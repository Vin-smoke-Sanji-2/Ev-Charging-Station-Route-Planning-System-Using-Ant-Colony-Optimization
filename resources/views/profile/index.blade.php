@extends('layouts.app')

@section('title', 'Profile - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/profile-index.js'])
@endpush

@section('content')
    <h2 class="mb-1">Profile</h2>
    <p class="text-muted">Manage your account details.</p>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <h5 class="card-title text-start">Photo</h5>

                    <div id="avatar-error" class="alert alert-danger d-none text-start small" role="alert"></div>

                    <img id="avatar-preview" src="" alt="Your avatar"
                         class="rounded-circle d-none" style="width: 140px; height: 140px; object-fit: cover;">
                    <div id="avatar-placeholder" class="rounded-circle bg-brand text-white d-flex align-items-center justify-content-center mx-auto"
                         style="width: 140px; height: 140px;">
                        <i class="bi bi-person-fill" style="font-size: 4rem;"></i>
                    </div>

                    <div class="mt-3 d-flex flex-column align-items-center gap-3">
                        <input type="file" id="avatar-input" accept="image/jpeg,image/png,image/webp" class="d-none">
                        <button type="button" class="btn btn-secondary btn-sm" id="upload-avatar-btn">
                            <i class="bi bi-upload"></i> Upload Photo
                        </button>
                        <button type="button" class="btn btn-icon-circle btn-icon-circle--danger d-none" id="remove-avatar-btn"
                                aria-label="Remove photo">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h5 class="card-title">Profile Info</h5>

                    <div id="profile-form-error" class="alert alert-danger d-none" role="alert"></div>
                    <div id="profile-form-success" class="alert alert-success d-none" role="alert">Profile updated.</div>

                    <form id="profile-form" novalidate>
                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                            <div class="invalid-feedback" data-error-for="name"></div>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" disabled>
                            <div class="form-text">Email can't be changed here.</div>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone <span class="text-muted">(optional)</span></label>
                            <input type="text" class="form-control" id="phone" name="phone">
                            <div class="invalid-feedback" data-error-for="phone"></div>
                        </div>
                        <button type="submit" class="btn btn-primary" id="profile-form-submit">Save Changes</button>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Change Password</h5>

                    <div id="password-form-error" class="alert alert-danger d-none" role="alert"></div>
                    <div id="password-form-success" class="alert alert-success d-none" role="alert">Password updated.</div>

                    <form id="password-form" novalidate>
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                            <div class="invalid-feedback" data-error-for="current_password"></div>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">New password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                            <div class="invalid-feedback" data-error-for="password"></div>
                        </div>
                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">Confirm new password</label>
                            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
                        </div>
                        <button type="submit" class="btn btn-primary" id="password-form-submit">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
