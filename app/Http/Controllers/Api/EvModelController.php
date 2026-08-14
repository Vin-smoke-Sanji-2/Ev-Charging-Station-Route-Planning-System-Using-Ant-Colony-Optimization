<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvModel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class EvModelController extends Controller
{
    public function index()
    {
        return response()->json(EvModel::orderBy('brand')->get());
    }

    /**
     * Reachable two ways: admin's own management screen (POST
     * /admin/ev-models), and any authenticated user typing a model that
     * isn't in My EVs' dropdown (POST /ev-models - see UserVehicleController's
     * own doc comment on why a vehicle can't just carry free-text instead).
     * Both routes hit this exact same method/validation - there's no
     * separate, less-strict path for the non-admin case.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'brand' => 'required|string|max:100',
            'model' => 'required|string|max:100',
            'battery_capacity_kwh' => 'required|numeric|min:0',
            'max_range_km' => 'required|numeric|min:0',
            'connector_type' => 'required|string|max:50',
        ]);

        // Case-insensitive find-or-create on brand+model - opening this up
        // to every authenticated user (not just admin) means two different
        // people typing "Tesla"/"Model 3" on two different days shouldn't
        // silently produce two near-duplicate rows that both then show up
        // in everyone's dropdown. Returns the existing row as-is (200) if
        // one already matches, rather than creating a duplicate even if
        // the submitted capacity/range/connector differ slightly - the
        // first real entry wins, matching this project's established
        // "duplicate-safe" precedent (stations:seed-file, RoadNodeLinker).
        $existing = EvModel::whereRaw('LOWER(brand) = ?', [mb_strtolower($data['brand'])])
            ->whereRaw('LOWER(model) = ?', [mb_strtolower($data['model'])])
            ->first();

        if ($existing) {
            return response()->json($existing, 200);
        }

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

    /**
     * user_vehicles.ev_model_id is restrictOnDelete() - an in-use model
     * can never be deleted (the DB itself would refuse it). Checked up
     * front so this is a clean 422 with a real, specific count in the
     * message, not a raw unhandled QueryException surfacing as a 500 -
     * and re-checked via a try/catch around the actual delete() as a
     * defense-in-depth safety net against a vehicle being created in the
     * gap between this check and that call.
     */
    public function destroy(EvModel $evModel)
    {
        $vehicleCount = $evModel->vehicles()->count();

        abort_if(
            $vehicleCount > 0,
            422,
            "This EV model cannot be deleted because {$vehicleCount} vehicle(s) currently use it."
        );

        try {
            $evModel->delete();
        } catch (QueryException $e) {
            abort(422, 'This EV model cannot be deleted because it is currently in use.');
        }

        return response()->json(['message' => 'EV model removed']);
    }
}
