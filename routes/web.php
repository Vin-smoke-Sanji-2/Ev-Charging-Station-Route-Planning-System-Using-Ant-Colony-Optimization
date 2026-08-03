<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', function () {
        return view('auth.login');
    })->name('login');

    Route::get('/register', function () {
        return view('auth.register');
    })->name('register');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/trips/plan', function () {
        return view('trips.plan');
    })->name('trips.plan');

    Route::get('/trips/history', function () {
        return view('coming-soon', ['title' => 'Trip History', 'icon' => 'clock-history']);
    })->name('trips.history');

    Route::get('/trips/live', function () {
        return view('coming-soon', ['title' => 'Live Trip', 'icon' => 'broadcast']);
    })->name('trips.live');

    Route::get('/trips/{trip}', function (int $trip) {
        return view('trips.show', ['tripId' => $trip]);
    })->name('trips.show');

    Route::get('/stations', function () {
        return view('coming-soon', ['title' => 'Stations', 'icon' => 'ev-station']);
    })->name('stations.index');

    Route::get('/vehicles', function () {
        return view('coming-soon', ['title' => 'My EVs', 'icon' => 'car-front']);
    })->name('vehicles.index');

    Route::get('/favorites', function () {
        return view('coming-soon', ['title' => 'Favorites', 'icon' => 'heart']);
    })->name('favorites.index');

    Route::get('/notifications', function () {
        return view('coming-soon', ['title' => 'Notifications', 'icon' => 'bell']);
    })->name('notifications.index');

    Route::get('/profile', function () {
        return view('coming-soon', ['title' => 'Profile', 'icon' => 'person']);
    })->name('profile');
});
