<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage D6 · AI suggestions (types/suggestions.ts).
 *
 * `ai_suggestions`: one proposal of the AI about a request (summary,
 * duplicate, criteria, size) or a GitHub release (release_notes). Never
 * applied by itself: `accepted_value` keeps what a person used.
 * - `subject` morph (class names, no morph map, as `github_repositories`).
 * - `state`: generating | ready | accepted | discarded | error | cancelled
 *   (`stale` is computed from `input_hashes`; `cancelled` is never sent).
 * - `batch`: rows filled by the same provider call.
 * - `input_hashes`: sha1 per whitelisted input field, to tell what changed.
 * - `superseded_at`: replaced by a newer suggestion of the same kind.
 *
 * `ai_requests.summary_text`: "Resumen para TI" once a summary was used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('kind', 20);
            $table->string('state', 20)->default('generating');
            $table->uuid('batch')->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->json('payload')->nullable();
            $table->json('accepted_value')->nullable();
            $table->foreignId('accepted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('discarded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discarded_at')->nullable();
            $table->string('discard_reason', 500)->nullable();
            $table->json('input_hashes')->nullable();
            $table->json('error')->nullable();
            $table->foreignId('ai_interaction_id')->nullable()->constrained('ai_interactions')->nullOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'kind']);
            $table->index('batch');
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->text('summary_text')->nullable()->after('expected_result');
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropColumn('summary_text');
        });

        Schema::dropIfExists('ai_suggestions');
    }
};
