<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoadEdge;
use Illuminate\Http\Request;

class RoadEdgeController extends Controller
{
    public function index()
    {
        return response()->json(RoadEdge::with(['fromNode', 'toNode'])->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'from_node_id' => 'required|exists:road_nodes,id',
            'to_node_id' => 'required|exists:road_nodes,id|different:from_node_id',
            'distance_km' => 'required|numeric|min:0',
            'avg_speed_kmh' => 'sometimes|numeric|min:1',
        ]);

        return response()->json(RoadEdge::create($data)->load(['fromNode', 'toNode']), 201);
    }

    public function update(Request $request, RoadEdge $roadEdge)
    {
        $data = $request->validate([
            'distance_km' => 'sometimes|numeric|min:0',
            'avg_speed_kmh' => 'sometimes|numeric|min:1',
        ]);

        $roadEdge->update($data);

        return response()->json($roadEdge->fresh(['fromNode', 'toNode']));
    }

    public function destroy(RoadEdge $roadEdge)
    {
        $roadEdge->delete();

        return response()->json(['message' => 'Road edge removed']);
    }
}
