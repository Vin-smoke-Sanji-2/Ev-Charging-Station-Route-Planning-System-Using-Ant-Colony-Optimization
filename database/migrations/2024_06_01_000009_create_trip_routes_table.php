<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_request_id')->constrained('trip_requests')->cascadeOnDelete();
            $table->foreignId('previous_route_id')->nullable()->constrained('trip_routes')->nullOnDelete();
            $table->string('recalculation_reason')->nullable();
            $table->decimal('total_distance_km', 8, 2)->nullable();
            $table->decimal('total_duration_min', 8, 2)->nullable();
            $table->decimal('est_battery_consumption', 5, 2)->nullable();
            $table->decimal('estimated_cost', 10, 2)->nullable();
            $table->enum('status', ['planned', 'active', 'completed', 'cancelled'])->default('planned');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_routes');
    }
};
