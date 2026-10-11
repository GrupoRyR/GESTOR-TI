<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requester side of the Gestor IA (B3):
 *
 * - `ai_request_followers`: "Seguir" (requester and +1 voters follow
 *   automatically; anyone who can view may follow or stop following).
 * - `ai_request_drafts`: the wizard autosave, one per person, no files.
 * - `ai_ratings`: "Calificar la entrega" (PROMPT 6.4.5), once per request
 *   and person; B7 adds the 3/7-day reminder lifecycle.
 * - `ai_hours_saved_reports`: "¿cuántas horas te ahorra por semana?" 30
 *   days after release, once per request and person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_request_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ai_request_id', 'user_id']);
        });

        Schema::create('ai_request_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->timestamp('saved_at');
            $table->timestamps();
        });

        Schema::create('ai_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // "Resolvió mi necesidad" and "Facilidad de uso", 1–5.
            $table->unsignedTinyInteger('solved_need');
            $table->unsignedTinyInteger('ease_of_use');
            $table->text('comment')->nullable();
            $table->timestamp('rated_at');
            $table->timestamps();

            $table->unique(['ai_request_id', 'user_id']);
        });

        Schema::create('ai_hours_saved_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('hours_per_week', 4, 1);
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->unique(['ai_request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_hours_saved_reports');
        Schema::dropIfExists('ai_ratings');
        Schema::dropIfExists('ai_request_drafts');
        Schema::dropIfExists('ai_request_followers');
    }
};
