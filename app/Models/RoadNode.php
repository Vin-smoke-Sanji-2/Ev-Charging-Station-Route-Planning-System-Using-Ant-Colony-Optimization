<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoadNode extends Model
{
    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'type',
    ];

    public function chargingStations()
    {
        return $this->hasMany(ChargingStation::class, 'road_node_id');
    }

    public function outgoingEdges()
    {
        return $this->hasMany(RoadEdge::class, 'from_node_id');
    }

    public function incomingEdges()
    {
        return $this->hasMany(RoadEdge::class, 'to_node_id');
    }
}
