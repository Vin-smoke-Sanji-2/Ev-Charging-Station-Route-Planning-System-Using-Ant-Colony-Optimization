<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    protected $table = 'user_notifications';

    protected $fillable = [
        'user_id',
        'type',
        'message',
        'is_read',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Notifies every admin account at once - used for events an admin
     * needs to act on (a new pending station, a new pending station-owner
     * registration) rather than any specific user. No Events/Listeners
     * infrastructure exists anywhere in this project (controllers call
     * models directly throughout), so this stays a plain static helper
     * rather than introducing a new architectural pattern for just this.
     */
    public static function notifyAdmins(string $type, string $message): void
    {
        User::where('role', 'admin')->get()->each(
            fn (User $admin) => self::create([
                'user_id' => $admin->id,
                'type' => $type,
                'message' => $message,
            ])
        );
    }
}
