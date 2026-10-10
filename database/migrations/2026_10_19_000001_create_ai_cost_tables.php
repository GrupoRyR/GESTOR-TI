<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time and costs of the projects (PROMPT 6.4.11, B6):
 *
 * - `ai_timers`: the running timer of a person (one per person, unique
 *   `user_id`). Stopping it writes an `ai_time_entries` row (origin timer).
 * - `ai_project_costs`: costs entered by hand (license, hosting, external
 *   provider, other; once or monthly; COP) with an optional receipt on the
 *   private disk. Team hours and AI usage are computed, never stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_timers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('ai_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamps();
        });

        Schema::create('ai_project_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('concept', 160);
            $table->string('recurrence', 10);
            $table->unsignedBigInteger('amount');
            $table->date('date');
            $table->string('attachment_disk', 32)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ai_project_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_project_costs');
        Schema::dropIfExists('ai_timers');
    }
};
