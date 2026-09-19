<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 07 §36-37. Tenant isolation is inherited from attributes.store_id
// (no separate store_id column needed — same pattern as
// permission_role in Phase B1).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('value');
            $table->string('normalized_value'); // Module 07 §37 "Attribute Value Normalization"
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['attribute_id', 'normalized_value'], 'uniq_attribute_values_attribute_id_normalized_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_values');
    }
};
