<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every AI provider call (PROMPT §6.8.3): tokens with and without cache,
     * cost and duration. Logic arrives with the AI client (Stage B/C).
     */
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->string('feature', 64);
            $table->nullableMorphs('source');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_interactions');
    }
};
