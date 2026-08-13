<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TripRequest;
use Illuminate\Http\Request;

/**
 * A separate controller, not folded into AdminDashboardController - trips
 * are an unrelated resource domain with no status-transition workflow of
 * their own (unlike users/stations, which share the two transition-map
 * constants there), matching this project's existing one-controller-
 * per-resource convention (EvModelController, RoadNodeController, ...).
 */
class AdminTripController extends Controller
{
    /**
     * TripRequest has no status column of its own - "trip status" really
     * means the status of its latest TripRoute (see TripRequest::
     * latestRoute()), so filtering by status goes through whereHas()
     * rather than a plain column filter. A trip with no route yet (still
     * planning, or planning failed) is deliberately excluded by any
     * status filter, since it has no status to match against - included
     * only when the filter itself is omitted.
     */
    public function index(Request $request)
    {
        $query = TripRequest::query()->with([
            'user:id,name,email',
            'vehicle.evModel',
            'originNode',
            'destinationNode',
            'latestRoute',
        ]);

        if ($request->filled('status')) {
            $statuses = array_filter(explode(',', (string) $request->string('status')));
            $query->whereHas('latestRoute', fn ($q) => $q->whereIn('status', $statuses));
        }

        if ($request->filled('name')) {
            $search = $request->string('name');
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        return response()->json($query->latest()->paginate(20));
    }
}
