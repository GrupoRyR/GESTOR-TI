<?php

namespace App\Domain\Ai;

use App\Enums\Ai\RequestStatus;
use App\Models\AiProduct;
use App\Models\AiRequest;
use App\Models\Area;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Ai\Presenters\TriagePresenter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data of the product form (C-ProductForm, `ProductFormOptions` and
 * `ProductFormData` of types/pages/ai-knowledge.ts).
 *
 * Linkable requests are the open requests without a product (any company:
 * a product may serve several) plus the ones already linked to the product.
 */
final class ProductForm
{
    /** "Dónde corre" choices; a value outside the list stays offered when editing. */
    public const HOSTS = ['GoDaddy', 'Vercel', 'Equipo local', 'Servidor de SAP'];

    public const DEFAULT_VERSION = 'v0.1.0';

    /** Requests that are not "open" any more. */
    private const CLOSED = [RequestStatus::InProduction, RequestStatus::Rejected, RequestStatus::Closed];

    /**
     * Requests the product may link.
     *
     * @return Builder<AiRequest>
     */
    public static function linkable(?AiProduct $product): Builder
    {
        return AiRequest::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where(function (Builder $query) use ($product): void {
                $query->where(fn (Builder $open) => $open
                    ->whereNull('product_id')
                    ->whereNotIn('status', array_map(fn (RequestStatus $status) => $status->value, self::CLOSED)));

                if ($product !== null) {
                    $query->orWhere('product_id', $product->id);
                }
            });
    }

    /**
     * `ProductFormOptions` without `errors` (shared by Inertia).
     *
     * @return array{areas: list<array<string, mixed>>, owners: list<array<string, mixed>>, hosts: list<string>, requests: list<array<string, mixed>>}
     */
    public static function options(?AiProduct $product, ?string $currentHost = null): array
    {
        $hosts = self::HOSTS;
        if ($currentHost !== null && $currentHost !== '' && ! in_array($currentHost, $hosts, true)) {
            $hosts[] = $currentHost;
        }

        return [
            'areas' => Area::query()->where('is_active', true)->orderBy('name')->orderBy('id')->get()
                ->map(fn (Area $area) => TriagePresenter::areaOption($area))->values()->all(),
            'owners' => User::query()->where('active', true)->with('employee')->orderBy('name')->orderBy('id')->get()
                ->map(fn (User $user) => RequestPresenter::person($user))->values()->all(),
            'hosts' => $hosts,
            'requests' => self::linkable($product)->orderByDesc('id')->get(['id', 'key', 'title', 'status'])
                ->map(fn (AiRequest $request) => ['key' => $request->key, 'title' => $request->title, 'status' => $request->status->value])
                ->values()
                ->all(),
        ];
    }

    /**
     * Blank `ProductFormData`: the company of the context, "En desarrollo", v0.1.0.
     *
     * @return array<string, mixed>
     */
    public static function blank(?int $companyId): array
    {
        return [
            'name' => '',
            'slug' => '',
            'company_ids' => $companyId === null ? [] : [$companyId],
            'area_id' => null,
            'owner_id' => null,
            'description' => '',
            'request_keys' => [],
            'version' => self::DEFAULT_VERSION,
            'state' => 'development',
            'kind' => 'web',
            'stack' => '',
            'host' => '',
            'url' => '',
        ];
    }

    /**
     * `ProductFormData` of a stored product (primary company and area first).
     *
     * @return array<string, mixed>
     */
    public static function values(AiProduct $product): array
    {
        $product->loadMissing(['companies', 'areas']);

        return [
            'name' => $product->name,
            'slug' => $product->slug,
            'company_ids' => $product->companies
                ->sortBy([fn ($a, $b) => (int) $b->pivot?->getAttribute('is_primary') <=> (int) $a->pivot?->getAttribute('is_primary'), fn ($a, $b) => $a->id <=> $b->id])
                ->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'area_id' => $product->primaryArea()?->id,
            'owner_id' => $product->owner_user_id,
            'description' => (string) $product->description,
            'request_keys' => $product->requests()->orderByDesc('id')->pluck('key')->values()->all(),
            'version' => (string) $product->current_version,
            'state' => $product->status->value,
            'kind' => $product->kind,
            'stack' => (string) $product->stack,
            'host' => (string) $product->hosting,
            'url' => (string) $product->url,
        ];
    }

    /**
     * The old input of a failed store/update over `$base`, typed like
     * `ProductFormData`.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $old
     * @return array<string, mixed>
     */
    public static function withOldInput(array $base, array $old): array
    {
        if ($old === []) {
            return $base;
        }

        $ids = fn ($value): array => array_values(array_map('intval', array_filter((array) $value, 'is_numeric')));
        $id = fn ($value): ?int => is_numeric($value) ? (int) $value : null;
        // Empty fields arrive as null (ConvertEmptyStringsToNull): they stay empty.
        $text = fn (string $key): string => array_key_exists($key, $old)
            ? (is_scalar($old[$key]) ? (string) $old[$key] : '')
            : (string) $base[$key];

        return [
            'name' => $text('name'),
            'slug' => $text('slug'),
            'company_ids' => array_key_exists('company_ids', $old) ? $ids($old['company_ids']) : $base['company_ids'],
            'area_id' => array_key_exists('area_id', $old) ? $id($old['area_id']) : $base['area_id'],
            'owner_id' => array_key_exists('owner_id', $old) ? $id($old['owner_id']) : $base['owner_id'],
            'description' => $text('description'),
            'request_keys' => array_key_exists('request_keys', $old)
                ? array_values(array_filter((array) $old['request_keys'], 'is_string'))
                : $base['request_keys'],
            'version' => $text('version'),
            'state' => $text('state'),
            'kind' => $text('kind'),
            'stack' => $text('stack'),
            'host' => $text('host'),
            'url' => $text('url'),
        ];
    }
}
