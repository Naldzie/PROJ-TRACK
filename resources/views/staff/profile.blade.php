@extends('layouts.stafflayouts')

@section('content')

@php use Illuminate\Support\Str; @endphp

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<div class="container py-5">

<style>
:root {
    --primary: #4f46e5;
    --bg: linear-gradient(135deg, #eef2ff, #f8fafc);
    --card-bg: rgba(255, 255, 255, 0.78);
    --border: #e5e7eb;
    --text-main: #111827;
    --text-muted: #6b7280;
}

body {
    background: var(--bg);
}

.profile-container {
    max-width: 1050px;
    margin: auto;
}

.card-box {
    background: var(--card-bg);
    border-radius: 30px;
    padding: 50px;
    backdrop-filter: blur(18px);
    box-shadow: 0 30px 70px rgba(0,0,0,0.08);
    border: 1px solid rgba(255,255,255,0.6);
}

.left-panel {
    border-right: 1px solid var(--border);
}

/* Avatar */
.profile-avatar {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    background-size: cover;
    background-position: center;
    border: 6px solid #fff;
    box-shadow: 0 15px 35px rgba(0,0,0,0.2);
    margin-bottom: 15px;
}

/* Name */
.profile-name {
    font-size: 20px;
    font-weight: 700;
}

.profile-email {
    font-size: 13px;
    color: var(--text-muted);
}

/* Section title */
.section-title {
    font-weight: 600;
    margin-bottom: 15px;
}

/* Inputs */
.form-floating input {
    border-radius: 12px;
}

/* Button */
.btn-primary {
    background: linear-gradient(to right, #4f46e5, #3730a3);
    border: none;
    border-radius: 12px;
    padding: 12px;
    font-weight: 600;
}

/* Google */
.btn-google {
    background: #fff;
    border: 1.5px solid #dadce0;
    border-radius: 12px;
    padding: 12px;
    display: flex;
    justify-content: center;
    gap: 10px;
    text-decoration: none;
    color: #444;
}
.btn-google {
    background: #fff;
    border: 1px solid #dadce0;
    border-radius: 12px;
    padding: 12px 16px;
    font-weight: 500;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    text-decoration: none;
    color: #3c4043;
    transition: all 0.25s ease;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}

/* Hover */
.btn-google:hover {
    background: #f8f9fa;
    box-shadow: 0 6px 18px rgba(0,0,0,0.12);
    transform: translateY(-2px);
}

/* Click effect */
.btn-google:active {
    transform: scale(0.97);
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

/* Icon */
.google-logo {
    width: 20px;
    height: 20px;
}
</style>

<div class="profile-container">
<div class="card-box">

    <!-- HEADER -->
    <div class="text-center mb-5">
        <h2 class="fw-bold">Profile Settings</h2>
        <small class="text-muted">Manage your personal information</small>
    </div>

    <div class="row">

        <!-- LEFT (ONLY IDENTITY) -->
        <div class="col-md-5 text-center left-panel">

            <div class="profile-avatar mx-auto"
                style="background-image: url('{{ 
                    $user->profile_photo 
                        ? (Str::startsWith($user->profile_photo, 'http') 
                            ? $user->profile_photo 
                            : asset('storage/' . $user->profile_photo)) 
                        : asset('images/default.png') 
                }}');">
            </div>

            <div class="profile-name">
                {{ $staff->first_name ?? 'N/A' }} {{ $staff->last_name ?? '' }}
            </div>

            <div class="profile-email">
                {{ $user->email }}
            </div>

        </div>

        <!-- RIGHT (EDIT ONLY) -->
        <div class="col-md-7">

            <div class="section-title">Edit Information</div>

            <form action="{{ route('staff.profile.update') }}" method="POST">
                @csrf

                <div class="form-floating mb-3">
                    <input type="text" name="profession"
                        class="form-control"
                        placeholder="Profession"
                        value="{{ old('profession', $staff->profession) }}">
                    <label>Profession</label>
                </div>

<div class="form-floating mb-4">
    <input type="text" name="contact_number"
        class="form-control"
        placeholder="Contact Number"
        value="{{ old('contact_number', $staff->contact_number) }}"
        inputmode="numeric"
        oninput="this.value = this.value.replace(/[^0-9]/g, '')">

    <label>Contact Number</label>
</div>

            <button type="submit" class="btn btn-primary w-100 mb-3">
                <i class="bi bi-check-circle me-1"></i> Save Changes
            </button>

            </form>

            <!-- GOOGLE -->
<!-- GOOGLE BUTTON -->
<div class="mt-2">
    <a href="https://myaccount.google.com/" target="_blank" class="btn-google">
        <svg class="google-logo" viewBox="0 0 48 48">
            <path fill="#EA4335" d="M24 9.5c3.54 0 6.73 1.22 9.24 3.6l6.9-6.9C35.9 2.1 30.37 0 24 0 14.61 0 6.46 5.38 2.44 13.24l8.04 6.24C12.64 13.22 17.9 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.5 24.5c0-1.54-.14-3.02-.4-4.44H24v8.42h12.7c-.55 2.96-2.2 5.46-4.68 7.14l7.2 5.6c4.2-3.88 6.28-9.6 6.28-16.72z"/>
            <path fill="#FBBC05" d="M10.48 28.48A14.5 14.5 0 0 1 9.5 24c0-1.55.27-3.05.76-4.44l-8.04-6.24A23.93 23.93 0 0 0 0 24c0 3.82.92 7.44 2.56 10.68l7.92-6.2z"/>
            <path fill="#34A853" d="M24 48c6.37 0 11.72-2.1 15.63-5.72l-7.2-5.6c-2 1.34-4.56 2.14-8.43 2.14-6.1 0-11.36-3.72-13.52-8.98l-7.92 6.2C6.46 42.62 14.61 48 24 48z"/>
        </svg>
        <span>Manage Google Account</span>
    </a>
</div>

        </div>

    </div>

</div>
</div>

</div>

@endsection