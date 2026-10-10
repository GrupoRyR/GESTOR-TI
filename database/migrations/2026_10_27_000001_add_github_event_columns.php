<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage D2 · what the processed GitHub webhooks keep (types/github.ts):
 *
 * - `github_repositories.ci`: latest CI run per branch (`CiRun` keyed by
 *   branch name); `last_deploy`: last push to the linked branch (`Deploy`
 *   without the provider, which comes from the product hosting);
 *   `recent_commits`: newest commits of that branch (`CommitSummary`).
 * - `ai_product_versions`: the GitHub release behind a version (`Release`):
 *   tag, release id (a redelivery updates the same version), exact
 *   publication instant, release body (`technical_notes`), the user notes
 *   IT confirms and when requesters were notified (Stage D4), author login
 *   and repository.
 * - `ai_product_version_request.found_in_pr`: merged PR where the key was
 *   found (`ReleaseRequest.found_in_pr`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_repositories', function (Blueprint $table) {
            $table->json('ci')->nullable()->after('last_synced_at');
            $table->json('last_deploy')->nullable()->after('ci');
            $table->json('recent_commits')->nullable()->after('last_deploy');
        });

        Schema::table('ai_product_versions', function (Blueprint $table) {
            $table->string('tag', 100)->nullable()->after('version');
            $table->unsignedBigInteger('github_release_id')->nullable()->unique()->after('release_url');
            $table->foreignId('github_repository_id')->nullable()->after('github_release_id')->constrained('github_repositories')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->after('github_repository_id');
            $table->string('author_login', 39)->nullable()->after('published_at');
            $table->longText('technical_notes')->nullable()->after('author_login');
            $table->text('user_notes')->nullable()->after('technical_notes');
            $table->timestamp('notified_at')->nullable()->after('user_notes');
        });

        Schema::table('ai_product_version_request', function (Blueprint $table) {
            $table->unsignedInteger('found_in_pr')->nullable()->after('ai_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_product_version_request', function (Blueprint $table) {
            $table->dropColumn('found_in_pr');
        });

        Schema::table('ai_product_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('github_repository_id');
            $table->dropUnique(['github_release_id']);
            $table->dropColumn(['tag', 'github_release_id', 'published_at', 'author_login', 'technical_notes', 'user_notes', 'notified_at']);
        });

        Schema::table('github_repositories', function (Blueprint $table) {
            $table->dropColumn(['ci', 'last_deploy', 'recent_commits']);
        });
    }
};
