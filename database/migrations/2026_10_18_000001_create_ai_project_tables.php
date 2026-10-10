<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects (B5, PROMPT 6.4.3, types/pages/ai-projects.ts):
 *
 * - `ai_projects` (key P-n, company data) with benefited areas, team,
 *   the requests it came from (`ai_project_request`, one project per
 *   request) and its board columns (`ai_project_columns`, the five design
 *   keys with configurable names, `is_done`, optional WIP limit).
 * - `ai_tasks` (key T-n per project) with assignees, subtasks, comments
 *   and files; GitHub issue/PR columns stay empty until Stage D.
 * - `ai_requirements`: what IT needs from a person, optionally blocking a
 *   task (a task is blocked while an open requirement points at it).
 * - `ai_project_activities`: the "Actividad" feed of the summary.
 * - `ai_project_reports`: recipients, sections and schedule of the report.
 * - `ai_time_entries`: hours per person (origin timer|manual|telegram);
 *   B5 only reads them (spent hours), B6 builds the timer and the forms.
 * - `ai_estimates.ai_project_id` gets its foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_projects', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->text('objective')->nullable();
            $table->string('status', 20)->default('planned')->index();
            $table->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('ai_products')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('repository_branch', 100)->nullable();
            $table->timestamp('reapproval_requested_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_project_area', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['ai_project_id', 'area_id']);
        });

        Schema::create('ai_project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 60);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['ai_project_id', 'user_id']);
        });

        Schema::create('ai_project_request', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_project_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_project_id')->constrained()->cascadeOnDelete();
            $table->string('key', 20);
            $table->string('name', 60);
            $table->unsignedSmallInteger('position');
            $table->boolean('is_done')->default(false);
            $table->unsignedSmallInteger('wip_limit')->nullable();
            $table->timestamps();

            $table->unique(['ai_project_id', 'key']);
        });

        Schema::create('ai_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_project_column_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('key', 20);
            $table->unsignedInteger('position');
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('labels')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('estimate_hours', 6, 1)->nullable();
            // Stage D (GitHub): issue and pull request of the task.
            $table->unsignedInteger('github_issue_number')->nullable();
            $table->string('github_issue_url')->nullable();
            $table->string('github_issue_state', 10)->nullable();
            $table->unsignedInteger('github_pr_number')->nullable();
            $table->string('github_pr_url')->nullable();
            $table->string('github_pr_state', 10)->nullable();
            $table->string('github_ci', 10)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ai_project_id', 'key']);
            $table->index(['ai_project_column_id', 'position']);
        });

        Schema::create('ai_task_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ai_task_id', 'user_id']);
        });

        Schema::create('ai_subtasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_task_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->boolean('done')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('ai_task_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 150);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('ai_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_project_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('detail')->nullable();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_label', 160);
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('area_name', 120);
            $table->date('due_date');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('reminders_sent')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->foreignId('blocks_task_id')->nullable()->constrained('ai_tasks')->nullOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->foreignId('answered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('answer_disk', 40)->nullable();
            $table->string('answer_path')->nullable();
            $table->string('answer_name')->nullable();
            $table->unsignedBigInteger('answer_size')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_project_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_project_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('text');
            $table->timestamp('created_at')->nullable();

            $table->index(['ai_project_id', 'id']);
        });

        Schema::create('ai_project_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('periodicity', 10)->default('manual');
            $table->unsignedTinyInteger('weekday')->default(1);
            $table->string('time', 5)->default('07:00');
            $table->json('sections');
            $table->string('version', 10)->default('internal');
            $table->json('recipients');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_send_at')->nullable()->index();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_task_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->decimal('hours', 5, 2);
            $table->string('origin', 10);
            $table->string('note')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['ai_project_id', 'date']);
            $table->index(['user_id', 'date']);
        });

        Schema::table('ai_estimates', function (Blueprint $table) {
            $table->foreign('ai_project_id')->references('id')->on('ai_projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_estimates', function (Blueprint $table) {
            $table->dropForeign(['ai_project_id']);
        });

        Schema::dropIfExists('ai_time_entries');
        Schema::dropIfExists('ai_project_reports');
        Schema::dropIfExists('ai_project_activities');
        Schema::dropIfExists('ai_requirements');
        Schema::dropIfExists('ai_task_attachments');
        Schema::dropIfExists('ai_task_comments');
        Schema::dropIfExists('ai_subtasks');
        Schema::dropIfExists('ai_task_assignees');
        Schema::dropIfExists('ai_tasks');
        Schema::dropIfExists('ai_project_columns');
        Schema::dropIfExists('ai_project_request');
        Schema::dropIfExists('ai_project_members');
        Schema::dropIfExists('ai_project_area');
        Schema::dropIfExists('ai_projects');
    }
};
