<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
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
        'avatar_path',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'avatar_path',
    ];

    protected $appends = [
        'avatar_url',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
        );
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

    /**
     * Where each role's page-level portal actually lives - the PHP-side
     * mirror of resources/js/pages/login.js's own ROLE_LANDING_PAGES,
     * used as the single source of truth for every server-side "where
     * does this role belong" redirect (EnsureUserBelongsToPortal).
     * This stack has no shared JS/PHP config layer, so a literal single
     * definition across both isn't possible - kept identical by hand
     * instead, the same precedent as haversineDistanceKm() existing
     * separately in both navigate.js and NavigateController. If this
     * ever changes, update login.js's copy too.
     */
    public const ROLE_LANDING_PAGES = [
        'ev_owner' => '/dashboard',
        'station_owner' => '/station-owner/overview',
        'admin' => '/admin/overview',
    ];

    public function landingPage(): string
    {
        return self::ROLE_LANDING_PAGES[$this->role] ?? '/dashboard';
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
