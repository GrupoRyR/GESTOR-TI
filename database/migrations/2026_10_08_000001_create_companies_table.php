<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_name', 60);
            $table->string('slug', 60)->unique();
            // Microsoft Entra tenant GUID; only active companies' tenants may log in (A5).
            $table->string('tenant_id', 36)->nullable()->unique();
            $table->json('domains')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('logo_dark_path')->nullable();
            $table->json('colors')->nullable();
            // Company mailbox used as sender for every outgoing mail of its records.
            $table->string('sender_email')->nullable();
            $table->string('sender_name')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
