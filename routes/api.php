<?php

use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChargingSessionController;
use App\Http\Controllers\Api\ChargingSlotController;
use App\Http\Controllers\Api\ChargingStationController;
use App\Http\Controllers\Api\EvModelController;
use App\Http\Controllers\Api\FavoriteStationController;
use App\Http\Controllers\Api\NavigateController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\RoadEdgeController;
use App\Http\Controllers\Api\RoadNodeController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\UserNotificationController;
use App\Http\Controllers\Api\UserVehicleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::get('/ev-models', [EvModelController::class, 'index']);

Route::get('/stations', [ChargingStationController::class, 'index']);
Route::get('/stations/search-suggestions', [ChargingStationController::class, 'searchSuggestions']);
// /stations/mine must be registered before /stations/{station} - same
// route-ordering hazard as /stations/search-suggestions above and
// /trips/active vs /trips/{trip}: {station} is an implicit Eloquent-bound
// wildcard with no numeric constraint, so "mine" would otherwise be
// swallowed as an unresolvable station id. Registered here (ahead of the
// public block's {station}) even though it needs its own auth - route
// registration order determines match order regardless of which
// middleware group a route sits in.
Route::middleware(['auth:sanctum', 'role:station_owner,admin'])
    ->get('/stations/mine', [ChargingStationController::class, 'mine']);
Route::get('/stations/{station}', [ChargingStationController::class, 'show']);
Route::get('/stations/{station}/slots', [ChargingSlotController::class, 'index']);
Route::get('/stations/{station}/reviews', [ReviewController::class, 'index']);

Route::get('/road-nodes', [RoadNodeController::class, 'index']);
Route::get('/road-nodes/city-suggestions', [RoadNodeController::class, 'citySuggestions']);
Route::get('/road-edges', [RoadEdgeController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Authenticated routes (any role)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('/auth/password', [AuthController::class, 'changePassword']);
    Route::post('/auth/avatar', [AuthController::class, 'uploadAvatar']);
    Route::delete('/auth/avatar', [AuthController::class, 'deleteAvatar']);

    Route::apiResource('vehicles', UserVehicleController::class)->except(['index', 'store'])->parameters(['vehicles' => 'vehicle']);
    Route::get('/vehicles', [UserVehicleController::class, 'index']);
    Route::post('/vehicles', [UserVehicleController::class, 'store']);

    Route::get('/trips', [TripController::class, 'index']);
    Route::post('/trips', [TripController::class, 'store']);
    // /trips/active must be registered before /trips/{trip} - "active" would
    // otherwise be swallowed as an unresolvable {trip} id, the same
    // route-ordering hazard as /stations/search-suggestions vs /stations/{station}.
    Route::get('/trips/active', [TripController::class, 'active']);
    Route::get('/trips/{trip}', [TripController::class, 'show']);
    Route::post('/trips/{trip}/recalculate', [TripController::class, 'recalculate']);
    Route::get('/trips/{trip}/summary', [TripController::class, 'summary']);
    Route::post('/trips/{trip}/start', [TripController::class, 'start']);
    Route::post('/trips/{trip}/stops/{stop}/reached', [TripController::class, 'markStopReached']);
    Route::post('/trips/{trip}/complete', [TripController::class, 'complete']);
    Route::post('/trips/{trip}/cancel', [TripController::class, 'cancel']);

    Route::post('/stations/{station}/sessions', [ChargingSessionController::class, 'store']);
    Route::get('/sessions', [ChargingSessionController::class, 'index']);
    Route::put('/sessions/{session}', [ChargingSessionController::class, 'update']);

    Route::get('/favorites', [FavoriteStationController::class, 'index']);
    Route::post('/favorites', [FavoriteStationController::class, 'store']);
    Route::delete('/favorites/{stationId}', [FavoriteStationController::class, 'destroy']);

    Route::post('/stations/{station}/reviews', [ReviewController::class, 'store']);

    Route::get('/notifications', [UserNotificationController::class, 'index']);
    Route::put('/notifications/{notification}/read', [UserNotificationController::class, 'markRead']);
    Route::put('/notifications/read-all', [UserNotificationController::class, 'markAllRead']);

    Route::get('/navigate/route', [NavigateController::class, 'route']);
});

/*
|--------------------------------------------------------------------------
| Station owner routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'role:station_owner,admin'])->group(function () {
    Route::post('/stations', [ChargingStationController::class, 'store']);
    Route::put('/stations/{station}', [ChargingStationController::class, 'update']);
    Route::post('/stations/{station}/slots', [ChargingSlotController::class, 'store']);
    Route::put('/stations/{station}/slots/{slot}', [ChargingSlotController::class, 'update']);
    Route::delete('/stations/{station}/slots/{slot}', [ChargingSlotController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Admin-only routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'stats']);
    Route::get('/users', [AdminDashboardController::class, 'users']);
    Route::put('/users/{user}/status', [AdminDashboardController::class, 'updateUserStatus']);

    Route::get('/stations/pending', [AdminDashboardController::class, 'pendingStations']);
    Route::put('/stations/{station}/verify', [AdminDashboardController::class, 'verifyStation']);
    Route::delete('/stations/{station}', [ChargingStationController::class, 'destroy']);

    Route::post('/ev-models', [EvModelController::class, 'store']);
    Route::put('/ev-models/{evModel}', [EvModelController::class, 'update']);
    Route::delete('/ev-models/{evModel}', [EvModelController::class, 'destroy']);

    Route::post('/road-nodes', [RoadNodeController::class, 'store']);
    Route::put('/road-nodes/{roadNode}', [RoadNodeController::class, 'update']);
    Route::delete('/road-nodes/{roadNode}', [RoadNodeController::class, 'destroy']);

    Route::post('/road-edges', [RoadEdgeController::class, 'store']);
    Route::put('/road-edges/{roadEdge}', [RoadEdgeController::class, 'update']);
    Route::delete('/road-edges/{roadEdge}', [RoadEdgeController::class, 'destroy']);
});
