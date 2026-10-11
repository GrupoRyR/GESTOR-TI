<?php

namespace App\Domain\Ai;

use App\Models\AiProduct;
use App\Models\User;
use App\Support\Ai\Presenters\ProductPresenter;
use App\Support\Ai\Presenters\RequestPresenter;

/**
 * `IncidentFormSetup` of the "Registrar incidente" drawer on
 * `Ai/Products/Show` (types/pages/ai-knowledge.ts). Defaults: now in
 * Bogotá, every IT person (`ai.manage`) checked, the owner checked when the
 * owner is a user. After a failed store the old input (an
 * `IncidentStorePayload`) is turned back into `IncidentFormData`.
 */
final class IncidentForm
{
    private const TIMEZONE = 'America/Bogota';

    public function __construct(private readonly TriagePeople $people) {}

    /**
     * @param  array<string, mixed>  $old  flashed input of a failed store
     * @return array<string, mixed>
     */
    public function setup(AiProduct $product, User $reporter, bool $open, array $old = []): array
    {
        // Only input of this drawer counts (it always carries `started_at`).
        if (! array_key_exists('started_at', $old)) {
            $old = [];
        }

        $team = $this->people->team();
        $now = now()->timezone(self::TIMEZONE);
        $hasOwnerUser = $product->owner_user_id !== null;

        $defaults = [
            'title' => '',
            'started_on' => $now->toDateString(),
            'started_time' => $now->format('H:i'),
            'impact' => null,
            'description' => '',
            'status' => 'open',
            'ended_on' => '',
            'ended_time' => '',
            'resolution' => '',
            'notify_ids' => $team->modelKeys(),
            'notify_owner' => $hasOwnerUser,
        ];

        return [
            'open' => $open || $old !== [],
            'defaults' => $old === [] ? $defaults : self::fromOldInput($defaults, $old),
            'notify_people' => $team->map(fn (User $user) => RequestPresenter::person($user))->values()->all(),
            'owner_label' => $product->ownerLabel() ?? ProductPresenter::OWNER_PLACEHOLDER,
            'reporter' => RequestPresenter::person($reporter),
        ];
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $old
     * @return array<string, mixed>
     */
    private static function fromOldInput(array $defaults, array $old): array
    {
        $text = fn (string $key): string => is_scalar($old[$key] ?? null) ? (string) $old[$key] : '';
        [$startedOn, $startedTime] = self::split($old['started_at'] ?? null);
        [$endedOn, $endedTime] = self::split($old['ended_at'] ?? null);
        $impact = is_string($old['impact'] ?? null) ? $old['impact'] : null;

        return [
            'title' => $text('title'),
            'started_on' => $startedOn,
            'started_time' => $startedTime,
            'impact' => $impact,
            'description' => $text('description'),
            'status' => ($old['status'] ?? null) === 'resolved' ? 'resolved' : 'open',
            'ended_on' => $endedOn,
            'ended_time' => $endedTime,
            'resolution' => $text('resolution'),
            'notify_ids' => array_values(array_map('intval', array_filter((array) ($old['notify_ids'] ?? []), 'is_numeric'))),
            'notify_owner' => array_key_exists('notify_owner', $old) ? filter_var($old['notify_owner'], FILTER_VALIDATE_BOOLEAN) : $defaults['notify_owner'],
        ];
    }

    /**
     * "2026-10-07T08:10" → ['2026-10-07', '08:10'].
     *
     * @return array{0: string, 1: string}
     */
    private static function split(mixed $value): array
    {
        if (! is_string($value) || ! str_contains($value, 'T')) {
            return ['', ''];
        }

        [$date, $time] = explode('T', $value, 2);

        return [$date, mb_substr($time, 0, 5)];
    }
}
