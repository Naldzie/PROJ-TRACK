<!DOCTYPE html>
<html>
<head>
    <title>Admin</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('faviconpmu.png') }}?v=5">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    {{--  DIRECT CSS--}}
    <link rel="stylesheet" href="{{ asset('css/dashboard/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard/dashboard-widgets.css') }}?v=20260921f">
    <link rel="stylesheet" href="{{ asset('css/dashboard/timeline.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/projects/project-detail.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/projects/projects.css') }}">
    {{-- Versioned by mtime: this file changes with the auto-fill preview layout, and an unversioned stylesheet is the documented "browser serves a ghost" incident. --}}
    <link rel="stylesheet" href="{{ asset('css/admin/projects/_create_project_modal.css') }}?v={{ filemtime(public_path('css/admin/projects/_create_project_modal.css')) }}">
    {{-- Review Learned Keywords modal (included by this layout); mtime-versioned like the line above. --}}
    <link rel="stylesheet" href="{{ asset('css/admin/projects/parser-keywords-modal.css') }}?v={{ filemtime(public_path('css/admin/projects/parser-keywords-modal.css')) }}">
    {{-- Review S-Curve Layouts modal (included by this layout); mtime-versioned like the lines above. --}}
    <link rel="stylesheet" href="{{ asset('css/admin/projects/scurve-layouts-modal.css') }}?v={{ filemtime(public_path('css/admin/projects/scurve-layouts-modal.css')) }}">
    {{-- PRELOADER --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Non-blocking font load: keeps Google Fonts from delaying first paint (no more unstyled flash on refresh) --}}
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500&family=Inter:wght@400;500;600&display=swap">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'" data-pm-font>
    <noscript><link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet"></noscript>
    {{-- Fallback: if the async font stylesheet's onload never fires (e.g. served from cache), force it on. No visual change otherwise. --}}
    <script>setTimeout(function(){try{var l=document.querySelector('link[data-pm-font]');if(l&&l.media!=='all'){l.media='all';}}catch(e){}},2500);</script>
    <link rel="stylesheet" href="{{ asset('css/components/preloader.css') }}">

    {{-- VITE CSS 
     @vite(['resources/css/app.css', 'resources/js/app.js'])
     --}}
    <!-- Leaflet Map Library -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.css" />
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.4.1/dist/MarkerCluster.css" />
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css" />

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
    <link rel="stylesheet" href="{{ asset('css/layouts/sidebar.css') }}">
    <link rel="stylesheet" href="{{ asset('css/layouts/sweetalert-theme.css') }}">
</head>
<body>

@include('components.preloader')

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-mark"><img src="{{ asset('faviconpmu.png') }}" alt="PROJ-TRACK logo"></div>
        <div>
            <div class="brand-title">PROJ-TRACK SYSTEM</div>
            <div class="brand-sub">CSPC &mdash; PMU</div>
        </div>
    </div>

    <span class="nav-section-label">Main</span>

    <a href="{{ route('admin.dashboard') }}"
       class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
        <i class="bi bi-speedometer2"></i> Dashboard
    </a>

    <a href="{{ route('admin.projects') }}"
       class="{{ request()->routeIs('admin.projects') ? 'active' : '' }}">
        <i class="bi bi-folder2-open"></i> Projects
    </a>

    <a href="{{ route('admin.warranty') }}"
       class="{{ request()->routeIs('admin.warranty') ? 'active' : '' }}">
        <i class="bi bi-shield-check"></i> Warranty
    </a>

    <a href="{{ route('admin.email') }}"
       class="{{ request()->routeIs('admin.email') ? 'active' : '' }}">
        <i class="bi bi-envelope"></i> Email
    </a>

    <span class="nav-section-label">People</span>

    <a href="{{ route('contractors.index') }}"
       class="{{ request()->routeIs('contractors.index') ? 'active' : '' }}">
        <i class="bi bi-people"></i> Contractors
    </a>

    <a href="{{ route('admin.staff.index') }}"
       class="{{ request()->routeIs('admin.staff.*') ? 'active' : '' }}">
        <i class="bi bi-person-badge"></i> PMU Staff
    </a>

    <a href="{{ route('admin.rating') }}"
       class="{{ request()->routeIs('admin.rating') ? 'active' : '' }}">
        <i class="bi bi-star"></i> Contractor Ratings
    </a>

    <span class="nav-section-label">System</span>

    <a href="{{ route('admin.activity.logs') }}"
       class="{{ request()->routeIs('admin.activity.logs') ? 'active' : '' }}">
        <i class="bi bi-clock-history"></i> Activity Logs
    </a>

    {{-- L2 learned-keywords review desk (modal launcher — no dedicated page) --}}
    <a href="javascript:void(0)" onclick="openManageParserKeywords(); return false;" title="Review wording the document reader learned from corrections">
        <i class="bi bi-magic"></i> Learned Keywords
    </a>

    {{-- E1-2 S-curve layouts review desk (modal launcher — no dedicated page) --}}
    <a href="javascript:void(0)" onclick="openManageScurveHeaders(); return false;" title="Review S-curve header layouts captured from failed imports">
        <i class="bi bi-table"></i> S-Curve Layouts
    </a>

    <a href="{{ route('admin.profile') }}"
       class="{{ request()->routeIs('admin.profile') ? 'active' : '' }}">
        <i class="bi bi-gear"></i> Admin Profile
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
    <button type="button" id="sidebarToggle" class="sidebar-toggle" aria-label="Toggle navigation" aria-expanded="true" title="Toggle navigation">
        <i class="bi bi-list"></i>
    </button>
    <div class="profile-section">
        @include('notifications.partials.bell')
        <div class="profile-info">
            <div class="name">{{ auth()->user()->admin->full_name ?? auth()->user()->email }}</div>
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

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"></script>

<!-- PDF.js — used by document-parser.js for autofill (multi-CDN fallback loader) -->
<script src="{{ asset('js/vendor/pdfjs-loader.js') . '?v=' . filemtime(public_path('js/vendor/pdfjs-loader.js')) }}"></script>

{{-- LEARNED DATA LOADER — shared reader for the live learning desks. Layout-level
     so BOTH the create wizard and the project-detail Baseline tab can consult
     approved words (the detail tab never loads document-parser.js, where this
     used to live). Contractor layout deliberately excludes it. --}}
<script src="{{ asset('js/admin/projects/learned-keywords-loader.js') . '?v=' . filemtime(public_path('js/admin/projects/learned-keywords-loader.js')) }}"></script>
    
{{-- FORM VALIDATOR JS FUNCTION(CREATE/EDIT FORM VALIDATION) --}}
<script src="{{ asset('js/admin/projects/field-validation.js') }}"></script>
{{-- CREATE/EDIT PROJECTS MODAL MULTI PAGE FORM NAVIGATION JS FUNCTION --}}
<script src="{{ asset('js/admin/projects/form-navigation.js') }}"></script>

{{-- PRELOADER SCRIPT --}}
<script src="{{ asset('js/components/preloader.js') }}"></script>

{{-- GLOBAL TOAST NOTIFICATION --}}
@include('components.toast')

{{-- LEARNED KEYWORDS REVIEW MODAL (L2 — admin role layout; staff has its own include) --}}
@include('admin._manage_parser_keywords_modal')

{{-- S-CURVE LAYOUTS REVIEW MODAL (E1-2 — admin role layout; staff has its own include) --}}
{{-- Its pure logic (block parsing, prefill, row-click rules) lives in this module so
     npm run test:js can test it; the desk cannot work without it, so it loads right
     beside the desk, filemtime-versioned so an edit never serves a stale copy. --}}
<script type="module" src="{{ asset('js/admin/projects/scurve-teach-core.js') . '?v=' . filemtime(public_path('js/admin/projects/scurve-teach-core.js')) }}"></script>
@include('admin._manage_scurve_headers_modal')
    {{-- The two desks above call showDeleteConfirmation from any page, so its
         component must be present wherever they are. Guarded by @once, so pages
         that already include it are unaffected. --}}
    @include('components.delete-confirmation-modal')

{{-- DEV DATE SIMULATOR + NOTIFICATION SCAN (non-production only) --}}
@if (app()->environment() !== 'production')
@include('components.dev_date_widget')
@endif

{{-- COLLAPSIBLE SIDEBAR --}}
<script src="{{ asset('js/layouts/sidebar.js') }}"></script>

{{-- AUTO LOGOUT ON 20 MIN INACTIVITY --}}
@include('components.inactivity_logout')
</body>
</html>
