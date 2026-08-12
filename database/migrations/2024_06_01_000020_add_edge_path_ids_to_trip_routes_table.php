<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_routes', function (Blueprint $table) {
            // Nullable - trips planned before this column existed have no
            // recorded path; RouteGeometryBuilder falls back to a fresh
            // Dijkstra reconstruction for those. Ordered array of
            // road_edges.id values, the winning ant's actual traversed
            // edges (previously computed by AcoRouteEngine but discarded).
            $table->json('edge_path_ids')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('trip_routes', function (Blueprint $table) {
            $table->dropColumn('edge_path_ids');
        });
    }
};
