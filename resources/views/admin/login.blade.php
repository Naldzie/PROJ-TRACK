@extends('layouts.app')

@section('content')

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=JetBrains+Mono:wght@400;500&display=swap');

:root {
    --primary: #378ADD;
    --primary-light: #E6F1FB;
    --primary-dim: rgba(55,138,221,0.10);
    --bg: #f4f6f9;
    --card-bg: #ffffff;
    --border: rgba(0,0,0,0.07);
    --border-hover: rgba(55,138,221,0.30);
    --text-main: #1a1f2e;
    --text-muted: #7a8499;
    --text-subtle: #b0b8c8;
    --radius-lg: 16px;
    --radius-md: 10px;
    --radius-pill: 100px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.08), 0 1px 4px rgba(0,0,0,0.04);
    --shadow-row: 0 2px 12px rgba(55,138,221,0.10);
    --transition: 0.18s cubic-bezier(0.4,0,0.2,1);
}

* { font-family: 'DM Sans', sans-serif; }
code, .mono { font-family: 'JetBrains Mono', monospace; }

/* ── PAGE WRAPPER ── */
.logs-wrap {
    animation: pageIn 0.45s cubic-bezier(0.22,1,0.36,1) both;
}
@keyframes pageIn {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── CARD ── */
.card-box {
    background: var(--card-bg);
    border-radius: var(--radius-lg);
    border: 0.5px solid var(--border);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

/* ── HEADER ── */
.card-header-inner {
    padding: 22px 26px;
    border-bottom: 0.5px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    background: #fff;
}

.header-left { display: flex; align-items: center; gap: 14px; }

.header-icon {
    width: 40px;
    height: 40px;
    background: var(--primary-dim);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.header-icon svg { width: 18px; height: 18px; color: var(--primary); }

.page-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-main);
    letter-spacing: -0.01em;
    margin: 0;
    line-height: 1.2;
}

.page-subtitle {
    color: var(--text-muted);
    font-size: 12.5px;
    margin-top: 2px;
}

/* ── SEARCH / FILTERS ── */
.search-form {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.search-form .form-control,
.search-form .form-select {
    border-radius: var(--radius-pill);
    font-size: 13px;
    height: 36px;
    border: 0.5px solid #dde1e8;
    background: #f7f8fa;
    color: var(--text-main);
    padding: 0 14px;
    outline: none;
    transition: border-color var(--transition), box-shadow var(--transition), background var(--transition);
    font-family: 'DM Sans', sans-serif;
}

.search-form .form-control:focus,
.search-form .form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(55,138,221,0.13);
    background: #fff;
}

.search-form .form-select {
    padding-right: 30px;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8499' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    background-color: #f7f8fa;
}

.btn-search {
    border-radius: var(--radius-pill);
    font-size: 13px;
    padding: 6px 18px;
    height: 36px;
    background: var(--primary);
    color: #fff;
    border: none;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: opacity var(--transition), transform var(--transition), box-shadow var(--transition);
    font-family: 'DM Sans', sans-serif;
    box-shadow: 0 2px 8px rgba(55,138,221,0.28);
}

.btn-search:hover {
    opacity: 0.90;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(55,138,221,0.36);
    color: #000000;
}

.btn-search:active { transform: translateY(0); }

.btn-reset {
    border-radius: var(--radius-pill);
    font-size: 13px;
    padding: 6px 16px;
    height: 36px;
    background: #f3f4f6;
    color: var(--text-muted);
    border: 0.5px solid #e5e7eb;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background var(--transition), color var(--transition), transform var(--transition);
    font-family: 'DM Sans', sans-serif;
    text-decoration: none;
}

.btn-reset:hover {
    background: #e9eaec;
    color: var(--text-main);
    transform: translateY(-1px);
}

/* ── LOADING OVERLAY ── */
.table-wrap { position: relative; }

.loading-overlay {
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,0.75);
    backdrop-filter: blur(2px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
    border-radius: 0;
}

.loading-overlay.active {
    opacity: 1;
    pointer-events: all;
}

.spinner {
    width: 32px;
    height: 32px;
    border: 2.5px solid var(--primary-light);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ── TABLE ── */
.table {
    border-collapse: collapse;
    width: 100%;
    margin: 0;
}

.table thead th {
    font-size: 11.5px;
    color: var(--text-muted);
    font-weight: 500;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    padding: 11px 18px;
    background: #f8f9fb;
    border-bottom: 0.5px solid var(--border);
    white-space: nowrap;
}

.table tbody tr {
    border-bottom: 0.5px solid var(--border);
    transition: background var(--transition), box-shadow var(--transition);
    animation: rowIn 0.35s cubic-bezier(0.22,1,0.36,1) both;
}

/* staggered row animation */
.table tbody tr:nth-child(1)  { animation-delay: 0.04s; }
.table tbody tr:nth-child(2)  { animation-delay: 0.08s; }
.table tbody tr:nth-child(3)  { animation-delay: 0.12s; }
.table tbody tr:nth-child(4)  { animation-delay: 0.16s; }
.table tbody tr:nth-child(5)  { animation-delay: 0.20s; }
.table tbody tr:nth-child(6)  { animation-delay: 0.24s; }
.table tbody tr:nth-child(7)  { animation-delay: 0.28s; }
.table tbody tr:nth-child(8)  { animation-delay: 0.32s; }
.table tbody tr:nth-child(9)  { animation-delay: 0.36s; }
.table tbody tr:nth-child(10) { animation-delay: 0.40s; }

@keyframes rowIn {
    from { opacity: 0; transform: translateX(-6px); }
    to   { opacity: 1; transform: translateX(0); }
}

.table tbody tr:last-child { border-bottom: none; }

.table tbody tr:hover {
    background: #f9fafb;
    box-shadow: var(--shadow-row);
    position: relative;
    z-index: 1;
}

.table td {
    padding: 14px 18px;
    vertical-align: middle;
    font-size: 13px;
    color: var(--text-main);
}

/* ── USER CELL ── */
.user-cell { display: flex; align-items: center; gap: 11px; }

.avatar-wrap {
    position: relative;
    flex-shrink: 0;
}

.user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    border: 1.5px solid var(--border);
    transition: border-color var(--transition), transform var(--transition);
}

.table tbody tr:hover .user-avatar {
    border-color: var(--primary);
    transform: scale(1.05);
}

.user-name { font-size: 13px; font-weight: 500; color: var(--text-main); letter-spacing: -0.01em; }
.user-id   { font-size: 11px; color: var(--text-muted); margin-top: 2px; font-family: 'JetBrains Mono', monospace; }

/* ── ROLE BADGES ── */
.badge-role {
    display: inline-block;
    padding: 3px 10px;
    border-radius: var(--radius-pill);
    font-size: 11px;
    font-weight: 500;
    white-space: nowrap;
    letter-spacing: 0.01em;
}

.role-admin      { background: #FCEBEB; color: #A32D2D; }
.role-staff      { background: #E6F1FB; color: #185FA5; }
.role-contractor { background: #EAF3DE; color: #3B6D11; }
.role-unknown    { background: #f3f4f6; color: #6b7280; }

/* ── ACTION BADGES ── */
.badge-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 11px;
    border-radius: 7px;
    font-size: 11.5px;
    font-weight: 500;
    white-space: nowrap;
    transition: transform var(--transition), box-shadow var(--transition);
}

.badge-action::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}

.table tbody tr:hover .badge-action {
    transform: scale(1.04);
    box-shadow: 0 2px 6px rgba(0,0,0,0.10);
}

.action-login          { background: #EAF3DE; color: #3B6D11; }
.action-login::before  { background: #639922; animation: pulse-green 2s infinite; }

.action-create          { background: #E6F1FB; color: #185FA5; }
.action-create::before  { background: #378ADD; }

.action-update          { background: #FAEEDA; color: #854F0B; }
.action-update::before  { background: #BA7517; }

.action-delete          { background: #FCEBEB; color: #A32D2D; }
.action-delete::before  { background: #E24B4A; }

.action-logout          { background: #f3f4f6; color: #6b7280; }
.action-logout::before  { background: #9ca3af; }

.action-edit-baseline   { background: #F0E6FF; color: #6B21A8; }
.action-edit-baseline::before { background: #8B5CF6; }

.action-other          { background: #f3f4f6; color: #6b7280; }
.action-other::before  { background: #9ca3af; }

@keyframes pulse-green {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: 0.6; transform: scale(1.3); }
}

/* ── DESCRIPTION ── */
.desc-text {
    font-size: 13px;
    color: var(--text-muted);
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    max-width: 300px;
}

.desc-link {
    font-size: 11px;
    color: var(--primary);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    margin-top: 4px;
    transition: gap var(--transition);
}

.desc-link:hover { text-decoration: underline; gap: 5px; }

/* ── DATE ── */
.date-main { font-size: 13px; color: var(--text-main); font-weight: 500; }
.date-time  { font-size: 11px; color: var(--text-muted); margin-top: 2px; font-family: 'JetBrains Mono', monospace; }

/* ── EMPTY STATE ── */
.empty-state {
    padding: 72px 16px;
    text-align: center;
    animation: rowIn 0.4s ease both;
}

.empty-icon {
    width: 52px;
    height: 52px;
    background: #f3f4f6;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 14px;
}

.empty-icon svg { width: 22px; height: 22px; color: var(--text-muted); }

.empty-title { font-size: 14px; font-weight: 500; color: var(--text-main); margin-bottom: 4px; }
.empty-sub   { font-size: 13px; color: var(--text-muted); }

/* ── PAGINATION ── */
.pag-wrapper {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 22px;
    border-top: 0.5px solid var(--border);
    background: #fff;
    flex-wrap: wrap;
    gap: 8px;
}

.pag-info { font-size: 12px; color: var(--text-muted); }

.pagination { gap: 4px; margin: 0; }

.pagination .page-link {
    border-radius: 8px;
    border: 0.5px solid #e5e7eb;
    padding: 5px 12px;
    background: #fff;
    color: var(--text-main);
    font-size: 13px;
    line-height: 1.4;
    font-family: 'DM Sans', sans-serif;
    transition: all var(--transition);
}

.pagination .page-link:hover {
    background: var(--primary-light);
    border-color: var(--primary);
    color: var(--primary);
    transform: translateY(-1px);
}

.pagination .active .page-link {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
    font-weight: 500;
    box-shadow: 0 2px 8px rgba(55,138,221,0.30);
}

.pagination .disabled .page-link {
    background: #f9fafb;
    color: #d1d5db;
    border-color: #f1f2f4;
    transform: none;
}

/* ── MODAL ── */
.modal-content {
    border-radius: var(--radius-lg);
    border: 0.5px solid var(--border);
    box-shadow: 0 20px 60px rgba(0,0,0,0.14);
    animation: modalIn 0.28s cubic-bezier(0.34,1.56,0.64,1) both;
}

@keyframes modalIn {
    from { opacity: 0; transform: scale(0.94) translateY(8px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

.modal-header {
    padding: 18px 22px;
    border-bottom: 0.5px solid var(--border);
}

.modal-title { font-size: 14px; font-weight: 600; letter-spacing: -0.01em; }
.modal-body  { padding: 18px 22px; font-size: 13.5px; color: var(--text-main); line-height: 1.65; }

/* ── AUTO-REFRESH INDICATOR ── */
.select-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
}

.refresh-dot {
    position: absolute;
    right: -4px;
    top: -4px;
    width: 8px;
    height: 8px;
    background: var(--primary);
    border-radius: 50%;
    border: 1.5px solid #fff;
    opacity: 0;
    transform: scale(0);
    transition: opacity 0.2s, transform 0.2s;
}

.refresh-dot.show {
    opacity: 1;
    transform: scale(1);
    animation: dotPulse 1s ease infinite;
}

@keyframes dotPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(55,138,221,0.4); }
    50%       { box-shadow: 0 0 0 4px rgba(55,138,221,0); }
}

/* ── FILTER ACTIVE INDICATOR ── */
.filter-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px 3px 8px;
    border-radius: var(--radius-pill);
    background: var(--primary-dim);
    border: 1px solid rgba(55,138,221,0.2);
    font-size: 11.5px;
    color: var(--primary);
    font-weight: 500;
    animation: chipIn 0.25s cubic-bezier(0.34,1.56,0.64,1) both;
}

@keyframes chipIn {
    from { opacity: 0; transform: scale(0.8); }
    to   { opacity: 1; transform: scale(1); }
}

.filter-chip-remove {
    cursor: pointer;
    opacity: 0.6;
    transition: opacity 0.15s;
    line-height: 1;
}

.filter-chip-remove:hover { opacity: 1; }

.active-filters {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    padding: 10px 26px;
    border-bottom: 0.5px solid var(--border);
    background: #fafbfc;
}

.active-filters-label {
    font-size: 11.5px;
    color: var(--text-muted);
    font-weight: 500;
}

/* ── METADATA DIFF TOOLTIP ── */
.diff-trigger {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: var(--primary);
    cursor: help;
    margin-top: 4px;
    font-weight: 500;
    position: relative;
}
.diff-trigger i { font-size: 13px; }

.diff-tooltip {
    display: none;
    position: absolute;
    bottom: calc(100% + 8px);
    left: 0;
    z-index: 1050;
    background: #1a1f2e;
    color: #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    min-width: 380px;
    max-width: 520px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.25);
    font-size: 12px;
    line-height: 1.5;
    pointer-events: none;
}
.diff-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 20px;
    border: 6px solid transparent;
    border-top-color: #1a1f2e;
}
.diff-trigger:hover .diff-tooltip { display: block; }

.diff-tooltip .diff-header {
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #94a3b8;
    margin-bottom: 8px;
}
.diff-tooltip .diff-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11.5px;
}
.diff-tooltip .diff-table th {
    text-align: left;
    color: #94a3b8;
    font-weight: 500;
    padding: 2px 8px 2px 0;
    white-space: nowrap;
}
.diff-tooltip .diff-table td {
    padding: 2px 8px 2px 0;
    vertical-align: top;
    color: #e2e8f0;
}
.diff-tooltip .diff-old {
    color: #f87171;
    text-decoration: line-through;
    opacity: 0.85;
}
.diff-tooltip .diff-new {
    color: #4ade80;
    font-weight: 500;
}
.diff-tooltip .diff-removed {
    color: #f87171;
    font-style: italic;
    opacity: 0.7;
}
.diff-tooltip .diff-section {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid rgba(255,255,255,0.1);
}
</style>

<div class="logs-wrap">
<div class="card-box">

    {{-- HEADER --}}
    <div class="card-header-inner">
        <div class="header-left">
            <div class="header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                </svg>
            </div>
            <div>
                <div class="page-title">Activity logs</div>
                <div class="page-subtitle">Track all system activities in real-time</div>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.activity.logs') }}" class="search-form" id="filterForm">
            <input type="date" name="date" class="form-control" style="width:160px" value="{{ request('date') }}"
                   id="dateInput" onchange="triggerAutoSubmit()">

            <div class="select-wrap">
                <select name="action" class="form-select" style="width:170px" id="actionSelect" onchange="triggerAutoSubmit(this)">
                    <option value="">All actions</option>
                    @foreach(['Login','Logout','Create Project','Update Project','Delete Project','Create Category','Update Category','Delete Category','Create User','Delete User','Request Extension','Approve Extension','Reject Extension','Update Extension','Revert Extension','Supersede Extension','Resubmit Extension','Delete Extension','Restore Extension','Edit Original Baseline','Adjust Baseline','Deadline Reminder','Overdue Inspection','Extension Update','Issue Reported'] as $act)
                        <option value="{{ $act }}" {{ request('action') == $act ? 'selected' : '' }}>{{ $act }}</option>
                    @endforeach
                </select>
                <div class="refresh-dot" id="refreshDot"></div>
            </div>

            <button type="submit" class="btn btn-search" id="searchBtn">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" d="m21 21-4.35-4.35"/>
                </svg>
                Search
            </button>
            <a href="{{ route('admin.activity.logs') }}" class="btn btn-reset" id="resetBtn">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                Reset
            </a>
        </form>
    </div>

    {{-- ACTIVE FILTER CHIPS --}}
    @if(request('action') || request('date'))
    <div class="active-filters">
        <span class="active-filters-label">Filtered by:</span>
        @if(request('action'))
            <span class="filter-chip">
                Action: {{ request('action') }}
                <span class="filter-chip-remove" onclick="clearFilter('action')" title="Remove">✕</span>
            </span>
        @endif
        @if(request('date'))
            <span class="filter-chip">
                Date: {{ \Carbon\Carbon::parse(request('date'))->format('M d, Y') }}
                <span class="filter-chip-remove" onclick="clearFilter('date')" title="Remove">✕</span>
            </span>
        @endif
    </div>
    @endif

    {{-- TABLE --}}
    <div class="table-wrap">
        <div class="loading-overlay" id="loadingOverlay">
            <div class="spinner"></div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>Date &amp; Time</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($logs as $log)
                    @php
                        $user = $log->user;
                        $role = $user?->role ?? 'unknown';

                        $avatarSrc = $user && $user->profile_photo
                            ? (Str::startsWith($user->profile_photo, 'http')
                                ? $user->profile_photo
                                : asset('storage/' . $user->profile_photo))
                            : asset('images/default-profile.png');

                        $description = $log->description;
                        if (strtolower($log->action) === 'login') {
                            $description = ($user?->display_name ? $user->display_name . ' logged in' : 'User logged in');
                        }
                        $description = $description ?: 'No description available.';

                        $actionClass = match(true) {
                            $log->action === 'Login' => 'action-login',
                            $log->action === 'Logout' => 'action-logout',
                            $log->action === 'Edit Original Baseline' => 'action-edit-baseline',
                            str_starts_with($log->action, 'Create') => 'action-create',
                            str_starts_with($log->action, 'Update') => 'action-update',
                            str_starts_with($log->action, 'Delete') => 'action-delete',
                            default                           => 'action-other',
                        };

                        $roleClass = match($role) {
                            'admin'      => 'role-admin',
                            'staff'      => 'role-staff',
                            'contractor' => 'role-contractor',
                            default      => 'role-unknown',
                        };
                    @endphp

                    <tr>
                        {{-- USER --}}
                        <td>
                            <div class="user-cell">
                                <div class="avatar-wrap">
                                    <img src="{{ $avatarSrc }}" alt="{{ $user?->display_name }}" class="user-avatar"
                                         onerror="this.src='{{ asset('images/default-profile.png') }}'">
                                </div>
                                <div>
                                    <div class="user-name">{{ $user?->display_name ?? 'User deleted' }}</div>
                                    <div class="user-id">{{ $user?->display_id ?? '—' }}</div>
                                </div>
                            </div>
                        </td>

                        {{-- ROLE --}}
                        <td>
                            <span class="badge-role {{ $roleClass }}">{{ ucfirst($role) }}</span>
                        </td>

                        {{-- ACTION --}}
                        <td>
                            <span class="badge-action {{ $actionClass }}">{{ $log->action }}</span>
                        </td>

                        {{-- DESCRIPTION --}}
                        <td>
                            <div class="desc-text" id="desc-{{ $log->id }}">{{ $description }}</div>
                            @if(strlen($description) > 100)
                                <span class="desc-link" onclick="showDesc(@js($description))">
                                    View full ›
                                </span>
                            @endif
                            @if($log->action === 'Edit Original Baseline' && $log->metadata && isset($log->metadata['before'], $log->metadata['after']))
                                @php
                                    $before = $log->metadata['before'] ?? [];
                                    $after = $log->metadata['after'] ?? [];
                                    $removed = $log->metadata['removed'] ?? [];
                                    $beforeById = [];
                                    foreach ($before as $bm) { $beforeById[$bm['id']] = $bm; }
                                    $diffRows = [];
                                    foreach ($after as $am) {
                                        $bm = $beforeById[$am['id']] ?? null;
                                        if (!$bm) continue;
                                        $oldPct = (string) ($bm['original_planned_progress_percent'] ?? '');
                                        $newPct = (string) ($am['original_planned_progress_percent'] ?? '');
                                        $oldDate = (string) ($bm['original_planned_date'] ?? '');
                                        $newDate = (string) ($am['original_planned_date'] ?? '');
                                        $label = $am['month_label'] ?? substr($newDate, 0, 7) ?? '';
                                        if ($oldPct !== $newPct || $oldDate !== $newDate) {
                                            $diffRows[] = compact('label', 'oldPct', 'newPct', 'oldDate', 'newDate');
                                        }
                                    }
                                @endphp
                                <div class="diff-trigger">
                                    <i class="bi bi-code-slash"></i> Changes
                                    <div class="diff-tooltip">
                                        <div class="diff-header">Baseline Edit Diff</div>
                                        @if(count($diffRows) > 0)
                                            <table class="diff-table">
                                                <tr><th>Month</th><th>Progress</th><th>Date</th></tr>
                                                @foreach($diffRows as $row)
                                                    <tr>
                                                        <td>{{ $row['label'] }}</td>
                                                        <td>
                                                            @if($row['oldPct'] !== $row['newPct'])
                                                                <span class="diff-old">{{ $row['oldPct'] }}%</span> &rarr; <span class="diff-new">{{ $row['newPct'] }}%</span>
                                                            @else
                                                                {{ $row['newPct'] }}%
                                                            @endif
                                                        </td>
                                                        <td>
                                                            @if($row['oldDate'] !== $row['newDate'])
                                                                <span class="diff-old">{{ $row['oldDate'] }}</span> &rarr; <span class="diff-new">{{ $row['newDate'] }}</span>
                                                            @else
                                                                {{ $row['newDate'] }}
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </table>
                                        @else
                                            <div style="opacity:0.6;">No value changes detected.</div>
                                        @endif
                                        @if(count($removed) > 0)
                                            <div class="diff-section">
                                                <div class="diff-header" style="margin-bottom:4px;">Removed Months</div>
                                                @foreach($removed as $rid)
                                                    @php $rm = $beforeById[$rid] ?? null; @endphp
                                                    @if($rm)
                                                        <div class="diff-removed">&ndash; {{ $rm['month_label'] ?? 'ID '.$rid }} ({{ $rm['original_planned_progress_percent'] ?? '?' }}%)</div>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </td>

                        {{-- DATE --}}
                        <td>
                            <div class="date-main">{{ $log->created_at?->format('M d, Y') }}</div>
                            <div class="date-time">{{ $log->created_at?->format('h:i A') }}</div>
                        </td>
                    </tr>

                @empty
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                    </svg>
                                </div>
                                <div class="empty-title">No activity logs found</div>
                                <div class="empty-sub">Try adjusting your filters or date range</div>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- PAGINATION --}}
    @if($logs->hasPages())
    <div class="pag-wrapper">
        <span class="pag-info">
            Showing {{ $logs->firstItem() }}–{{ $logs->lastItem() }} of {{ $logs->total() }} entries
        </span>
        {{ $logs->withQueryString()->links('pagination::bootstrap-5') }}
    </div>
    @endif

</div>
</div>

{{-- DESCRIPTION MODAL --}}
<div class="modal fade" id="descModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:32px;height:32px;background:var(--primary-dim);border-radius:8px;display:flex;align-items:center;justify-content:center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#378ADD" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h5 class="modal-title">Activity description</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="descContent"></div>
        </div>
    </div>
</div>

<script>
// ── AUTO SUBMIT ON SELECT CHANGE ──
function triggerAutoSubmit(selectEl) {
    const dot = document.getElementById('refreshDot');
    const overlay = document.getElementById('loadingOverlay');

    // Show pulse dot on select
    if (selectEl) {
        dot.classList.add('show');
    }

    // Brief delay so user sees the dot, then show loading & submit
    setTimeout(() => {
        overlay.classList.add('active');
        dot.classList.remove('show');

        // Animate search button
        const btn = document.getElementById('searchBtn');
        btn.style.opacity = '0.6';
        btn.style.pointerEvents = 'none';

        document.getElementById('filterForm').submit();
    }, 320);
}

// ── CLEAR INDIVIDUAL FILTER ──
function clearFilter(field) {
    const url = new URL(window.location.href);
    url.searchParams.delete(field);
    // Show loading before redirect
    document.getElementById('loadingOverlay').classList.add('active');
    window.location.href = url.toString();
}

// ── SHOW FULL DESCRIPTION IN MODAL ──
function showDesc(text) {
    document.getElementById('descContent').textContent = text;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('descModal')).show();
}

// ── RESET BUTTON: show loading ──
document.getElementById('resetBtn').addEventListener('click', function() {
    document.getElementById('loadingOverlay').classList.add('active');
});

// ── SUBMIT BUTTON: show loading ──
document.getElementById('filterForm').addEventListener('submit', function() {
    document.getElementById('loadingOverlay').classList.add('active');
    const btn = document.getElementById('searchBtn');
    btn.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" style="animation:spin 0.7s linear infinite">
            <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
        </svg>
        Searching…
    `;
});

// ── RE-ANIMATE ROWS ON PAGE LOAD ──
document.addEventListener('DOMContentLoaded', () => {
    const rows = document.querySelectorAll('.table tbody tr');
    rows.forEach((row, i) => {
        row.style.animationDelay = `${0.04 + i * 0.04}s`;
    });
});
</script>

@endsection