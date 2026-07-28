@extends('layouts.contractorlayouts')

@section('content')

@php use Illuminate\Support\Str; @endphp

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<div class="container py-5">

<style>
:root {
    --primary: #4f46e5;
    --primary-dark: #3730a3;
    --bg: linear-gradient(135deg, #eef2ff, #f8fafc);
    --card-bg: rgba(255, 255, 255, 0.85);
    --border: #e5e7eb;
    --text-main: #111827;
    --text-muted: #6b7280;
}

/* BACKGROUND */
body {
    background: var(--bg);
}

/* MAIN CONTAINER */
.profile-container {
    max-width: 1100px;
    margin: auto;
}

/* CARD */
.card-box {
    background: var(--card-bg);
    border-radius: 30px;
    padding: 50px;
    backdrop-filter: blur(20px);
    box-shadow: 0 30px 80px rgba(0,0,0,0.08);
    border: 1px solid rgba(255,255,255,0.7);
    transition: all 0.3s ease;
}

.card-box:hover {
    transform: translateY(-5px);
    box-shadow: 0 35px 90px rgba(0,0,0,0.12);
}

/* LEFT PANEL */
.left-panel {
    border-right: 1px solid var(--border);
}

/* AVATAR */
.profile-avatar {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    background-size: cover;
    background-position: center;
    border: 5px solid #fff;
    box-shadow: 0 15px 40px rgba(0,0,0,0.25);
    margin-bottom: 15px;
    transition: all 0.3s ease;
}

.profile-avatar:hover {
    transform: scale(1.06);
}

/* TEXT */
.profile-email {
    font-size: 13px;
    color: var(--text-muted);
}

.profile-name {
    font-weight: 800;
    font-size: 20px;
    color: #111827;
    letter-spacing: 0.3px;
}

.google-label {
    font-size: 12px;
    color: var(--text-muted);
}

/* HEADER */
.header-title {
    font-size: 28px;
    font-weight: 700;
    color: var(--text-main);
}

.header-sub {
    font-size: 14px;
    color: var(--text-muted);
}

/* SECTION TITLE */
.section-title {
    font-weight: 600;
    margin-bottom: 20px;
    font-size: 15px;
    color: var(--text-main);
    position: relative;
}

.section-title::after {
    content: '';
    width: 40px;
    height: 3px;
    background: var(--primary);
    position: absolute;
    bottom: -6px;
    left: 0;
    border-radius: 10px;
}

/* INPUTS */
.form-floating input {
    border-radius: 16px;
    border: 1px solid #e5e7eb;
    padding: 14px;
    font-size: 14px;
}

.form-floating input:focus {
    border-color: #4f46e5;
    box-shadow: 0 0 0 4px rgba(79,70,229,0.12);
}
/* BUTTON */
.btn-primary {
    background: linear-gradient(135deg, #4f46e5, #6366f1);
    border: none;
    border-radius: 16px;
    padding: 14px;
    font-weight: 700;
    font-size: 15px;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 40px rgba(79,70,229,0.35);
}

/* GOOGLE BUTTON */
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
/* MOBILE */
@media(max-width: 768px){
    .left-panel {
        border-right: none;
        border-bottom: 1px solid var(--border);
        margin-bottom: 25px;
        padding-bottom: 25px;
    }
}
</style>

<div class="profile-container">
<div class="card-box">

    <!-- HEADER -->
    <div class="text-center mb-5">
        <div class="header-title">Profile Settings</div>
        <div class="header-sub">Manage your contractor information</div>
    </div>

   <div class="row align-items-stretch">

        <!-- LEFT -->
        <div class="col-md-5 text-center left-panel d-flex flex-column justify-content-center">

    <div class="profile-avatar mx-auto mb-3"
        style="background-image: url('{{ 
            $user->profile_photo 
                ? (Str::startsWith($user->profile_photo, 'http') 
                    ? $user->profile_photo 
                    : asset('storage/' . $user->profile_photo)) 
                : asset('images/default.png') 
        }}');">
    </div>

    <div class="profile-name">
        {{ $contractor->contractor_name ?? $user->name ?? 'Contractor Name' }}
    </div>

    <div class="profile-email mb-2">
        {{ $user->email }}
    </div>

</div>

        <!-- RIGHT -->
        <div class="col-md-7">

            <div class="section-title">Edit Information</div>

            <form action="{{ route('contractor.profile.update') }}" method="POST">
                @csrf

                <div class="form-floating mb-3">
                    <input type="text" name="company_name" class="form-control"
                        placeholder="Company Name"
                        value="{{ old('company_name', $contractor->company_name) }}">
                    <label>Company Name</label>
                </div>

                <div class="form-floating mb-3">
                    <input type="text" name="contact_number" class="form-control"
                        placeholder="Contact Number"
                        value="{{ old('contact_number', $contractor->contact_number) }}"
                        inputmode="numeric"
                        oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    <label>Contact Number</label>
                </div>

                <div class="form-floating mb-3">
                    <input type="text" name="company_landline" class="form-control"
                        placeholder="Company Landline"
                        value="{{ old('company_landline', $contractor->company_landline) }}"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    <label>Company Landline</label>
                </div>

                <div class="form-floating mb-4">
                    <input type="text" name="company_address" class="form-control"
                        placeholder="Company Address"
                        value="{{ old('company_address', $contractor->company_address) }}">
                    <label>Company Address</label>
                </div>

                <button type="submit" class="btn btn-primary w-100 mb-3">
                    <i class="bi bi-check-circle me-1"></i> Save Changes
                </button>

            </form>

            <!-- GOOGLE -->
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