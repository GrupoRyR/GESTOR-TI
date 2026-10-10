<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asistente TI knowledge base (PROMPT 6.8.1, types/assistant.ts).
 *
 * - `knowledge_items`: cards keyed `K-031` (App\Domain\Ai\Sequence
 *   `knowledge_items`). `type` is system | report | data_flow | procedure |
 *   glossary; `fields` holds the type fields (App\Domain\Assistant\KnowledgeTypes)
 *   and `search_text` their flattened text for the FULLTEXT index.
 *   `visibility` is it ("Solo TI") | company ("Toda la empresa").
 *   `review_at` is the next review (6 months after the last one).
 * - `knowledge_item_company`: a card serves one or several companies.
 * - `knowledge_links`: "Relacionadas", a card to another card or to a
 *   Gestor IA product (exactly one of `linked_item_id` / `ai_product_id`).
 *
 * Glossary synonyms are not a table: they come from the glossary cards
 * (`term` + `also_called`), App\Domain\Assistant\Synonyms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_items', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->unique();
            $table->string('type', 20);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->json('fields');
            $table->text('search_text')->nullable();
            $table->json('tags')->nullable();
            $table->string('visibility', 10)->default('company');
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('review_at');
            $table->timestamp('last_reviewed_at')->nullable();
            $table->foreignId('last_reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_current')->default(true);
            // manual | import | learn (/aprender draft).
            $table->string('source', 10)->default('manual');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'visibility']);
            $table->index('review_at');
            $table->index('updated_at');
        });

        // Search (App\Domain\Assistant\KnowledgeSearch). SQLite has no
        // FULLTEXT: tests use the keyword fallback.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('knowledge_items', function (Blueprint $table) {
                $table->fullText(['title', 'description', 'search_text'], 'knowledge_items_search_fulltext');
            });
        }

        Schema::create('knowledge_item_company', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_item_id')->constrained('knowledge_items')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->unique(['knowledge_item_id', 'company_id']);
            $table->index('company_id');
        });

        Schema::create('knowledge_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_item_id')->constrained('knowledge_items')->cascadeOnDelete();
            $table->foreignId('linked_item_id')->nullable()->constrained('knowledge_items')->cascadeOnDelete();
            $table->foreignId('ai_product_id')->nullable()->constrained('ai_products')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->index(['knowledge_item_id', 'position']);
            $table->index('linked_item_id');
            $table->index('ai_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_links');
        Schema::dropIfExists('knowledge_item_company');
        Schema::dropIfExists('knowledge_items');
    }
};
