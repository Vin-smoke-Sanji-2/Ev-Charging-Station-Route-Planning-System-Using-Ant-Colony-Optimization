<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargingSlot extends Model
{
    protected $fillable = [
        'station_id',
        'slot_code',
        'connector_type',
        'power_kw',
        'status',
    ];

    public function station()
    {
        return $this->belongsTo(ChargingStation::class, 'station_id');
    }

    public function sessions()
    {
        return $this->hasMany(ChargingSession::class, 'slot_id');
    }
}
