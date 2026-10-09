<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named counters for human keys (`ai_requests` → GIA-n). Incremented with a
 * row lock (App\Domain\Ai\RequestKeys), so numbers are never repeated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->string('name', 60)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
