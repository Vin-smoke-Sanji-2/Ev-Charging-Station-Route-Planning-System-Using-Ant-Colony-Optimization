<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('road_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_node_id')->constrained('road_nodes')->cascadeOnDelete();
            $table->foreignId('to_node_id')->constrained('road_nodes')->cascadeOnDelete();
            $table->decimal('distance_km', 8, 2);
            $table->decimal('avg_speed_kmh', 6, 2)->default(40);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('road_edges');
    }
};
