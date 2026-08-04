<!DOCTYPE html>
<html>
<head>
    <title>Staff</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    {{--  DIRECT CSS--}}
    <link rel="stylesheet" href="{{ asset('css/dashboard/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard/dashboard-widgets.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/projects/project-detail.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/projects/projects.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/projects/_create_project_modal.css') }}">

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #eef1f7;
            font-family: 'Segoe UI', sans-serif;
        }

        /* ===== SIDEBAR ===== */
        .sidebar {
            width: 260px;
            height: 100vh;
            background: linear-gradient(160deg, #1a2540 0%, #1e3a8a 100%);
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
            overflow-y: auto;
        }

        .sidebar-brand {
            padding: 24px 20px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 8px;
        }

        .sidebar-brand .brand-title {
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            letter-spacing: 0.5px;
            line-height: 1.3;
        }

        .sidebar-brand .brand-sub {
            font-size: 11px;
            color: rgba(255,255,255,0.5);
            margin-top: 3px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .nav-section-label {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.3);
            padding: 12px 22px 4px;
        }

        .sidebar a {
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            padding: 10px 14px;
            margin: 2px 10px;
            display: flex;
            align-items: center;
            gap: 11px;
            border-radius: 9px;
            font-size: 13.5px;
            transition: background 0.2s, color 0.2s;
        }

        .sidebar a i {
            font-size: 16px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        .sidebar a:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }

        .sidebar a.active {
            background: rgba(255,255,255,0.15);
            color: #fff;
            font-weight: 600;
        }

        .sidebar a.active i {
            color: #60a5fa;
        }

        .sidebar-footer {
            margin-top: auto;
            padding: 16px 12px;
            border-top: 1px solid rgba(255,255,255,0.08);
        }

        .sidebar-footer .btn-logout {
            width: 100%;
            background: rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.85);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 9px;
            padding: 9px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            transition: background 0.2s, color 0.2s;
        }

        .sidebar-footer .btn-logout:hover {
            background: rgba(255,255,255,0.18);
            color: #fff;
        }

        /* ===== TOPBAR ===== */
        .topbar {
            margin-left: 260px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 30px;
            position: relative;
            background-image: url("{{ asset('images/profile-bg.jpg') }}");
            background-size: cover;
            background-position: center;
        }

        .topbar::before {
            content: "";
            position: absolute;
            inset: 0;
            background: rgba(10, 20, 50, 0.52);
        }

        .topbar > * {
            position: relative;
            z-index: 2;
        }

        /* ===== PROFILE SECTION ===== */
        .profile-section {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .profile-info {
            text-align: right;
        }

        .profile-info .name {
            font-size: 14px;
            font-weight: 600;
            color: #fff;
            line-height: 1.3;
        }

        .profile-info .role-badge {
            display: inline-block;
            font-size: 11px;
            background: rgba(96,165,250,0.25);
            color: #93c5fd;
            border: 1px solid rgba(96,165,250,0.35);
            border-radius: 20px;
            padding: 1px 9px;
            margin-top: 3px;
            letter-spacing: 0.3px;
        }

        .profiless-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            border: 2px solid rgba(255,255,255,0.6);
            box-shadow: 0 0 0 3px rgba(255,255,255,0.15);
            flex-shrink: 0;
        }

        /* ===== CONTENT ===== */
        .content {
            margin-left: 260px;
            padding: 28px 30px;
            min-height: calc(100vh - 80px);
        }

        .card-box {
            background: white;
            padding: 24px;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-title">PROJ-TRACK SYSTEM</div>
        <div class="brand-sub">CSPC &mdash; PMU</div>
    </div>

    <span class="nav-section-label">Main</span>

    <a href="{{ route('staff.dashboard') }}"
       class="{{ request()->routeIs('staff.dashboard') ? 'active' : '' }}">
        <i class="bi bi-speedometer2"></i> Dashboard
    </a>

    <a href="{{ route('staff.projects') }}"
       class="{{ request()->routeIs('staff.projects') ? 'active' : '' }}">
        <i class="bi bi-folder2-open"></i> Projects
    </a>

    <a href="{{ route('staff.email') }}"
       class="{{ request()->routeIs('staff.email') ? 'active' : '' }}">
        <i class="bi bi-envelope"></i> Email
    </a>

    <a href="{{ route('staff.warranty') }}"
       class="{{ request()->routeIs('staff.warranty') ? 'active' : '' }}">
        <i class="bi bi-shield-check"></i> Warranty
    </a>

    <span class="nav-section-label">Account</span>

    <a href="{{ route('staff.profile') }}"
       class="{{ request()->routeIs('staff.profile') ? 'active' : '' }}">
        <i class="bi bi-person-circle"></i> My Profile
    </a>

    <div class="sidebar-footer">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn-logout">
                <i class="bi bi-box-arrow-right"></i> Logout
            </button>
        </form>
    </div>
</div>

<!-- TOPBAR -->
<div class="topbar">
    <div class="profile-section">
        @include('notifications.partials.bell')
        <div class="profile-info">
            <div class="name">
                {{ auth()->user()->staff->first_name ?? 'No Name' }}
                {{ auth()->user()->staff->last_name ?? '' }}
            </div>
            <span class="role-badge">{{ ucfirst(auth()->user()->role) }}</span>
        </div>
        <div class="profiless-avatar"
             style="background-image: url('{{ 
                auth()->user()->profile_photo 
                    ? (Str::startsWith(auth()->user()->profile_photo, 'http') 
                        ? auth()->user()->profile_photo 
                        : asset('storage/' . auth()->user()->profile_photo)) 
                    : asset('images/default-profile.png') 
             }}');">
        </div>
    </div>
</div>

<!-- CONTENT -->
<div class="content">
    @yield('content')
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/raphael@2.1.4/raphael.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/justgage/justgage.min.js"></script>

@if(session('success'))
<script>
    Swal.fire({
        icon: 'success',
        title: 'Success',
        text: "{{ session('success') }}",
        confirmButtonColor: '#2563eb'
    });
</script>
@endif

@if($errors->any())
<script>
    Swal.fire({
        icon: 'error',
        title: 'Oops...',
        html: `{!! implode('<br>', $errors->all()) !!}`,
        confirmButtonColor: '#d33'
    });
</script>
@endif

@php
    $staff = auth()->user()->staff ?? null;
    $isIncomplete =
        !$staff ||
        !$staff->first_name ||
        !$staff->last_name ||
        !$staff->profession ||
        !$staff->contact_number;
@endphp

@if($isIncomplete && request()->path() !== 'staff/profile')
<style>
.swal2-container {
    backdrop-filter: blur(8px) !important;
    background: rgba(0, 0, 0, 0.4) !important;
}
.swal2-popup {
    border-radius: 20px !important;
    padding: 30px !important;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2) !important;
    font-family: 'Segoe UI', sans-serif;
}
.swal2-title {
    font-size: 22px !important;
    font-weight: 700 !important;
    color: #111827 !important;
}
.swal2-html-container {
    font-size: 14px !important;
    color: #6b7280 !important;
}
.swal2-confirm {
    background: linear-gradient(to right, #2563eb, #1e3a8a) !important;
    border-radius: 10px !important;
    padding: 10px 20px !important;
    font-weight: 600 !important;
    transition: 0.2s;
}
.swal2-confirm:hover {
    transform: translateY(-1px) scale(1.04);
    box-shadow: 0 6px 18px rgba(37,99,235,0.4);
}
</style>

<script>
Swal.fire({
    icon: 'warning',
    title: 'Complete Your Profile',
    html: `
        <div style="line-height:1.6">
            Please complete your profile details to continue.<br>
            <small style="color:#9ca3af">Required fields must be filled.</small>
        </div>
    `,
    confirmButtonText: 'Go to Profile',
    allowOutsideClick: false,
    allowEscapeKey: false,
    backdrop: true,
    showClass: { popup: 'animate__animated animate__fadeInDown' },
    hideClass: { popup: 'animate__animated animate__fadeOutUp' }
}).then((result) => {
    if (result.isConfirmed) {
        window.location.href = "{{ route('staff.profile') }}";
    }
});
</script>
@endif
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"></script>

<!-- PDF.js — used by document-parser.js for autofill -->
    {{-- import() requires type="module" — without it the browser throws a silent syntax error --}}
    <script type="module">
        import * as pdfjsLib from 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.4.168/build/pdf.min.mjs';
        pdfjsLib.GlobalWorkerOptions.workerSrc =
            'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.4.168/build/pdf.worker.min.mjs';
        // Expose as a global so non-module scripts (document-parser.js) can access it
        window.pdfjsLib = pdfjsLib;
    </script>
    
{{-- FORM VALIDATOR JS FUNCTION(CREATE/EDIT FORM VALIDATION) --}}
<script src="{{ asset('js/admin/projects/field-validation.js') }}"></script>
{{-- CREATE/EDIT PROJECTS MODAL MULTI PAGE FORM NAVIGATION JS FUNCTION --}}
<script src="{{ asset('js/admin/projects/form-navigation.js') }}"></script>

{{-- GLOBAL TOAST NOTIFICATION --}}
@include('components.toast')
</body>
</html>