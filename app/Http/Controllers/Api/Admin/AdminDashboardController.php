<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChargingStation;
use App\Models\TripRequest;
use App\Models\User;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function stats()
    {
        return response()->json([
            'total_users' => User::count(),
            'total_stations' => ChargingStation::count(),
            'total_trips' => TripRequest::count(),
            'active_today' => User::whereDate('updated_at', today())->count(),
            'pending_station_verifications' => ChargingStation::where('verification_status', 'pending')->count(),
            'recent_registrations' => User::latest()->limit(5)->get(['id', 'name', 'role', 'created_at']),
        ]);
    }

    public function users(Request $request)
    {
        $query = User::query();

        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function updateUserStatus(Request $request, User $user)
    {
        $data = $request->validate([
            'status' => 'required|in:active,pending,suspended,rejected',
        ]);

        $user->update($data);

        return response()->json($user->fresh());
    }

    public function pendingStations()
    {
        return response()->json(
            ChargingStation::where('verification_status', 'pending')->with('owner:id,name,email')->get()
        );
    }

    public function verifyStation(Request $request, ChargingStation $station)
    {
        $data = $request->validate([
            'verification_status' => 'required|in:verified,rejected',
        ]);

        $station->update($data);

        return response()->json($station->fresh());
    }
}
