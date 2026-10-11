<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit of the MCP server: every `tools/call` (personal token or OAuth)
 * and the refused calls of a known personal token. Never the token value
 * nor what the Gestor answered. Pruned after `gestor.mcp.calls_retention_days`
 * (`mcp:prune-calls`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mcp_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('oauth_client_id', 100)->nullable();
            $table->string('tool', 60)->nullable();
            $table->string('target', 120)->nullable();
            $table->string('result', 20);
            $table->string('message', 160)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['mcp_token_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_calls');
    }
};
