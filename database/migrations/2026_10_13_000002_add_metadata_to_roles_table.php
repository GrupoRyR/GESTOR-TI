<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Role metadata for Administración › Roles y permisos and › Usuarios.
     * Stored on the spatie `roles` table (one row per role, no join):
     * built-in roles get theirs from config/permissions.php (`role_metadata`,
     * applied by RolesAndPermissionsSeeder, `is_system = true`); custom roles
     * from `admin.roles.store`. `scope`: global | company | company_area.
     */
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->string('label')->nullable()->after('name');
            $table->string('scope', 20)->default('company')->after('label');
            $table->string('description')->nullable()->after('scope');
            $table->boolean('requires_area')->default(false)->after('description');
            $table->boolean('is_system')->default(false)->after('requires_area');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn(['label', 'scope', 'description', 'requires_area', 'is_system']);
        });
    }

    private function table(): string
    {
        return (string) config('permission.table_names.roles', 'roles');
    }
};
