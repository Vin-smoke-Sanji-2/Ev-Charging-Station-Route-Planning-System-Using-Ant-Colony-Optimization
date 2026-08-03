<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvModel;
use Illuminate\Http\Request;

class EvModelController extends Controller
{
    public function index()
    {
        return response()->json(EvModel::orderBy('brand')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'brand' => 'required|string|max:100',
            'model' => 'required|string|max:100',
            'battery_capacity_kwh' => 'required|numeric|min:0',
            'max_range_km' => 'required|numeric|min:0',
            'connector_type' => 'required|string|max:50',
        ]);

        return response()->json(EvModel::create($data), 201);
    }

    public function update(Request $request, EvModel $evModel)
    {
        $data = $request->validate([
            'brand' => 'sometimes|string|max:100',
            'model' => 'sometimes|string|max:100',
            'battery_capacity_kwh' => 'sometimes|numeric|min:0',
            'max_range_km' => 'sometimes|numeric|min:0',
            'connector_type' => 'sometimes|string|max:50',
        ]);

        $evModel->update($data);

        return response()->json($evModel->fresh());
    }

    public function destroy(EvModel $evModel)
    {
        $evModel->delete();

        return response()->json(['message' => 'EV model removed']);
    }
}
