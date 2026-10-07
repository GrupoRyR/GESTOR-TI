<?php

namespace Database\Seeders;

use App\Models\AccessSystem;
use Illuminate\Database\Seeder;

/**
 * Default access systems. Idempotent: never overwrites edits.
 */
class AccessSystemSeeder extends Seeder
{
    public function run(): void
    {
        $systems = [
            'Office 365' => 'Cuenta de Microsoft 365 (correo, Teams, OneDrive).',
            'SAP Business One' => 'Usuario de SAP Business One.',
            'VPN' => 'Acceso remoto por VPN.',
        ];

        foreach ($systems as $name => $description) {
            AccessSystem::firstOrCreate(['name' => $name], ['description' => $description, 'is_active' => true]);
        }
    }
}
