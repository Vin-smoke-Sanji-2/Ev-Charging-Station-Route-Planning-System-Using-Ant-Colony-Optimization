<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_charging_stops', function (Blueprint $table) {
            // Null = not yet reached. Set only by
            // TripController::markStopReached(), driven by the frontend's
            // automatic geofence detection during a Live Trip - never a
            // manual "mark as reached" action.
            $table->timestamp('reached_at')->nullable()->after('estimated_wait_min');
        });
    }

    public function down(): void
    {
        Schema::table('route_charging_stops', function (Blueprint $table) {
            $table->dropColumn('reached_at');
        });
    }
};
