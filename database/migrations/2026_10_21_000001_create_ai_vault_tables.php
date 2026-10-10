<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials vault of the projects and products (PROMPT 6.4.10,
 * types/pages/ai-value-vault.ts).
 *
 * - `ai_vault_items`: metadata plus `secret_ciphertext`, the value encrypted
 *   with the vault's own key (`VAULT_KEY`, App\Support\Vault\VaultEncrypter),
 *   never with APP_KEY, so a database or backup copy is not enough to read it.
 *   An item belongs to a project or a product (or neither, e.g. Gestor TI).
 * - `ai_vault_access_log`: every view, copy, create, update, delete, denied
 *   attempt, unlock and lock (append-only, no `updated_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_vault_items', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->foreignId('ai_project_id')->nullable()->constrained('ai_projects')->nullOnDelete();
            $table->foreignId('ai_product_id')->nullable()->constrained('ai_products')->nullOnDelete();
            // production | testing
            $table->string('environment', 12)->default('production');
            // api_key | user_password | connection_string | token
            $table->string('type', 20);
            $table->string('username', 190)->nullable();
            $table->text('secret_ciphertext');
            $table->string('url', 255)->nullable();
            $table->date('expires_at')->nullable();
            // "Falta recibirla" and similar states when there is no date.
            $table->string('expiry_note', 120)->nullable();
            $table->unsignedSmallInteger('rotate_every_days')->nullable();
            $table->timestamp('last_rotated_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('expires_at');
        });

        Schema::create('ai_vault_access_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_vault_item_id')->nullable()->constrained('ai_vault_items')->nullOnDelete();
            // Snapshot: the log keeps the name after the item is deleted.
            $table->string('item_name', 160)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // view | copy | create | update | delete | denied | unlock | lock
            $table->string('action', 10);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            // web | auto (auto-lock) | console
            $table->string('channel', 10)->default('web');
            $table->timestamp('created_at')->nullable();

            $table->index(['ai_vault_item_id', 'id']);
            $table->index(['user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_vault_access_log');
        Schema::dropIfExists('ai_vault_items');
    }
};
