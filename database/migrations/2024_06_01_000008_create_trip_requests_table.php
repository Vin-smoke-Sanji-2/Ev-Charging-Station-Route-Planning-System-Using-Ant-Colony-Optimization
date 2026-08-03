<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('user_vehicles')->cascadeOnDelete();
            $table->foreignId('origin_node_id')->constrained('road_nodes')->restrictOnDelete();
            $table->foreignId('destination_node_id')->constrained('road_nodes')->restrictOnDelete();
            $table->decimal('battery_percent', 5, 2);
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_requests');
    }
};
