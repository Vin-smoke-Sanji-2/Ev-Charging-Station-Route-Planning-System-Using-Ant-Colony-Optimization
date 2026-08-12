<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripRoute extends Model
{
    protected $fillable = [
        'trip_request_id',
        'previous_route_id',
        'recalculation_reason',
        'total_distance_km',
        'total_duration_min',
        'est_battery_consumption',
        'estimated_cost',
        'status',
        'edge_path_ids',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'edge_path_ids' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tripRequest()
    {
        return $this->belongsTo(TripRequest::class);
    }

    public function previousRoute()
    {
        return $this->belongsTo(TripRoute::class, 'previous_route_id');
    }

    public function recalculatedInto()
    {
        return $this->hasOne(TripRoute::class, 'previous_route_id');
    }

    public function chargingStops()
    {
        return $this->hasMany(RouteChargingStop::class)->orderBy('sequence_no');
    }

    public function chargingSessions()
    {
        return $this->hasMany(ChargingSession::class);
    }
}
