<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 15 §10-11 "Customer Segments / Segment Rules". `rules` is a
// JSON array of {field, operator, value} triples — NEVER raw SQL or an
// arbitrary expression (Non-Negotiable Rules #3-4). Only whitelisted
// fields/operators are ever evaluated — see MarketingSegmentService.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->json('rules'); // list<{field, operator, value}>, ALL must match (AND) — see service docblock
            $table->timestamps();

            $table->index(['store_id'], 'idx_marketing_segments_store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_segments');
    }
};
