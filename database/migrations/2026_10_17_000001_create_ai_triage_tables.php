<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IT triage (B4, types/pages/ai-triage.ts):
 *
 * - `ai_requests`: assignee ("Asignar a…"), merged duplicate target and the
 *   manual backlog position (null = suggested order).
 * - `areas.hour_value`: COP an hour of the area is worth (money impact and
 *   payback; edited from Administración › Costos in B6).
 * - `ai_cost_rates`: internal cost per hour of a person, effective dated
 *   (B6 adds its administration page).
 * - `ai_estimates` + `ai_estimate_lines`: the estimate before approval
 *   (PROMPT 6.4.11): hours per person or role with the rate in force,
 *   external costs and due date. `ai_project_id` is filled by B5.
 * - `ai_request_events`: IT timeline entries that are not status changes
 *   (evaluation, sent to approval, notifications, merges, reminders).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->foreignId('assignee_id')->nullable()->after('created_by_id')->constrained('users')->nullOnDelete();
            $table->foreignId('merged_into_id')->nullable()->after('rejection_message')->constrained('ai_requests')->nullOnDelete();
            $table->unsignedInteger('backlog_position')->nullable()->after('final_priority');
        });

        Schema::table('areas', function (Blueprint $table) {
            $table->unsignedBigInteger('hour_value')->nullable()->after('approver_user_id');
        });

        Schema::create('ai_cost_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rate');
            $table->date('effective_from');
            $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'effective_from']);
        });

        Schema::create('ai_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->nullable()->constrained()->cascadeOnDelete();
            // Projects arrive in B5 (no foreign key yet).
            $table->unsignedBigInteger('ai_project_id')->nullable()->index();
            $table->decimal('hours_total', 8, 1);
            $table->unsignedBigInteger('external_costs')->default(0);
            // Null while some person has no rate in force.
            $table->unsignedBigInteger('total_cost')->nullable();
            $table->date('due_date');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_estimate_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 100)->nullable();
            $table->decimal('hours', 8, 1);
            $table->unsignedBigInteger('hourly_rate')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('family', 10);
            $table->string('title');
            $table->text('quote')->nullable();
            $table->string('detail')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['ai_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_request_events');
        Schema::dropIfExists('ai_estimate_lines');
        Schema::dropIfExists('ai_estimates');
        Schema::dropIfExists('ai_cost_rates');

        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('hour_value');
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropConstrainedForeignId('assignee_id');
            $table->dropColumn('backlog_position');
        });
    }
};
