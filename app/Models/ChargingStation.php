<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargingStation extends Model
{
    protected $fillable = [
        'owner_user_id',
        'name',
        'latitude',
        'longitude',
        'address',
        'township',
        'total_slots',
        'charging_speed',
        'operating_hours',
        'verification_status',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function slots()
    {
        return $this->hasMany(ChargingSlot::class, 'station_id');
    }

    public function sessions()
    {
        return $this->hasMany(ChargingSession::class, 'station_id');
    }

    public function routeStops()
    {
        return $this->hasMany(RouteChargingStop::class, 'station_id');
    }

    public function favoritedBy()
    {
        return $this->hasMany(FavoriteStation::class, 'station_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'station_id');
    }

    public function availableSlotsCount(): int
    {
        return $this->slots()->where('status', 'available')->count();
    }

    public function queueLength(): int
    {
        return $this->sessions()->where('status', 'waiting')->count();
    }
}
