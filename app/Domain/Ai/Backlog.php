<?php

namespace App\Domain\Ai;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\User;
use App\Support\Settings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Backlog priorizado" (BacklogIndexProps): requests "En backlog" in the
 * current company context.
 *
 * - Suggested order: the roadmap order (final priority, votes, oldest).
 * - Manual order ("Jhon movió GIA-102 arriba de GIA-121"): `reorder()`
 *   stores `backlog_position` 1..n for the given keys (only backlog
 *   requests in view count) and clears the others, so they follow in the
 *   suggested order. Who moved what is kept in Settings
 *   `ai.backlog.manual_order` (`ManualOrder` of the design); `reset()`
 *   ("Volver al orden sugerido") clears both.
 */
final class Backlog
{
    public const MANUAL_ORDER_KEY = 'ai.backlog.manual_order';

    public function __construct(
        private readonly Settings $settings,
        private readonly CurrentCompany $companies,
    ) {}

    /**
     * Ranked backlog (rank 1 first).
     *
     * @return Collection<int, AiRequest>
     */
    public function items(?int $areaId = null, ?int $companyId = null): Collection
    {
        $query = $this->query()
            ->when($areaId !== null, fn (Builder $query) => $query->where('area_id', $areaId))
            ->when($companyId !== null && $this->companies->isAll(), fn (Builder $query) => $query->where('company_id', $companyId))
            ->orderByRaw('backlog_position IS NULL')
            ->orderBy('backlog_position');

        return Roadmap::order($query)->get();
    }

    /**
     * Stored manual order, or null while the order is the suggested one.
     *
     * @return array{by_id: int, at: string, moved_key: string, above_key: ?string, note: ?string}|null
     */
    public function manualOrder(): ?array
    {
        $value = $this->settings->get(self::MANUAL_ORDER_KEY);

        return is_array($value) && isset($value['by_id'], $value['at'], $value['moved_key']) ? $value : null;
    }

    /**
     * @param  list<string>  $keys  request keys, rank 1 first
     */
    public function reorder(array $keys, User $actor, ?string $note = null): void
    {
        $before = $this->items()->pluck('key')->all();
        $requests = $this->query()->whereIn('key', $keys)->get()->keyBy('key');
        $order = array_values(array_filter(array_unique($keys), fn (string $key) => $requests->has($key)));

        if ($order === []) {
            return;
        }

        DB::transaction(function () use ($order, $requests): void {
            AiRequest::query()->withoutGlobalScopes()->whereNotNull('backlog_position')->update(['backlog_position' => null]);

            foreach ($order as $i => $key) {
                $requests[$key]->forceFill(['backlog_position' => $i + 1])->save();
            }
        });

        [$moved, $above] = $this->firstMove($before, $order);

        $this->settings->set(self::MANUAL_ORDER_KEY, [
            'by_id' => $actor->id,
            'at' => now()->toIso8601String(),
            'moved_key' => $moved,
            'above_key' => $above,
            'note' => $note === null || trim($note) === '' ? null : trim($note),
        ]);

        activity('ai')
            ->causedBy($actor)
            ->event('ai_backlog_reordered')
            ->withProperties(['order' => $order])
            ->log("Orden manual del backlog: {$moved} arriba".($above !== null ? " de {$above}" : ''));
    }

    public function reset(User $actor): void
    {
        AiRequest::query()->withoutGlobalScopes()->whereNotNull('backlog_position')->update(['backlog_position' => null]);
        $this->settings->forget(self::MANUAL_ORDER_KEY);

        activity('ai')->causedBy($actor)->event('ai_backlog_reset')->log('Backlog en el orden sugerido');
    }

    /**
     * @return Builder<AiRequest>
     */
    private function query(): Builder
    {
        return AiRequest::query()->where('status', RequestStatus::Backlog->value);
    }

    /**
     * The first request that changed place and the one it now sits above.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return array{0: string, 1: ?string}
     */
    private function firstMove(array $before, array $after): array
    {
        $before = array_values(array_filter($before, fn (string $key) => in_array($key, $after, true)));

        foreach ($after as $i => $key) {
            if (($before[$i] ?? null) !== $key) {
                return [$key, $after[$i + 1] ?? null];
            }
        }

        return [$after[0], $after[1] ?? null];
    }
}
