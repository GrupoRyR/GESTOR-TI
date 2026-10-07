<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            AreaSeeder::class,
            RolesAndPermissionsSeeder::class,
            AccessSystemSeeder::class,
        ]);

        // Roles of existing users from GESTOR_* emails (see config/gestor.php).
        $this->call(InitialUsersSeeder::class);
    }
}
