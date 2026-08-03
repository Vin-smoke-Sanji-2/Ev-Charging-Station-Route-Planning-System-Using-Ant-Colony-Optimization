<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class FavoriteStationController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            $request->user()->favoriteStations()->with('station')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'station_id' => 'required|exists:charging_stations,id',
        ]);

        $favorite = $request->user()->favoriteStations()->firstOrCreate($data);

        return response()->json($favorite->load('station'), 201);
    }

    public function destroy(Request $request, int $stationId)
    {
        $request->user()->favoriteStations()->where('station_id', $stationId)->delete();

        return response()->json(['message' => 'Removed from favorites']);
    }
}
