@extends('layouts.guest')

@section('title', 'Log in - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/login.js'])
@endpush

@section('content')
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <i class="bi bi-ev-station-fill fs-1 text-brand"></i>
                <h4 class="mt-2 mb-0">Welcome back</h4>
                <p class="text-muted small">Log in to plan your next EV trip.</p>
            </div>

            <div id="form-error" class="alert alert-danger d-none" role="alert"></div>

            <form id="login-form" novalidate>
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                    <div class="invalid-feedback" data-error-for="email"></div>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                    <div class="invalid-feedback" data-error-for="password"></div>
                </div>
                <button type="submit" class="btn btn-primary w-100" id="login-submit">Log in</button>
            </form>

            <p class="text-center text-muted small mt-3 mb-0">
                Don't have an account? <a href="{{ route('register') }}">Sign up</a>
            </p>
        </div>
    </div>
@endsection
