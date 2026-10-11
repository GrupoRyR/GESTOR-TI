<?php

namespace App\Actions\Ai;

use App\Domain\Ai\ProductForm;
use App\Enums\Ai\ProductStatus;
use App\Models\AiProduct;
use App\Models\AiRequest;
use App\Models\Area;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create and change a Gestor IA product from the product form
 * (C-ProductForm, PROMPT 6.4.2). There is no delete: a product leaves
 * service with the state "Retirado".
 *
 * - Companies: the chosen ones; the primary is the company of the area.
 * - Area: the chosen one becomes the primary area and replaces the previous
 *   primary; other (secondary) areas of the chosen companies are kept.
 * - Requests: the chosen keys point to the product; requests unlinked in
 *   the form lose it.
 * - Version: a new current version gets its `ai_product_versions` row.
 *
 * Attribute changes are logged by the model (LogsActivity, log `ai`); the
 * relations are logged as one `product_relations` entry.
 */
final class SaveProduct
{
    /**
     * @param  array<string, mixed>  $data  validated `ProductFormData`
     * @param  list<int>  $companyIds
     * @param  list<string>  $requestKeys
     */
    public function handle(User $actor, ?AiProduct $product, array $data, array $companyIds, array $requestKeys): AiProduct
    {
        return DB::transaction(function () use ($actor, $product, $data, $companyIds, $requestKeys): AiProduct {
            $creating = $product === null;
            $product ??= new AiProduct;

            $product->fill([
                'name' => trim((string) $data['name']),
                'slug' => (string) $data['slug'],
                'description' => self::nullable($data['description'] ?? null),
                'owner_user_id' => isset($data['owner_id']) ? (int) $data['owner_id'] : null,
                'status' => ProductStatus::from((string) $data['state']),
                'kind' => (string) $data['kind'],
                'current_version' => (string) $data['version'],
                'stack' => self::nullable($data['stack'] ?? null),
                'hosting' => self::nullable($data['host'] ?? null),
                'url' => self::nullable($data['url'] ?? null),
            ]);
            $product->save();

            /** @var Area $area */
            $area = Area::query()->withoutGlobalScopes()->findOrFail((int) $data['area_id']);

            $this->syncCompanies($product, $companyIds, (int) $area->company_id);
            $this->syncAreas($product, $area, $companyIds);
            $linked = $this->syncRequests($product, $requestKeys);
            $this->ensureVersion($product);

            activity('ai')
                ->performedOn($product)
                ->causedBy($actor)
                ->event('product_relations')
                ->withProperties([
                    'company_ids' => $companyIds,
                    'area_id' => $area->id,
                    'request_keys' => $linked,
                ])
                ->log($creating ? "Producto creado: {$product->name}" : "Producto actualizado: {$product->name}");

            return $product;
        });
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function syncCompanies(AiProduct $product, array $companyIds, int $primaryId): void
    {
        $product->companies()->sync(collect($companyIds)
            ->mapWithKeys(fn (int $id) => [$id => ['is_primary' => $id === $primaryId]])
            ->all());
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function syncAreas(AiProduct $product, Area $primary, array $companyIds): void
    {
        $previousPrimary = $product->areas()->wherePivot('is_primary', true)->pluck('areas.id')->all();

        $keep = $product->areas()->get()
            ->filter(fn (Area $area) => in_array((int) $area->company_id, $companyIds, true))
            ->reject(fn (Area $area) => in_array($area->id, $previousPrimary, true))
            ->mapWithKeys(fn (Area $area) => [$area->id => ['is_primary' => false]])
            ->all();

        $keep[$primary->id] = ['is_primary' => true];

        $product->areas()->sync($keep);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string> the linked keys
     */
    private function syncRequests(AiProduct $product, array $keys): array
    {
        $product->requests()
            ->when($keys !== [], fn ($query) => $query->whereNotIn('key', $keys))
            ->get()
            ->each(fn (AiRequest $request) => $request->forceFill(['product_id' => null])->save());

        if ($keys === []) {
            return [];
        }

        $requests = ProductForm::linkable($product)->whereIn('key', $keys)->get();

        $requests->each(function (AiRequest $request) use ($product): void {
            if ((int) $request->product_id !== $product->id) {
                $request->forceFill(['product_id' => $product->id])->save();
            }
        });

        return $requests->pluck('key')->sort()->values()->all();
    }

    private function ensureVersion(AiProduct $product): void
    {
        $version = (string) $product->current_version;

        if ($version === '' || $product->versions()->where('version', $version)->exists()) {
            return;
        }

        $product->versions()->create([
            'version' => $version,
            'released_at' => now('America/Bogota')->toDateString(),
            'summary' => 'Versión registrada en la ficha del producto.',
            'source' => 'manual',
        ]);
    }

    private static function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
