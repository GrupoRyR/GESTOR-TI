<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Open door for the monitoring module (PROMPT §2 and §7.3). No endpoints
     * yet: heartbeats will arrive through the reserved /api/local-apps/v1.
     */
    public function up(): void
    {
        Schema::create('monitored_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type', 16);
            $table->string('url')->nullable();
            $table->string('criticality', 16)->default('medium');
            $table->string('last_status', 32)->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedInteger('heartbeat_interval_s')->default(300);
            // The ai_products table is created in Stage B: no foreign key yet.
            // Add it (nullOnDelete) in the migration that creates ai_products.
            $table->unsignedBigInteger('ai_product_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitored_apps');
    }
};
