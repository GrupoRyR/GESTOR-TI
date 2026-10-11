<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employees (colaboradores) of each company: synchronized from Microsoft
     * Graph (`source = graph`) or created by hand (`source = manual`).
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            // True when the area was assigned by hand: the sync keeps it.
            $table->boolean('area_locked')->default(false);
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('source', 10)->default('manual');
            $table->string('azure_id', 64)->nullable();
            $table->string('name');
            $table->string('email')->nullable()->index();
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->string('manager_azure_id', 64)->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 10)->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'azure_id']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
