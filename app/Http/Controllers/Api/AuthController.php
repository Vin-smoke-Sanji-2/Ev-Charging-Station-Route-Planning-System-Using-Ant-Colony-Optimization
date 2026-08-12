<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ChargingStationCreator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => 'nullable|string|max:30',
            'role' => 'nullable|in:ev_owner,station_owner',
        ]);

        $isStationOwner = ($data['role'] ?? 'ev_owner') === 'station_owner';

        // Validated up front, before anything is created - if station data
        // fails validation, registration must fail as a whole with no
        // orphaned user, so both validate() calls happen before any
        // DB::transaction() work starts, not one after the other.
        $stationData = $isStationOwner ? $request->validate([
            // Identical rules to ChargingStationController::store() for
            // every one of these fields - reusing the same nullable/
            // required split that endpoint already established, not a
            // separately-invented set for this second entry point.
            'station.name' => 'required|string|max:255',
            'station.latitude' => 'required|numeric',
            'station.longitude' => 'required|numeric',
            'station.address' => 'nullable|string|max:255',
            'station.township' => 'nullable|string|max:100',
            'station.charging_speed' => 'nullable|string|max:100',
            'station.operating_hours' => 'nullable|string|max:100',
        ])['station'] : null;

        $user = DB::transaction(function () use ($data, $isStationOwner, $stationData) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'role' => $data['role'] ?? 'ev_owner',
                'status' => $isStationOwner ? 'pending' : 'active',
            ]);

            if ($isStationOwner) {
                // The exact same creation path ChargingStationController::
                // store() uses (owner_user_id/verification_status/
                // total_slots/road_node_id all handled identically there),
                // not a second, separately-maintained way to make a station.
                (new ChargingStationCreator)->create($user->id, $stationData);
            }

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json($user, 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $request->session()->regenerate();

        return response()->json(Auth::user());
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:30',
        ]);

        $request->user()->update($data);

        return response()->json($request->user()->fresh());
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 422);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => 'Password updated']);
    }

    public function uploadAvatar(Request $request)
    {
        $data = $request->validate([
            'avatar' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $data['avatar']->store('avatars', 'public');
        $user->update(['avatar_path' => $path]);

        return response()->json($user->fresh());
    }

    public function deleteAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return response()->json($user->fresh());
    }
}
