<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoadEdge extends Model
{
    protected $fillable = [
        'from_node_id',
        'to_node_id',
        'distance_km',
        'avg_speed_kmh',
    ];

    public function fromNode()
    {
        return $this->belongsTo(RoadNode::class, 'from_node_id');
    }

    public function toNode()
    {
        return $this->belongsTo(RoadNode::class, 'to_node_id');
    }
}
