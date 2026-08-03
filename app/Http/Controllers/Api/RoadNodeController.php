<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoadNode;
use Illuminate\Http\Request;

class RoadNodeController extends Controller
{
    public function index()
    {
        return response()->json(RoadNode::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'type' => 'sometimes|string|max:50',
        ]);

        return response()->json(RoadNode::create($data), 201);
    }

    public function update(Request $request, RoadNode $roadNode)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'latitude' => 'sometimes|numeric',
            'longitude' => 'sometimes|numeric',
            'type' => 'sometimes|string|max:50',
        ]);

        $roadNode->update($data);

        return response()->json($roadNode->fresh());
    }

    public function destroy(RoadNode $roadNode)
    {
        $roadNode->delete();

        return response()->json(['message' => 'Road node removed']);
    }
}
