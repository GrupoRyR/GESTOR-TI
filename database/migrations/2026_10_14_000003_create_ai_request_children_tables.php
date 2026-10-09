<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data hanging from a request: +1 votes, conversation and internal notes,
 * files, acceptance criteria, approvals and the status history. They carry
 * no `company_id`: they are reached only through their (scoped) request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_request_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['ai_request_id', 'user_id']);
        });

        Schema::create('ai_request_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->string('visibility', 10)->default('public');
            $table->timestamps();

            $table->index(['ai_request_id', 'visibility']);
        });

        Schema::create('ai_request_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_request_comment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('acceptance_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->text('text');
            // TI note shown beside it ("Depende de T4 y T7").
            $table->string('note', 255)->nullable();
            $table->string('result', 10)->nullable();
            $table->text('result_comment')->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained('ai_request_attachments')->nullOnDelete();
            $table->foreignId('validated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->index(['ai_request_id', 'position']);
        });

        Schema::create('ai_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            // The approver this row asks (one row per selected approver).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('decision', 10)->default('pending');
            $table->text('comment')->nullable();
            $table->string('channel', 10)->nullable();
            // Project approvals: estimate shown to the approver (hours by
            // person, external costs, due date, total cost).
            $table->json('estimate')->nullable();
            // Wait for every selected approver ("pedir la del otro").
            $table->boolean('require_all')->default(false);
            $table->boolean('is_reapproval')->default(false);
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['ai_request_id', 'kind', 'decision']);
            $table->index(['user_id', 'decision']);
        });

        Schema::create('ai_request_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['ai_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_request_status_changes');
        Schema::dropIfExists('ai_request_approvals');
        Schema::dropIfExists('acceptance_criteria');
        Schema::dropIfExists('ai_request_attachments');
        Schema::dropIfExists('ai_request_comments');
        Schema::dropIfExists('ai_request_votes');
    }
};
