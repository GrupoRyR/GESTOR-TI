<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestor IA requests (PROMPT 6.4.1). Enum-like columns are strings holding
 * the backed values of App\Enums\Ai\* (keys of the design types).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            // Differs from requester_id when TI files it on someone's behalf.
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();

            $table->string('type', 20);
            $table->string('title', 200);
            // Product the request is about (improvement / bug). The foreign
            // key to `ai_products` is added in B2.
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('product_version', 40)->nullable();

            // Guided form (wizard).
            $table->text('problem');
            $table->text('who_suffers')->nullable();
            $table->text('current_process')->nullable();
            $table->decimal('hours_per_week', 5, 1)->nullable();
            $table->unsignedSmallInteger('people_count')->nullable();
            $table->text('expected_result')->nullable();
            $table->text('success_criteria')->nullable();
            $table->json('systems')->nullable();
            $table->text('systems_notes')->nullable();
            $table->string('urgency', 10)->nullable();
            $table->date('deadline')->nullable();
            $table->text('deadline_reason')->nullable();

            // Lifecycle.
            $table->string('status', 30)->default('received');
            $table->string('area_approval_status', 10)->nullable();

            // Evaluation (TI).
            $table->string('size', 2)->nullable();
            $table->string('risk', 10)->nullable();
            // Money impact typed by TI (COP per month); null = computed from the area hour value.
            $table->unsignedBigInteger('impact_money_per_month')->nullable();
            $table->unsignedInteger('votes_count')->default(0);
            $table->unsignedTinyInteger('priority_score')->nullable();
            $table->unsignedTinyInteger('final_priority')->nullable();
            $table->boolean('priority_overridden')->default(false);
            $table->foreignId('evaluated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at')->nullable();

            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->string('rejection_reason', 20)->nullable();
            $table->text('rejection_message')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['requester_id', 'status']);
            $table->index(['status', 'first_responded_at', 'first_response_due_at'], 'ai_requests_first_response_index');
        });

        // Duplicate search (App\Domain\Ai\DuplicateFinder). SQLite has no
        // FULLTEXT: tests use the keyword fallback.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('ai_requests', function (Blueprint $table) {
                $table->fullText(['title', 'problem'], 'ai_requests_title_problem_fulltext');
            });
        }

        Schema::create('ai_request_area', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['ai_request_id', 'area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_request_area');
        Schema::dropIfExists('ai_requests');
    }
};
