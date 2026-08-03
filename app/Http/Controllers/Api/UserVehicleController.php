<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserVehicle;
use Illuminate\Http\Request;

class UserVehicleController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            $request->user()->vehicles()->with('evModel')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'ev_model_id' => 'required|exists:ev_models,id',
            'plate_no' => 'nullable|string|max:30',
            'is_default' => 'sometimes|boolean',
        ]);

        $data['user_id'] = $request->user()->id;

        if (! empty($data['is_default'])) {
            $request->user()->vehicles()->update(['is_default' => false]);
        }

        $vehicle = UserVehicle::create($data);

        return response()->json($vehicle->load('evModel'), 201);
    }

    public function show(Request $request, UserVehicle $vehicle)
    {
        $this->authorizeOwner($request, $vehicle);

        return response()->json($vehicle->load('evModel'));
    }

    public function update(Request $request, UserVehicle $vehicle)
    {
        $this->authorizeOwner($request, $vehicle);

        $data = $request->validate([
            'ev_model_id' => 'sometimes|exists:ev_models,id',
            'plate_no' => 'nullable|string|max:30',
            'is_default' => 'sometimes|boolean',
        ]);

        if (! empty($data['is_default'])) {
            $request->user()->vehicles()->where('id', '!=', $vehicle->id)->update(['is_default' => false]);
        }

        $vehicle->update($data);

        return response()->json($vehicle->fresh('evModel'));
    }

    public function destroy(Request $request, UserVehicle $vehicle)
    {
        $this->authorizeOwner($request, $vehicle);
        $vehicle->delete();

        return response()->json(['message' => 'Vehicle removed']);
    }

    private function authorizeOwner(Request $request, UserVehicle $vehicle): void
    {
        abort_unless($vehicle->user_id === $request->user()->id, 403);
    }
}
