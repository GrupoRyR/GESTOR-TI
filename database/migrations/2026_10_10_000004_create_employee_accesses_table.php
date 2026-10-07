<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accesses of each employee. `password` holds a Laravel `Crypt`
     * ciphertext (APP_KEY), never the plain value.
     */
    public function up(): void
    {
        Schema::create('employee_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_system_id')->constrained()->restrictOnDelete();
            $table->string('username');
            $table->text('password');
            $table->text('notes')->nullable();
            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'access_system_id', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_accesses');
    }
};
