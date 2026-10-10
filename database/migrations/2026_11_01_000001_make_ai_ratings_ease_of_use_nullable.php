<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage F: the one-click rating of the "Ya está en producción" email saves
 * only the "solved need" score, so `ease_of_use` becomes optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_ratings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('ease_of_use')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_ratings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('ease_of_use')->nullable(false)->change();
        });
    }
};
