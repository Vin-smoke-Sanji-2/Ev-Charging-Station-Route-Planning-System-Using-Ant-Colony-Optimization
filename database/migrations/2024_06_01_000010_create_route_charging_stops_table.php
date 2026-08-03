<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_charging_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_route_id')->constrained('trip_routes')->cascadeOnDelete();
            $table->foreignId('station_id')->constrained('charging_stations')->restrictOnDelete();
            $table->unsignedInteger('sequence_no');
            $table->unsignedInteger('estimated_wait_min')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_charging_stops');
    }
};
