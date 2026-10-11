<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aprobador de área (visto bueno before a request reaches TI). Only the
     * person is stored here; roles are managed in Administración › Usuarios.
     */
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->foreignId('approver_user_id')->nullable()->after('slug')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approver_user_id');
        });
    }
};
