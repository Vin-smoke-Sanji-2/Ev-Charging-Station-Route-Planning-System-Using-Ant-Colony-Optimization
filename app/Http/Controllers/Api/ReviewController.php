<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingStation;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(ChargingStation $station)
    {
        return response()->json(
            $station->reviews()->with('user:id,name')->latest()->paginate(10)
        );
    }

    public function store(Request $request, ChargingStation $station)
    {
        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $data['user_id'] = $request->user()->id;
        $review = $station->reviews()->create($data);

        return response()->json($review->load('user:id,name'), 201);
    }
}
