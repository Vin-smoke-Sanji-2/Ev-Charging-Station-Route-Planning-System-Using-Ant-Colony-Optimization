<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserVehicle extends Model
{
    protected $fillable = [
        'user_id',
        'ev_model_id',
        'plate_no',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function evModel()
    {
        return $this->belongsTo(EvModel::class);
    }

    public function tripRequests()
    {
        return $this->hasMany(TripRequest::class, 'vehicle_id');
    }

    public function chargingSessions()
    {
        return $this->hasMany(ChargingSession::class, 'vehicle_id');
    }
}
