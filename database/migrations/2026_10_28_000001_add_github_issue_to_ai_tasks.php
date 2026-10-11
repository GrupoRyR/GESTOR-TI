<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage D3 · `ai_tasks.github_issue`: what the Gestor sent when it created
 * the task's issue ("Crear issue en GitHub"): title, labels, assignee
 * login, who created it and when. The board and task page show it until
 * the `issues` webhook of that issue arrives (`GitHubIssue`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_tasks', function (Blueprint $table) {
            $table->json('github_issue')->nullable()->after('github_issue_state');
        });
    }

    public function down(): void
    {
        Schema::table('ai_tasks', function (Blueprint $table) {
            $table->dropColumn('github_issue');
        });
    }
};
