<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage C forms of the Gestor IA (types/pages/ai-knowledge.ts):
 *
 * - `ai_products.kind`: "Tipo de app" of the product form
 *   (web | robot | integration | mobile).
 * - `ai_product_incidents`: the fields of the incident drawer (impact,
 *   description, cause and solution), its origin (`manual` while monitoring
 *   is off) and who was notified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_products', function (Blueprint $table) {
            $table->string('kind', 16)->default('web')->after('status');
        });

        Schema::table('ai_product_incidents', function (Blueprint $table) {
            // low | medium | high | critical
            $table->string('impact', 12)->nullable()->after('title');
            $table->text('description')->nullable()->after('impact');
            $table->text('resolution')->nullable()->after('resolved_at');
            // manual | monitoring
            $table->string('origin', 16)->default('manual')->after('resolution');
            $table->json('notified_user_ids')->nullable()->after('reported_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_product_incidents', function (Blueprint $table) {
            $table->dropColumn(['impact', 'description', 'resolution', 'origin', 'notified_user_ids']);
        });

        Schema::table('ai_products', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
