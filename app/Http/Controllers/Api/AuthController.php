<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\LoginOtpMail;
use App\Models\LoginOtp;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ChargingStationCreator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
                // Separate from ChargingStationCreator's own "new pending
                // station" notification below - the account and the
                // station are two independent approval gates reviewed on
                // two different admin screens (Station Owners vs
                // Stations), so admin needs to know about each.
                UserNotification::notifyAdmins(
                    'Registration',
                    "{$user->name} registered as a station owner and is awaiting approval."
                );
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

    // Roles that must pass an emailed OTP after their password before a
    // session is actually established - a deliberate, explicit product
    // decision, not every role. Originally ev_owner + station_owner;
    // changed 2026-08-13 to station_owner + admin - ev_owner no longer
    // needs OTP, admin now does (the reverse of the original decision).
    private const OTP_REQUIRED_ROLES = ['station_owner', 'admin'];

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Auth::validate() checks the credentials WITHOUT establishing a
        // session (unlike Auth::attempt()) - needed because a correct
        // password should never immediately log an OTP-required user in;
        // it should only unlock the OTP step. login() itself never
        // creates a session for those roles - verifyOtp() does.
        if (! Auth::validate($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = User::where('email', $credentials['email'])->firstOrFail();

        if (! in_array($user->role, self::OTP_REQUIRED_ROLES, true)) {
            Auth::login($user);
            $request->session()->regenerate();

            return response()->json($user);
        }

        $this->sendLoginOtp($user);

        return response()->json([
            'otp_required' => true,
            'email' => $user->email,
        ]);
    }

    /**
     * Second step for OTP-required roles - the actual point where a
     * session gets established, mirroring what login() used to do
     * directly. $request->user() is NOT authenticated yet at this point
     * (that's the whole reason this endpoint exists), so this deliberately
     * looks the user up by email again rather than assuming a prior
     * request's identity.
     */
    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'code' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        // Same generic message whether the email doesn't exist, the code
        // is wrong, or it's expired/already used - LoginOtp::attemptVerify()
        // is what actually enforces all of that; this endpoint doesn't
        // need to (and shouldn't) distinguish those cases in the response.
        if (! $user || ! LoginOtp::attemptVerify($user, $data['code'])) {
            return response()->json(['message' => 'Invalid or expired code'], 422);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json($user);
    }

    public function resendOtp(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $data['email'])->first();

        // Always the same response, whether or not the account exists or
        // even needs OTP - avoids letting this endpoint be used to probe
        // which emails are registered.
        if ($user && in_array($user->role, self::OTP_REQUIRED_ROLES, true)) {
            $this->sendLoginOtp($user);
        }

        return response()->json(['message' => 'If an account needs a code, a new one has been sent.']);
    }

    private function sendLoginOtp(User $user): void
    {
        $code = LoginOtp::generateFor($user);

        Mail::mailer('sendgrid')->to($user->email)->send(new LoginOtpMail($code));

        // Local-dev convenience only: SendGrid's API accepts a send to a
        // fake/nonexistent address without complaint (no synchronous
        // bounce), so there's otherwise no way to retrieve a code sent to
        // a made-up test address during local testing. Never logs outside
        // 'local' - an OTP code is a real credential and must never leak
        // into a real deployment's log files.
        if (app()->environment('local')) {
            Log::info("Login OTP for {$user->email}: {$code}");
        }
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

        // Delete the previous file (if any) BEFORE storing the new one -
        // same ordering as before the Cloudinary move, so a failure
        // mid-request can't orphan the old file. avatar_path now holds a
        // Cloudinary public_id, not a local relative path, but the
        // Storage-facade contract (delete/store/url all keyed by that one
        // string) is otherwise unchanged.
        if ($user->avatar_path) {
            Storage::disk('cloudinary')->delete($user->avatar_path);
        }

        $path = $data['avatar']->store('avatars', 'cloudinary');
        $user->update(['avatar_path' => $path]);

        return response()->json($user->fresh());
    }

    public function deleteAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('cloudinary')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return response()->json($user->fresh());
    }
}
