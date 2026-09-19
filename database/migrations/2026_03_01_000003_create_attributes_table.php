<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 07 §27-29.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->string('key'); // stable internal reference, e.g. "color"
            $table->string('type', 16); // select|multi_select|boolean|numeric|text
            $table->timestamps();

            $table->unique(['store_id', 'key'], 'uniq_attributes_store_id_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attributes');
    }
};
