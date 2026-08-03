@extends('layouts.guest')

@section('title', 'Sign up - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/register.js'])
@endpush

@section('content')
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <i class="bi bi-ev-station-fill fs-1 text-brand"></i>
                <h4 class="mt-2 mb-0">Create your account</h4>
                <p class="text-muted small">Sign up to start planning EV road trips.</p>
            </div>

            <div id="form-error" class="alert alert-danger d-none" role="alert"></div>

            <form id="register-form" novalidate>
                <div class="mb-3">
                    <label for="name" class="form-label">Full name</label>
                    <input type="text" class="form-control" id="name" name="name" required>
                    <div class="invalid-feedback" data-error-for="name"></div>
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                    <div class="invalid-feedback" data-error-for="email"></div>
                </div>
                <div class="mb-3">
                    <label for="phone" class="form-label">Phone <span class="text-muted">(optional)</span></label>
                    <input type="text" class="form-control" id="phone" name="phone">
                    <div class="invalid-feedback" data-error-for="phone"></div>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                    <div class="invalid-feedback" data-error-for="password"></div>
                </div>
                <div class="mb-3">
                    <label for="password_confirmation" class="form-label">Confirm password</label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
                </div>
                <button type="submit" class="btn btn-primary w-100" id="register-submit">Sign up</button>
            </form>

            <p class="text-center text-muted small mt-3 mb-0">
                Already have an account? <a href="{{ route('login') }}">Log in</a>
            </p>
        </div>
    </div>
@endsection
