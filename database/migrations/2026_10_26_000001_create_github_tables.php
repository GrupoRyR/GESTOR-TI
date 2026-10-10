<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage D · GitHub (types/github.ts):
 *
 * - `github_repositories`: repositories the GitHub App is installed on,
 *   refreshed from the installation (App\Services\GitHub\RepositorySync).
 *   A repository links to at most one product (`ai_products`) or project
 *   (`ai_projects`) through `linkable`, with the branch used for CI and
 *   deploys and whether docs are read and events received. A product or
 *   project links to at most one repository.
 * - `github_webhook_events`: every signed delivery, stored before it is
 *   processed on the database queue (App\Jobs\ProcessGitHubEvent).
 * - `users.github_login`: GitHub account of an IT member (issue assignee).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_repositories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('github_id')->unique();
            // "owner/name".
            $table->string('full_name')->unique();
            $table->string('name');
            $table->string('default_branch')->default('main');
            $table->boolean('private')->default(true);
            $table->string('html_url');
            $table->string('language', 60)->nullable();
            $table->timestamp('pushed_at')->nullable();
            // Known branch names (default branch first).
            $table->json('branches')->nullable();
            $table->unsignedBigInteger('installation_id')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->nullableMorphs('linkable');
            $table->string('branch')->nullable();
            $table->boolean('read_docs')->default(true);
            $table->boolean('receive_events')->default(true);
            $table->foreignId('linked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->unique(['linkable_type', 'linkable_id']);
        });

        Schema::create('github_webhook_events', function (Blueprint $table) {
            $table->id();
            // `X-GitHub-Delivery`: a redelivery is stored once.
            $table->string('delivery_id', 64)->unique();
            // `X-GitHub-Event`: issues | pull_request | release | workflow_run | push | …
            $table->string('event', 40);
            $table->string('action', 40)->nullable();
            // "owner/name".
            $table->string('repository')->nullable()->index();
            $table->json('payload');
            // pending | processed | failed | ignored
            $table->string('status', 12)->default('pending')->index();
            // Spanish, user-facing.
            $table->string('error')->nullable();
            // timeout | repo_not_linked | …
            $table->string('error_code', 40)->nullable();
            // ["GIA-139", …] found in title, body and branch.
            $table->json('request_keys')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('github_login', 39)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('github_login');
        });

        Schema::dropIfExists('github_webhook_events');
        Schema::dropIfExists('github_repositories');
    }
};
