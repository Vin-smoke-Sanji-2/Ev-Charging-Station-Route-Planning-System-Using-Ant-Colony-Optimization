<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingSlot;
use App\Models\ChargingStation;
use Illuminate\Http\Request;

class ChargingSlotController extends Controller
{
    public function index(ChargingStation $station)
    {
        return response()->json($station->slots);
    }

    public function store(Request $request, ChargingStation $station)
    {
        $this->authorizeStation($request, $station);

        $data = $request->validate([
            'slot_code' => 'required|string|max:50',
            'connector_type' => 'required|string|max:50',
            'power_type' => 'required|in:AC,DC',
            'power_kw' => 'required|numeric|min:0',
            'status' => 'sometimes|in:available,occupied,maintenance',
        ]);

        $data['station_id'] = $station->id;
        $slot = ChargingSlot::create($data);

        $station->update(['total_slots' => $station->slots()->count()]);

        return response()->json($slot, 201);
    }

    public function update(Request $request, ChargingStation $station, ChargingSlot $slot)
    {
        $this->authorizeStation($request, $station);
        abort_unless($slot->station_id === $station->id, 404);

        $data = $request->validate([
            'connector_type' => 'sometimes|string|max:50',
            'power_type' => 'sometimes|in:AC,DC',
            'power_kw' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:available,occupied,maintenance',
        ]);

        $slot->update($data);

        return response()->json($slot->fresh());
    }

    public function destroy(Request $request, ChargingStation $station, ChargingSlot $slot)
    {
        $this->authorizeStation($request, $station);
        abort_unless($slot->station_id === $station->id, 404);

        $slot->delete();
        $station->update(['total_slots' => $station->slots()->count()]);

        return response()->json(['message' => 'Slot removed']);
    }

    private function authorizeStation(Request $request, ChargingStation $station): void
    {
        abort_unless(
            $request->user()->isAdmin() || $station->owner_user_id === $request->user()->id,
            403
        );
    }
}
