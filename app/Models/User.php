<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function vehicles()
    {
        return $this->hasMany(UserVehicle::class);
    }

    public function tripRequests()
    {
        return $this->hasMany(TripRequest::class);
    }

    public function ownedStations()
    {
        return $this->hasMany(ChargingStation::class, 'owner_user_id');
    }

    public function favoriteStations()
    {
        return $this->hasMany(FavoriteStation::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function chargingSessions()
    {
        return $this->hasMany(ChargingSession::class);
    }

    public function appNotifications()
    {
        return $this->hasMany(UserNotification::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStationOwner(): bool
    {
        return $this->role === 'station_owner';
    }

    public function stationOwnerAccessDeniedMessage(): ?string
    {
        if ($this->role !== 'station_owner' || $this->status === 'active') {
            return null;
        }

        return match ($this->status) {
            'pending' => 'Your station owner account is pending admin approval.',
            'rejected' => 'Your station owner application was not approved.',
            'suspended' => 'Your station owner account has been suspended.',
            default => 'Your station owner account cannot access this feature.',
        };
    }
}
