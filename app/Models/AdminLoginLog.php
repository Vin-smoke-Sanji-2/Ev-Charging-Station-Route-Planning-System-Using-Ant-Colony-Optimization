<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per successful admin login (AuthController::verifyOtp()) - which
 * admin, and when. Admin is a shared portal (unlike an EV owner/station
 * owner's own personal account), so this is the audit trail for "who
 * accessed the Dashboard and at what time."
 */
class AdminLoginLog extends Model
{
    protected $fillable = [
        'user_id',
        'ip_address',
        'logged_in_at',
    ];

    protected function casts(): array
    {
        return [
            'logged_in_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
