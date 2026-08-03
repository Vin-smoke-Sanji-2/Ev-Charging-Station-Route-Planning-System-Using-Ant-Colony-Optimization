<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('charging_stations')->cascadeOnDelete();
            $table->foreignId('slot_id')->nullable()->constrained('charging_slots')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('user_vehicles')->nullOnDelete();
            $table->foreignId('trip_route_id')->nullable()->constrained('trip_routes')->nullOnDelete();
            $table->enum('status', ['waiting', 'charging', 'completed', 'cancelled'])->default('waiting');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->decimal('energy_kwh', 7, 2)->nullable();
            $table->decimal('payment_amount', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_sessions');
    }
};
