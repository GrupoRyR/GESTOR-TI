<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('avatar_url')->nullable()->after('email');
            // Microsoft identity (filled by the Microsoft login in A5).
            $table->string('azure_id')->nullable()->unique()->after('avatar_url');
            $table->string('tenant_id', 36)->nullable()->after('azure_id');
            // Global IT staff (e.g. Jhon, Leonardo).
            $table->boolean('is_global')->default(false)->after('tenant_id');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropUnique(['azure_id']);
            $table->dropColumn(['avatar_url', 'azure_id', 'tenant_id', 'is_global', 'last_login_at']);
        });
    }
};
