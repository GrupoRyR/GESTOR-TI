<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asistente TI chat (PROMPT 6.8.2, types/pages/assistant-chat.ts).
 *
 * - `assistant_conversations`: one thread of one person, channel app |
 *   telegram (C4 keeps the chat id), title (first question), last activity
 *   (the 30-minute context window is counted on the messages).
 * - `assistant_messages`: role user | assistant | system, `kind` of
 *   types/assistant.ts (text, answer, no_answer, person_choice,
 *   credential_notice, vault_link, refused, error), the visible text and a
 *   `payload` (sources, options, notice references). NEVER a secret value:
 *   a credential notice keeps the access id and the user name only.
 *   `ai_interaction_id`: the last provider call behind an answer.
 * - `assistant_citations`: each card an answer cited (usage counters of the
 *   knowledge card: "Usada N veces este mes", "Última cita").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 12)->default('app');
            $table->string('telegram_chat_id', 32)->nullable();
            $table->string('title', 160);
            $table->timestamp('last_activity_at');
            $table->timestamps();

            $table->index(['user_id', 'channel', 'last_activity_at']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('role', 12);
            $table->string('kind', 24);
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 12)->default('done');
            $table->foreignId('ai_interaction_id')->nullable()->constrained('ai_interactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });

        Schema::create('assistant_citations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_item_id')->constrained('knowledge_items')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('assistant_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cited_at');

            $table->index(['knowledge_item_id', 'cited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_citations');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
