<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_routes', function (Blueprint $table) {
            // Distinct from created_at (when the route was PLANNED) - these
            // record when driving actually began/ended, set only by
            // TripController::start()/complete(). Nullable: every route
            // stays 'planned' (or jumps straight to 'cancelled' via
            // recalculate()) unless a real Live Trip is started.
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('trip_routes', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'completed_at']);
        });
    }
};
