<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Curation of the Asistente TI knowledge base (PROMPT 6.8.1,
 * types/pages/assistant-curation.ts).
 *
 * - `knowledge_imports`: one Excel/CSV import per person. The original
 *   file lives on the private disk (`file_disk` + `file_path`), never in
 *   `public`. `status`: uploaded | mapped | previewed | applied | failed.
 *   `sheets` keeps what was read on upload (name, first two rows, count of
 *   non-empty rows); `sheet` + `header_row` the choice; `type` the card
 *   type every row of that sheet enters as; `mapping` the columns and
 *   their fields; `stats` the last summary; `error_path` the errors file.
 * - `knowledge_drafts`: `/aprender` drafts (the only write the assistant
 *   makes). Raw `message`, `source` telegram | app, `proposed` card values
 *   (type, title, description, company_ids, area_id, owner_id, visibility,
 *   review_at, fields, origins), `missing` field keys; `status` pending |
 *   approved | merged | discarded; the card it became; Telegram chat and
 *   message ids for the bot (C4).
 * - `assistant_unanswered`: questions the assistant could not answer,
 *   grouped by `hash` (normalized text) while open; `status` open |
 *   documented | dismissed. `assistant_unanswered_asks`: each ask (who,
 *   channel app | telegram, conversation and message ids of C3/C4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_disk', 20)->default('local');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('status', 12)->default('uploaded');
            $table->json('sheets');
            $table->string('sheet', 100)->nullable();
            $table->boolean('header_row')->default(true);
            $table->string('type', 20)->nullable();
            $table->json('mapping')->nullable();
            $table->json('stats')->nullable();
            $table->string('error_path')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('knowledge_drafts', function (Blueprint $table) {
            $table->id();
            $table->text('message');
            $table->string('source', 10)->default('app');
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('proposed');
            $table->json('missing');
            $table->string('status', 10)->default('pending');
            $table->text('discard_reason')->nullable();
            $table->foreignId('knowledge_item_id')->nullable()->constrained('knowledge_items')->nullOnDelete();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('telegram_chat_id', 40)->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('assistant_unanswered', function (Blueprint $table) {
            $table->id();
            $table->text('question');
            $table->string('hash', 64);
            $table->unsignedInteger('times_asked')->default(0);
            $table->timestamp('first_asked_at');
            $table->timestamp('last_asked_at');
            $table->string('status', 12)->default('open');
            $table->foreignId('knowledge_item_id')->nullable()->constrained('knowledge_items')->nullOnDelete();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['hash', 'status']);
            $table->index(['status', 'last_asked_at']);
        });

        Schema::create('assistant_unanswered_asks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_unanswered_id')->constrained('assistant_unanswered')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 10)->default('app');
            // Chat conversation / message (C3) or Telegram ids (C4); no
            // foreign keys: those tables do not exist yet.
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamp('asked_at');

            $table->index('assistant_unanswered_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_unanswered_asks');
        Schema::dropIfExists('assistant_unanswered');
        Schema::dropIfExists('knowledge_drafts');
        Schema::dropIfExists('knowledge_imports');
    }
};
