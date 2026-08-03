<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ev_models', function (Blueprint $table) {
            $table->id();
            $table->string('brand');
            $table->string('model');
            $table->decimal('battery_capacity_kwh', 6, 2);
            $table->decimal('max_range_km', 6, 2);
            $table->string('connector_type');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ev_models');
    }
};
