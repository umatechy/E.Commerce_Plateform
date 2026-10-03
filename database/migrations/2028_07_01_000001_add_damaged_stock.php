<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 08 §47 "Damaged Stock" (Phase B34): damaged units get their own
 * quantity ("separate quantity state"), apart from what is on hand. They
 * are never part of `available` (on hand − reserved), so they cannot be
 * sold by accident; staff write them off when they are disposed of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->unsignedInteger('damaged')->default(0)->after('incoming');
        });
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('damaged');
        });
    }
};
