<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal tokens of the MCP connector (Profile/Mcp): only the SHA-256 of
 * the value is stored, never the value. `expires_at` is the last calendar
 * day (Bogotá) the token works. Rows are never deleted: revoking keeps the
 * row and its `mcp_calls`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('scope', 20);
            $table->char('token_hash', 64)->unique();
            $table->char('last_four', 4);
            $table->date('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_client', 100)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
    }
};
