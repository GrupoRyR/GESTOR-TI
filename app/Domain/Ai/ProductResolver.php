<?php

namespace App\Domain\Ai;

use App\Models\AiProduct;
use App\Models\User;

/**
 * Resolves the product of the `?producto={slug}` entry point (the "Pedir
 * mejora" button of each app, same slug as the Portal card) and the
 * version the requester was on.
 */
class ProductResolver
{
    /**
     * Active (not soft-deleted) product by slug; case and surrounding
     * spaces are ignored.
     */
    public function fromSlug(?string $slug): ?AiProduct
    {
        $slug = mb_strtolower(trim((string) $slug));

        if ($slug === '') {
            return null;
        }

        return AiProduct::query()->where('slug', $slug)->first();
    }

    /**
     * Product by slug only when it serves the user's own company.
     */
    public function forUser(?string $slug, User $user): ?AiProduct
    {
        $product = $this->fromSlug($slug);

        return $product !== null && $this->servesCompanyOf($product, $user) ? $product : null;
    }

    /**
     * Product by id only when it serves the user's own company.
     */
    public function byIdForUser(int $id, User $user): ?AiProduct
    {
        $product = AiProduct::query()->find($id);

        return $product !== null && $this->servesCompanyOf($product, $user) ? $product : null;
    }

    /**
     * The version given by the requester, else the product's current one.
     */
    public function version(AiProduct $product, ?string $version): ?string
    {
        $version = trim((string) $version);

        return $version === '' ? $product->current_version : $version;
    }

    private function servesCompanyOf(AiProduct $product, User $user): bool
    {
        return $user->company_id !== null && $product->companies()->whereKey($user->company_id)->exists();
    }
}
