<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->string('key'); // e.g. "multi_currency", "max_products"
            $table->string('type', 16); // feature|usage_limit (see EntitlementType)
            $table->boolean('boolean_value')->nullable();
            $table->unsignedInteger('limit_value')->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'key'], 'uniq_package_entitlements_package_id_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_entitlements');
    }
};
