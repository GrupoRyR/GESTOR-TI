<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Microsoft department → area of the company. `department_key` is the
     * normalized department (lower case, no accents, single spaces).
     */
    public function up(): void
    {
        Schema::create('department_area_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('department');
            $table->string('department_key');
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'department_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_area_mappings');
    }
};
