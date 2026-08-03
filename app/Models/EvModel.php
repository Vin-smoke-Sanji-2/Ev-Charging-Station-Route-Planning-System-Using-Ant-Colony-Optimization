<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvModel extends Model
{
    protected $fillable = [
        'brand',
        'model',
        'battery_capacity_kwh',
        'max_range_km',
        'connector_type',
    ];

    public function vehicles()
    {
        return $this->hasMany(UserVehicle::class);
    }
}
