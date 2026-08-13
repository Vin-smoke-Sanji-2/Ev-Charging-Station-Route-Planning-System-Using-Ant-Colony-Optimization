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
                    <div class="input-group has-validation">
                        <input type="password" class="form-control" id="password" name="password" required>
                        <button type="button" class="input-group-text password-toggle-btn" data-target="password" aria-label="Show password">
                            <i class="bi bi-eye"></i>
                        </button>
                        <div class="invalid-feedback" data-error-for="password"></div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100" id="login-submit">Log in</button>
            </form>

            <p class="text-center text-muted small mt-3 mb-0" id="login-signup-hint">
                Don't have an account? <a href="{{ route('register') }}">Sign up</a>
            </p>

            <!-- Step 2, shown only for roles that require an OTP (see
                 AuthController::OTP_REQUIRED_ROLES) - hidden until the
                 password step above responds with otp_required: true. -->
            <form id="otp-form" class="d-none" novalidate>
                <div class="text-center mb-3">
                    <i class="bi bi-envelope-check fs-2 text-brand"></i>
                    <p class="text-muted small mb-0 mt-2">
                        We emailed a 6-digit code to <strong id="otp-email-display"></strong>.
                        Enter it below to finish logging in.
                    </p>
                </div>
                <input type="hidden" id="otp-email" name="email">
                <div class="mb-3">
                    <label for="otp-code" class="form-label">Login code</label>
                    <input type="text" class="form-control text-center" id="otp-code" name="code"
                           inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required>
                    <div class="invalid-feedback" data-error-for="code"></div>
                </div>
                <button type="submit" class="btn btn-primary w-100" id="otp-submit">Verify code</button>
                <div class="d-flex justify-content-between mt-3">
                    <button type="button" class="btn btn-link p-0 small" id="otp-back-btn">Back to login</button>
                    <button type="button" class="btn btn-link p-0 small" id="otp-resend-btn">Resend code</button>
                </div>
            </form>
        </div>
    </div>
@endsection
