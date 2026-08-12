<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_stations', function (Blueprint $table) {
            $table->foreignId('road_node_id')->nullable()->after('owner_user_id')
                ->constrained('road_nodes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('charging_stations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('road_node_id');
        });
    }
};
