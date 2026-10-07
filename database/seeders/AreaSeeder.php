<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Default areas for EVERY company in the table (idempotent, keyed by
 * company + slug). Existing names and states are not overwritten.
 */
class AreaSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    public const DEFAULT_AREAS = [
        'Ventas', 'Contabilidad', 'Tesorería', 'Cartera', 'Producción',
        'Compras', 'Gerencia', 'TI', 'Diseño',
    ];

    public function run(): void
    {
        foreach (Company::all() as $company) {
            foreach (self::DEFAULT_AREAS as $name) {
                Area::withoutGlobalScopes()->firstOrCreate(
                    ['company_id' => $company->id, 'slug' => Str::slug($name)],
                    ['name' => $name, 'is_active' => true],
                );
            }
        }
    }
}
