<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('charging_stations')->cascadeOnDelete();
            $table->string('slot_code');
            $table->string('connector_type');
            $table->decimal('power_kw', 6, 2);
            $table->enum('status', ['available', 'occupied', 'maintenance'])->default('available');
            $table->timestamps();
            $table->unique(['station_id', 'slot_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_slots');
    }
};
