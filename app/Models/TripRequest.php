<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripRequest extends Model
{
    protected $fillable = [
        'user_id',
        'vehicle_id',
        'origin_node_id',
        'destination_node_id',
        'battery_percent',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(UserVehicle::class, 'vehicle_id');
    }

    public function originNode()
    {
        return $this->belongsTo(RoadNode::class, 'origin_node_id');
    }

    public function destinationNode()
    {
        return $this->belongsTo(RoadNode::class, 'destination_node_id');
    }

    public function routes()
    {
        return $this->hasMany(TripRoute::class);
    }

    public function latestRoute()
    {
        return $this->hasOne(TripRoute::class)->latestOfMany();
    }
}
