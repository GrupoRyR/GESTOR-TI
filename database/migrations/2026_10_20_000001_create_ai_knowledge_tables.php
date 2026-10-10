<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestor IA documentation, product incidents and the rating lifecycle
 * (PROMPT 6.4.2, 6.4.4, 6.4.5; types/pages/ai-knowledge.ts).
 *
 * - `ai_documents`: Markdown docs of a project or a product (exactly one of
 *   `ai_project_id` / `ai_product_id`). `body` is the working copy the
 *   editor autosaves; `version` is the last published version, whose text
 *   lives in `ai_document_revisions` (restoring adds a version, never
 *   deletes one).
 * - `ai_product_incidents`: listed on the product page; no form yet.
 * - `ai_requests`: stamps of the rating lifecycle (asked, 3-day reminder,
 *   30-day hours question) so the daily command never repeats a notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_project_id')->nullable()->constrained('ai_projects')->cascadeOnDelete();
            $table->foreignId('ai_product_id')->nullable()->constrained('ai_products')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('slug', 180);
            $table->string('template', 30);
            $table->longText('body');
            // company (everybody of the owner's companies) | it | project (the team).
            $table->string('visibility', 10)->default('it');
            $table->boolean('not_applicable')->default(false);
            $table->string('repository_path', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['ai_project_id', 'template']);
            $table->index(['ai_product_id', 'template']);
        });

        // Search (App\Domain\Ai\KnowledgeSearch). SQLite has no FULLTEXT:
        // tests use the keyword fallback.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('ai_documents', function (Blueprint $table) {
                $table->fullText(['title', 'body'], 'ai_documents_title_body_fulltext');
            });
        }

        Schema::create('ai_document_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_document_id')->constrained('ai_documents')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->longText('body');
            $table->string('summary', 255);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['ai_document_id', 'version']);
        });

        Schema::create('ai_product_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_product_id')->constrained('ai_products')->cascadeOnDelete();
            $table->string('title', 200);
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('reported_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['ai_product_id', 'started_at']);
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->timestamp('rating_requested_at')->nullable();
            $table->timestamp('rating_reminded_at')->nullable();
            $table->timestamp('hours_saved_asked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropColumn(['rating_requested_at', 'rating_reminded_at', 'hours_saved_asked_at']);
        });

        Schema::dropIfExists('ai_product_incidents');
        Schema::dropIfExists('ai_document_revisions');
        Schema::dropIfExists('ai_documents');
    }
};
