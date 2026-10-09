<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign keys to `ai_products` for the columns created before it existed:
 * `ai_requests.product_id` (B1) and `monitored_apps.ai_product_id`
 * (Stage A open door). Both become null when the product row is deleted
 * (products are soft-deleted, so this only happens on a hard delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('ai_products')->nullOnDelete();
        });

        Schema::table('monitored_apps', function (Blueprint $table) {
            $table->foreign('ai_product_id')->references('id')->on('ai_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monitored_apps', function (Blueprint $table) {
            $table->dropForeign(['ai_product_id']);
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });
    }
};
