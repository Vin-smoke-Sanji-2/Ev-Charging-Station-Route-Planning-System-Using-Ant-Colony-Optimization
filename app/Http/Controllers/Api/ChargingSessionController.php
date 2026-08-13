<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Models\ChargingStation;
use App\Models\TripRoute;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChargingSessionController extends Controller
{
    /**
     * List sessions. EV owners see their own; station owners see sessions
     * for stations they own; admins see everything (optionally filtered
     * with ?station_id=).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = ChargingSession::query()->with(['station', 'slot', 'user', 'vehicle.evModel']);

        if ($user->isStationOwner()) {
            $query->whereHas('station', fn ($q) => $q->where('owner_user_id', $user->id));
        } elseif (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('station_id')) {
            $query->where('station_id', $request->integer('station_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->latest()->paginate(20));
    }

    /**
     * Join the queue / start a session at a station. If a slot is
     * available it is assigned immediately (status = charging),
     * otherwise the session is created as status = waiting and the
     * station's queue_length() will include it.
     */
    public function store(Request $request, ChargingStation $station)
    {
        $data = $request->validate([
            'vehicle_id' => [
                'nullable',
                Rule::exists('user_vehicles', 'id')->where('user_id', $request->user()->id),
            ],
            'trip_route_id' => 'nullable|exists:trip_routes,id',
        ]);

        if (! empty($data['trip_route_id'])) {
            $tripRoute = TripRoute::with('tripRequest')->find($data['trip_route_id']);
            abort_if(
                $tripRoute->tripRequest->user_id !== $request->user()->id,
                422,
                'This trip route does not belong to you.'
            );
        }

        $slot = $station->slots()->where('status', 'available')->first();

        $session = ChargingSession::create([
            'station_id' => $station->id,
            'slot_id' => $slot?->id,
            'user_id' => $request->user()->id,
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'trip_route_id' => $data['trip_route_id'] ?? null,
            'status' => $slot ? 'charging' : 'waiting',
            'queued_at' => now(),
            'started_at' => $slot ? now() : null,
        ]);

        if ($slot) {
            $slot->update(['status' => 'occupied']);
        }

        if ($station->owner_user_id) {
            UserNotification::create([
                'user_id' => $station->owner_user_id,
                'type' => 'Queue',
                'message' => "{$request->user()->name} " . ($slot ? 'started charging' : 'joined the waiting queue')
                    . " at \"{$station->name}\".",
            ]);
        }

        return response()->json($session->load(['station', 'slot']), 201);
    }

    /**
     * Transition a session: assign a freed-up slot, complete it (records
     * energy/payment), or cancel it. Also frees the slot and promotes the
     * next waiting session at the same station when a session completes
     * or is cancelled.
     */
    public function update(Request $request, ChargingSession $session)
    {
        abort_unless(
            $request->user()->isAdmin() || $session->station->owner_user_id === $request->user()->id,
            403
        );

        if ($message = $request->user()->stationOwnerAccessDeniedMessage()) {
            abort(403, $message);
        }

        $data = $request->validate([
            'status' => 'required|in:charging,completed,cancelled',
            'energy_kwh' => 'nullable|numeric|min:0',
            'payment_amount' => 'nullable|numeric|min:0',
        ]);

        if ($data['status'] === 'charging' && $session->status === 'waiting') {
            $slot = $session->station->slots()->where('status', 'available')->first();
            abort_if(! $slot, 422, 'No available slot to assign yet');
            $slot->update(['status' => 'occupied']);
            $session->update(['slot_id' => $slot->id, 'status' => 'charging', 'started_at' => now()]);

            UserNotification::create([
                'user_id' => $session->user_id,
                'type' => 'Charging',
                'message' => "You've been assigned Slot {$slot->slot_code} at \"{$session->station->name}\" - you can start charging now.",
            ]);
        }

        if (in_array($data['status'], ['completed', 'cancelled'], true)) {
            $session->update([
                'status' => $data['status'],
                'ended_at' => now(),
                'energy_kwh' => $data['energy_kwh'] ?? $session->energy_kwh,
                'payment_amount' => $data['payment_amount'] ?? $session->payment_amount,
            ]);

            // This endpoint can only ever be reached by the station's own
            // owner or an admin (see the abort_unless above), never by the
            // session's own EV owner - so this is always "someone else did
            // this to your session," a genuinely useful thing to be told
            // about, not an echo of the recipient's own action.
            UserNotification::create([
                'user_id' => $session->user_id,
                'type' => 'Charging',
                'message' => "Your charging session at \"{$session->station->name}\" has been {$data['status']}.",
            ]);

            if ($session->slot_id) {
                $session->slot->update(['status' => 'available']);
            }

            $next = ChargingSession::where('station_id', $session->station_id)
                ->where('status', 'waiting')
                ->orderBy('queued_at')
                ->first();

            if ($next) {
                $freedSlot = $session->slot ?? $session->station->slots()->where('status', 'available')->first();
                if ($freedSlot) {
                    $freedSlot->update(['status' => 'occupied']);
                    $next->update(['slot_id' => $freedSlot->id, 'status' => 'charging', 'started_at' => now()]);

                    UserNotification::create([
                        'user_id' => $next->user_id,
                        'type' => 'Charging',
                        'message' => "You've been assigned Slot {$freedSlot->slot_code} at \"{$session->station->name}\" - you can start charging now.",
                    ]);
                }
            }
        }

        return response()->json($session->fresh(['station', 'slot']));
    }
}
