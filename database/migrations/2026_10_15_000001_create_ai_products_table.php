<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestor IA products: the own apps already in use (PROMPT 6.4.2). A product
 * may serve several companies (`ai_product_company`) and areas
 * (`ai_product_area`), so it carries no `company_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_products', function (Blueprint $table) {
            $table->id();
            // Same slug as the Portal card (`?producto={slug}` entry point).
            // Unique even for soft-deleted rows: a slug is never reused.
            $table->string('slug', 64)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            // Product owner; `owner_name` holds a person or area that has no user yet.
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_name', 150)->nullable();
            $table->string('url')->nullable();
            // "owner/repo"; the link to github_repositories arrives in Stage D.
            $table->string('repository', 200)->nullable();
            $table->string('stack', 80)->nullable();
            $table->string('hosting', 80)->nullable();
            $table->string('ai_provider', 40)->nullable();
            $table->string('ai_model', 80)->nullable();
            // Hosting plus AI usage per month; only shown with `ai.costs.view`.
            $table->decimal('monthly_cost', 14, 2)->nullable();
            $table->string('monthly_cost_currency', 3)->default('COP');
            $table->string('status', 16)->default('development');
            $table->string('current_version', 40)->nullable();
            $table->string('notion_url')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('ai_product_company', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['ai_product_id', 'company_id']);
        });

        Schema::create('ai_product_area', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['ai_product_id', 'area_id']);
        });

        Schema::create('ai_product_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_product_id')->constrained()->cascadeOnDelete();
            $table->string('version', 40);
            $table->date('released_at')->nullable();
            // One line for lists (design `ProductVersion.summary`); `notes` is markdown.
            $table->string('summary', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('release_url')->nullable();
            $table->timestamps();

            $table->unique(['ai_product_id', 'version']);
            $table->index(['ai_product_id', 'released_at']);
        });

        Schema::create('ai_product_version_request', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_product_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ai_product_version_id', 'ai_request_id'], 'ai_product_version_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_product_version_request');
        Schema::dropIfExists('ai_product_versions');
        Schema::dropIfExists('ai_product_area');
        Schema::dropIfExists('ai_product_company');
        Schema::dropIfExists('ai_products');
    }
};
