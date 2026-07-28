@extends('layouts.app')

@section('content')

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<div class="container py-5">

    <style>
    :root {
    --primary: #2563eb;
    --bg: linear-gradient(135deg, #eef2ff, #f8fafc);
    --card-bg: rgba(255, 255, 255, 0.85);
    --border: #e5e7eb;
    --text-main: #1f2937;
    --text-muted: #6b7280;
}

/* Background */
body {
    background: var(--bg);
    min-height: 100vh;
}

/* Container */
.profile-container {
    max-width: 760px;
    margin: auto;
}

/* Card */
.card-box {
    background: var(--card-bg);
    border-radius: 24px;
    padding: 45px;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);

    /* Stronger shadow */
    box-shadow: 
        0 20px 40px rgba(0,0,0,0.08),
        0 8px 16px rgba(0,0,0,0.05);

    border: 1px solid rgba(255,255,255,0.6);
    transition: 0.3s ease;
}

.card-box:hover {
    transform: translateY(-3px);
    box-shadow: 
        0 25px 50px rgba(0,0,0,0.12),
        0 10px 20px rgba(0,0,0,0.06);
}

/* Profile Image */
.profile-img-admin {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background-size: cover;
    background-position: center;

    border: 4px solid #fff;

    box-shadow: 
        0 6px 20px rgba(0,0,0,0.15),
        inset 0 0 0 2px rgba(255,255,255,0.6);

    margin-bottom: 20px;
}

/* Info box */
.info-box {
    background: #f9fafb;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 15px 18px;
    margin-bottom: 12px;
    transition: all 0.25s ease;
}

.info-box:hover {
    background: #f3f4f6;
    transform: translateX(3px);
}

/* Labels */
.label {
    font-size: 12px;
    color: var(--text-muted);
}

.value {
    font-weight: 600;
    color: var(--text-main);
}

/* 🔥 Enhanced Google Button */
.btn-google {
    background: #ffffff;
    color: #444;

    border: 1.5px solid #dadce0; /* stronger stroke */
    border-radius: 12px;

    padding: 12px 22px;
    font-weight: 600;

    display: inline-flex;
    align-items: center;
    gap: 10px;

    text-decoration: none;

    /* More visible shadow */
    box-shadow: 
        0 4px 10px rgba(0,0,0,0.08),
        0 2px 4px rgba(0,0,0,0.06);

    transition: all 0.25s ease;
}

/* Hover effect */
.btn-google:hover {
    background: #f8fafc;

    border-color: #c7c9cc;

    transform: translateY(-2px);

    box-shadow: 
        0 8px 20px rgba(0,0,0,0.12),
        0 4px 8px rgba(0,0,0,0.08);
}

/* Click effect */
.btn-google:active {
    transform: scale(0.98);
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

/* Google logo */
.google-logo {
    width: 20px;
    height: 20px;
}

    </style>

    <div class="profile-container">
        <div class="card-box text-center">

            <!-- HEADER -->
            <div class="mb-4">
                <h3 class="section-title">Profile Information</h3>
                <small class="section-subtitle">
                    Your account is managed via Google
                </small>
            </div>

            <!-- PROFILE IMAGE -->
<div class="profile-img-admin mx-auto"
     style="background-image: url('{{ 
        auth()->user()->profile_photo 
            ? auth()->user()->profile_photo 
            : asset('images/default-profile.png') 
     }}');">
</div>

            <!-- USER INFO -->
            <div class="text-start mt-4">
                <div class="info-box">
                    <div class="label">Full Name</div>
                    <div class="value">{{ $user->admin->full_name ?? 'N/A' }}</div>
                </div>

                <div class="info-box">
                    <div class="label">Email</div>
                    <div class="value">{{ $user->email }}</div>
                </div>
            </div>

            <!-- GOOGLE BUTTON -->
            <div class="mt-4">
                <a href="https://myaccount.google.com/" target="_blank" class="btn-google">
                    
                    <!-- Official Google "G" SVG -->
                    <svg class="google-logo" viewBox="0 0 48 48">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.73 1.22 9.24 3.6l6.9-6.9C35.9 2.1 30.37 0 24 0 14.61 0 6.46 5.38 2.44 13.24l8.04 6.24C12.64 13.22 17.9 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.5 24.5c0-1.54-.14-3.02-.4-4.44H24v8.42h12.7c-.55 2.96-2.2 5.46-4.68 7.14l7.2 5.6c4.2-3.88 6.28-9.6 6.28-16.72z"/>
                        <path fill="#FBBC05" d="M10.48 28.48A14.5 14.5 0 0 1 9.5 24c0-1.55.27-3.05.76-4.44l-8.04-6.24A23.93 23.93 0 0 0 0 24c0 3.82.92 7.44 2.56 10.68l7.92-6.2z"/>
                        <path fill="#34A853" d="M24 48c6.37 0 11.72-2.1 15.63-5.72l-7.2-5.6c-2 1.34-4.56 2.14-8.43 2.14-6.1 0-11.36-3.72-13.52-8.98l-7.92 6.2C6.46 42.62 14.61 48 24 48z"/>
                    </svg>

                    Manage Google Account
                </a>
            </div>

        </div>
    </div>

</div>

@endsection