<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * OTP login codes (AuthController::login()/verifyOtp()). A fresh row per
 * send rather than one column on users, so a resend never has to guess
 * which code is "current" - the newest unconsumed, unexpired row always
 * wins. code is hashed at rest (like a password), even though it's
 * short-lived and single-use.
 */
class LoginOtp extends Model
{
    private const VALID_MINUTES = 10;

    protected $fillable = [
        'user_id',
        'code',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Creates a fresh OTP for the given user and returns the PLAIN code -
     * never persisted in plain text, only its hash is stored - so the
     * caller can email it immediately and then discard it.
     */
    public static function generateFor(User $user): string
    {
        $code = (string) random_int(100000, 999999);

        $user->loginOtps()->create([
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::VALID_MINUTES),
        ]);

        return $code;
    }

    /**
     * Checks $code against the user's most recent unconsumed, unexpired
     * OTP and marks it consumed on success. An expired or already-used
     * code is never accepted even if the digits still match.
     */
    public static function attemptVerify(User $user, string $code): bool
    {
        $otp = $user->loginOtps()
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $otp || ! Hash::check($code, $otp->code)) {
            return false;
        }

        $otp->update(['consumed_at' => now()]);

        return true;
    }
}
