@php
    $layout = match(auth()->user()->role) {
        'contractor' => 'layouts.contractorlayouts',
        'staff' => 'layouts.stafflayouts',
        default => 'layouts.app'
    };
@endphp
@extends($layout)

@section('content')
<style>
    /* ===== DASHBOARD LAYOUT & ZONES ===== */
    .dashboard-page {
        display: flex;
        flex-direction: column;
        gap: 30px;
    }

    .dashboard-zone {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .zone-header {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 18px;
        font-weight: 700;
        color: #1f2937;
        padding-bottom: 12px;
        border-bottom: 2px solid #e5e7eb;
        margin-bottom: 5px;
    }

    .zone-header i {
        font-size: 22px;
        color: #2563eb;
    }

    /* ===== PAGE HEADER ===== */
    .dashboard-header {
        background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
        border-radius: 15px;
        padding: 25px 30px;
        color: white;
        margin-bottom: 10px;
    }

    .dashboard-header h2 {
        margin: 0;
        font-weight: 600;
        font-size: 28px;
    }

    .dashboard-header p {
        margin: 5px 0 0;
        opacity: 0.9;
        font-size: 14px;
    }

    .dashboard-header-actions {
        display: flex;
        gap: 10px;
    }

    .quick-action-btn {
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
    }

    /* ===== ZONE 1: EXECUTIVE SUMMARY ===== */
    .critical-alerts-banner {
        background: white;
        border-radius: 12px;
        padding: 16px 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        border-left: 4px solid #dc2626;
    }

    .alerts-container {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
    }

    .alert-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        background: #fef3c7;
        border: 1px solid #fcd34d;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 500;
        color: #92400e;
        cursor: pointer;
        transition: all 0.2s;
    }

    .alert-pill:hover {
        background: #fde68a;
        transform: translateY(-2px);
        box-shadow: 0 2px 6px rgba(217, 119, 6, 0.15);
    }

    .alert-pill.danger {
        background: #fee2e2;
        border-color: #fca5a5;
        color: #991b1b;
    }

    .alert-pill.danger:hover {
        background: #fecaca;
    }

    .alert-pill.warning {
        background: #fef3c7;
        border-color: #fcd34d;
        color: #92400e;
    }

    .alert-pill.info {
        background: #dbeafe;
        border-color: #93c5fd;
        color: #1e40af;
    }

    .alert-pill i {
        font-size: 14px;
    }

    .alert-count {
        background: rgba(0,0,0,0.1);
        padding: 2px 8px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 12px;
    }

    /* Health Score Cards */
    .health-metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 16px;
    }

    .health-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        transition: all 0.2s;
    }

    .health-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.12);
    }

    .health-card .icon-box {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin-bottom: 12px;
    }

    .health-card .metric-value {
        font-size: 32px;
        font-weight: 700;
        color: #1f2937;
        line-height: 1;
    }

    .health-card .metric-label {
        color: #6b7280;
        font-size: 13px;
        margin-top: 6px;
        font-weight: 500;
    }

    .health-card .metric-change {
        font-size: 12px;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid #f3f4f6;
    }

    .metric-change.positive {
        color: #059669;
    }

    .metric-change.negative {
        color: #dc2626;
    }

    /* ===== ZONE 2: OPERATIONAL STATUS ===== */
    
    /* Predictive Risk Indicator */
    .risk-indicator {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
    }

    .risk-low {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }

    .risk-medium {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a;
    }

    .risk-high {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fca5a5;
    }

    .risk-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        display: inline-block;
    }

    /* Milestone Widget */
    .milestone-widget {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    .milestone-item {
        display: flex;
        align-items: flex-start;
        padding: 14px 0;
        border-bottom: 1px solid #f3f4f6;
        gap: 12px;
    }

    .milestone-item:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }

    .milestone-date-box {
        min-width: 60px;
        text-align: center;
        padding: 8px 12px;
        background: #f3f4f6;
        border-radius: 8px;
        border-left: 3px solid #2563eb;
    }

    .milestone-date-box.urgent {
        background: #fee2e2;
        border-left-color: #dc2626;
    }

    .milestone-date-box.warning {
        background: #fef3c7;
        border-left-color: #d97706;
    }

    .milestone-date-box .day {
        font-size: 16px;
        font-weight: 700;
        color: #1f2937;
        line-height: 1;
    }

    .milestone-date-box .month {
        font-size: 10px;
        color: #6b7280;
        text-transform: uppercase;
        margin-top: 2px;
    }

    .milestone-content {
        flex: 1;
    }

    .milestone-title {
        font-weight: 600;
        color: #1f2937;
        font-size: 14px;
    }

    .milestone-meta {
        font-size: 12px;
        color: #6b7280;
        margin-top: 3px;
    }

    .milestone-progress-bar {
        height: 6px;
        background: #e5e7eb;
        border-radius: 3px;
        margin-top: 8px;
        overflow: hidden;
    }

    .milestone-progress-bar .fill {
        height: 100%;
        background: #2563eb;
        transition: width 0.3s ease;
    }

    .milestone-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }

    /* Projects Follow-up Table with Risk */
    .follow-up-section {
        background: white;
        border-radius: 12px;
        border: 1px solid var(--border);
        overflow: hidden;
    }

    .follow-up-table-container {
        overflow-x: auto;
    }

    .follow-up-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }

    .follow-up-table th {
        background: #f8fafc;
        padding: 10px 12px;
        text-align: left;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        font-size: 11px;
        border-bottom: 2px solid var(--border);
        white-space: nowrap;
    }

    .follow-up-table td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
        font-size: 12px;
    }

    .follow-up-table tr:last-child td { 
        border-bottom: none; 
    }

    .follow-up-table tbody tr:hover {
        background: #f8fafc;
    }

    .follow-up-table .project-code {
        font-weight: 700;
        color: var(--text-main);
        font-size: 12px;
    }

    .follow-up-table .project-title {
        font-weight: 600;
        color: var(--text-main);
        font-size: 13px;
    }

    .follow-up-table .project-category {
        display: inline-block;
        font-size: 10px;
        padding: 2px 6px;
        background: #eff6ff;
        color: #1e40af;
        border-radius: 4px;
        margin-top: 2px;
    }

    .follow-up-table .status-badge {
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 600;
        white-space: nowrap;
        display: inline-block;
    }

    .project-code {
        font-weight: 700;
        color: #1f2937;
    }

    .project-title {
        font-weight: 600;
        color: #1f2937;
    }

    .project-category {
        display: inline-block;
        font-size: 11px;
        padding: 3px 8px;
        background: #eff6ff;
        color: #1e40af;
        border-radius: 4px;
        margin-top: 2px;
    }

    .progress-visual {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .progress-bar-mini {
        width: 50px;
        height: 6px;
        background: #e5e7eb;
        border-radius: 4px;
        overflow: hidden;
    }

    .progress-bar-mini .fill {
        height: 100%;
        background: #2563eb;
        transition: width 0.3s ease;
    }

    .progress-percent {
        font-weight: 600;
        min-width: 30px;
        text-align: right;
        font-size: 12px;
    }

    .deadline-cell {
        font-weight: 500;
        font-size: 12px;
    }

    .deadline-cell.overdue {
        color: #dc2626;
    }

    .deadline-cell.urgent {
        color: #d97706;
    }

    .risk-indicator {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 11px;
        font-weight: 600;
    }

    .risk-dot {
        display: inline-block;
        width: 6px;
        height: 6px;
        border-radius: 50%;
    }

    .status-badge {
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .status-delayed {
        background: #fee2e2;
        color: #991b1b;
    }

    .status-at-risk {
        background: #fef3c7;
        color: #92400e;
    }

    .status-on-track {
        background: #d1fae5;
        color: #065f46;
    }

    /* Status & Progress Charts */
    .chart-container {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    .progress-section {
        margin-bottom: 18px;
    }

    .progress-section:last-child {
        margin-bottom: 0;
    }

    .progress-label {
        display: flex;
        justify-content: space-between;
        margin-bottom: 6px;
        font-size: 13px;
        font-weight: 600;
    }

    .progress-bar-custom {
        height: 12px;
        background: #e5e7eb;
        border-radius: 6px;
        overflow: hidden;
    }

    .progress-bar-custom .fill {
        height: 100%;
        transition: width 0.3s ease;
    }

    /* ===== ZONE 3: CONTRACTOR INTELLIGENCE ===== */

    /* Contractor Performance Panel */
    .contractor-performance {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    .contractor-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 16px;
    }

    .contractor-card {
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 16px;
        transition: all 0.2s;
    }

    .contractor-card:hover {
        border-color: #2563eb;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
    }

    .contractor-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 14px;
    }

    .contractor-avatar {
        width: 44px;
        height: 44px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
        color: white;
    }

    .contractor-name {
        flex: 1;
    }

    .contractor-name .title {
        font-weight: 600;
        color: #1f2937;
        font-size: 14px;
    }

    .contractor-name .projects {
        font-size: 12px;
        color: #6b7280;
        margin-top: 2px;
    }

    .contractor-risk {
        font-size: 12px;
        padding: 4px 8px;
        border-radius: 4px;
    }

    .contractor-risk.low {
        background: #d1fae5;
        color: #065f46;
    }

    .contractor-risk.medium {
        background: #fef3c7;
        color: #92400e;
    }

    .contractor-risk.high {
        background: #fee2e2;
        color: #991b1b;
    }

    .contractor-metrics {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .metric-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .metric-row .label {
        font-size: 12px;
        color: #6b7280;
        font-weight: 500;
    }

    .metric-row .value {
        font-weight: 700;
        color: #1f2937;
        font-size: 14px;
    }

    .metric-bar {
        width: 100%;
    }

    .metric-bar-track {
        width: 100%;
        height: 6px;
        background: #e5e7eb;
        border-radius: 3px;
        overflow: hidden;
    }

    .metric-bar-fill {
        height: 100%;
        background: #2563eb;
        border-radius: 3px;
    }

    /* Activity & Quick Links */
    .activity-section {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    .activity-item {
        display: flex;
        align-items: flex-start;
        padding: 12px 0;
        border-bottom: 1px solid #f3f4f6;
        gap: 10px;
    }

    .activity-item:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }

    .activity-icon {
        width: 36px;
        height: 36px;
        min-width: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
    }

    .activity-icon.progress {
        background: #dbeafe;
        color: #2563eb;
    }

    .activity-icon.complete {
        background: #d1fae5;
        color: #059669;
    }

    .activity-icon.update {
        background: #fef3c7;
        color: #d97706;
    }

    .activity-icon.create {
        background: #e9d5ff;
        color: #7e22ce;
    }

    .activity-icon.alert {
        background: #fee2e2;
        color: #dc2626;
    }

    .activity-content {
        flex: 1;
    }

    .activity-text {
        font-size: 13px;
        color: #374151;
        line-height: 1.4;
    }

    .activity-time {
        font-size: 11px;
        color: #9ca3af;
        margin-top: 3px;
    }

    .quick-links-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 16px;
        margin-top: 20px;
    }

    .quick-link-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        text-align: center;
        text-decoration: none;
        color: inherit;
        transition: all 0.2s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        cursor: pointer;
    }

    .quick-link-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 16px rgba(0,0,0,0.12);
        text-decoration: none;
        color: inherit;
    }

    .quick-link-icon {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        margin: 0 auto 12px;
    }

    .quick-link-title {
        font-weight: 600;
        color: #1f2937;
        font-size: 14px;
    }

    .quick-link-desc {
        font-size: 12px;
        color: #6b7280;
        margin-top: 4px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .dashboard-header {
            padding: 20px;
        }

        .dashboard-header h2 {
            font-size: 24px;
        }

        .dashboard-header-actions {
            flex-direction: column;
            width: 100%;
        }

        .quick-action-btn {
            width: 100%;
            text-align: center;
        }

        .health-metrics-grid {
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }

        .contractor-grid {
            grid-template-columns: 1fr;
        }

        .projects-table {
            font-size: 12px;
        }

        .projects-table th,
        .projects-table td {
            padding: 10px 8px;
        }

        .follow-up-table {
            font-size: 11px;
        }

        .follow-up-table th,
        .follow-up-table td {
            padding: 8px 10px;
        }
    }
</style>

<!-- ===== PAGE HEADER ===== -->
<div class="dashboard-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h2><i class="bi bi-speedometer2 me-2"></i>Project Monitoring Dashboard</h2>
            <p>Real-time project overview with predictive risk analytics and contractor performance tracking.</p>
        </div>
        <div class="dashboard-header-actions">
            <a href="{{ route('admin.projects') }}" class="btn btn-light quick-action-btn">
                <i class="bi bi-plus-circle me-1"></i> New Project
            </a>
            <a href="{{ route('admin.projects') }}" class="btn btn-outline-light quick-action-btn">
                <i class="bi bi-folder me-1"></i> View All Projects
            </a>
        </div>
    </div>
</div>

<!-- ===== ZONE 1: EXECUTIVE SUMMARY ===== -->
<div class="dashboard-zone">
    <div class="zone-header">
        <i class="bi bi-exclamation-triangle-fill"></i>
        Critical Alerts & Status
    </div>

    <!-- Critical Alerts Banner - Updated for Activities -->
    <div class="critical-alerts-banner">
        <div style="font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 10px; text-transform: uppercase;">
            ⚠️ This Month's Attention Required
        </div>
        <div class="alerts-container">
            <div class="alert-pill danger" title="View at-risk projects">
                <i class="bi bi-x-circle-fill"></i>
                <span>Projects at Risk</span>
                <span class="alert-count">2</span>
            </div>
            <div class="alert-pill warning" title="View overdue activities">
                <i class="bi bi-clock-history"></i>
                <span>Overdue Activities</span>
                <span class="alert-count">4</span>
            </div>
            <div class="alert-pill info" title="View pending updates">
                <i class="bi bi-arrow-repeat"></i>
                <span>Pending Update</span>
                <span class="alert-count">3</span>
            </div>
            <div class="alert-pill" style="background: #eff6ff; border: 1px solid #93c5fd; color: #1e40af;">
                <i class="bi bi-list-check"></i>
                <span>Monthly Target Gap</span>
                <span class="alert-count">28%</span>
            </div>
        </div>
    </div>

    <!-- Health Score Cards - Updated for Monthly Metrics -->
    <div class="health-metrics-grid">
        <!-- Monthly Target Achievement -->
        <div class="health-card">
            <div class="icon-box" style="background: #dbeafe; color: #2563eb;">
                <i class="bi bi-graph-up"></i>
            </div>
            <div class="metric-value">72%</div>
            <div class="metric-label">Monthly Target Achievement</div>
            <div class="metric-change positive">
                <i class="bi bi-arrow-up"></i> Projects meeting April targets
            </div>
        </div>

        <!-- Activities Completion Rate -->
        <div class="health-card">
            <div class="icon-box" style="background: #d1fae5; color: #059669;">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="metric-value">58%</div>
            <div class="metric-label">Activities Completion Rate</div>
            <div class="metric-change positive">
                <i class="bi bi-arrow-up"></i> 23 of 40 activities completed
            </div>
        </div>

        <!-- Contractor Response Time -->
        <div class="health-card">
            <div class="icon-box" style="background: #fee2e2; color: #dc2626;">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <div class="metric-value">35%</div>
            <div class="metric-label">Projects at Risk</div>
            <div class="metric-change negative">
                <i class="bi bi-arrow-down"></i> 2 of 8 may miss targets
            </div>
        </div>

        <!-- Contractor Responsiveness -->
        <div class="health-card">
            <div class="icon-box" style="background: #fef3c7; color: #d97706;">
                <i class="bi bi-people-fill"></i>
            </div>
            <div class="metric-value">3.2 days</div>
            <div class="metric-label">Avg Activity Update Lag</div>
            <div class="metric-change positive">
                <i class="bi bi-arrow-up"></i> Quick contractor engagement
            </div>
        </div>
    </div>
</div>

<!-- ===== ZONE 2: OPERATIONAL STATUS ===== -->
<div class="dashboard-zone">
    <div class="zone-header">
        <i class="bi bi-calendar-check"></i>
        Operational Status
    </div>

    <!-- Monthly Targets & Current Month Progress Widget -->
    <div class="milestone-widget">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="font-weight: 600; color: #1f2937; font-size: 15px;">
                    <i class="bi bi-calendar2-month" style="color: #2563eb; margin-right: 8px;"></i>Monthly Targets & Progress
                </div>
                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: #dbeafe; color: #1e40af; border-radius: 20px; font-size: 12px; font-weight: 600;">
                    <i class="bi bi-calendar"></i> April 2026
                </span>
            </div>
            <a href="{{ route('admin.projects') }}" class="btn btn-sm btn-outline-primary">View All</a>
        </div>

        <!-- Month Overview Stats -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #e5e7eb;">
            <div style="padding: 12px; background: #f8fafc; border-radius: 8px; border-left: 3px solid #2563eb;">
                <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Active Projects</div>
                <div style="font-size: 24px; font-weight: 700; color: #1f2937; margin-top: 4px;">8</div>
                <div style="font-size: 12px; color: #059669; margin-top: 6px;"><i class="bi bi-arrow-up"></i> 2 new this month</div>
            </div>
            <div style="padding: 12px; background: #f8fafc; border-radius: 8px; border-left: 3px solid #10b981;">
                <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Target Completion Rate</div>
                <div style="font-size: 24px; font-weight: 700; color: #1f2937; margin-top: 4px;">72%</div>
                <div style="font-size: 12px; color: #6b7280; margin-top: 6px;">6 of 8 projects on track</div>
            </div>
            <div style="padding: 12px; background: #f8fafc; border-radius: 8px; border-left: 3px solid #d97706;">
                <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">At Risk</div>
                <div style="font-size: 24px; font-weight: 700; color: #1f2937; margin-top: 4px;">2</div>
                <div style="font-size: 12px; color: #d97706; margin-top: 6px;"><i class="bi bi-exclamation-triangle"></i> Need attention</div>
            </div>
        </div>

        <!-- Monthly Target Item 1 - At Risk -->
        <div class="milestone-item">
            <div class="milestone-date-box urgent">
                <div class="day">15</div>
                <div class="month">Apr</div>
            </div>
            <div class="milestone-content">
                <div style="display: flex; align-items: start; justify-content: space-between; gap: 10px;">
                    <div style="flex: 1;">
                        <div class="milestone-title">Foundation & Ground Work Completion</div>
                        <div class="milestone-meta">PRJ-2026-015 • ABC Construction Co. • Target: 45% → 70%</div>
                        <div style="margin-top: 8px; font-size: 12px; color: #374151;">
                            <strong>Current Progress:</strong> 45% | <strong>Monthly Target:</strong> 70% | <strong>Gap:</strong> 25%
                        </div>
                        <div class="milestone-progress-bar" style="margin-top: 8px;">
                            <div class="fill" style="width: 45%; background: #d97706;"></div>
                        </div>
                        <div style="display: flex; gap: 16px; margin-top: 8px; font-size: 11px; color: #6b7280; flex-wrap: wrap;">
                            <span><strong>4 activities</strong> planned for this month</span>
                            <span><strong>2 completed</strong> • <strong>2 pending</strong></span>
                        </div>
                    </div>
                    <span class="risk-indicator risk-high">
                        <span class="risk-dot" style="background: #dc2626;"></span>HIGH RISK
                    </span>
                </div>
            </div>
        </div>

        <!-- Monthly Target Item 2 - Warning -->
        <div class="milestone-item">
            <div class="milestone-date-box warning">
                <div class="day">20</div>
                <div class="month">Apr</div>
            </div>
            <div class="milestone-content">
                <div style="display: flex; align-items: start; justify-content: space-between; gap: 10px;">
                    <div style="flex: 1;">
                        <div class="milestone-title">Electrical Installation Phase 1</div>
                        <div class="milestone-meta">PRJ-2026-020 • TechNet Solutions • Target: 60% → 80%</div>
                        <div style="margin-top: 8px; font-size: 12px; color: #374151;">
                            <strong>Current Progress:</strong> 60% | <strong>Monthly Target:</strong> 80% | <strong>Gap:</strong> 20%
                        </div>
                        <div class="milestone-progress-bar" style="margin-top: 8px;">
                            <div class="fill" style="width: 60%; background: #f59e0b;"></div>
                        </div>
                        <div style="display: flex; gap: 16px; margin-top: 8px; font-size: 11px; color: #6b7280; flex-wrap: wrap;">
                            <span><strong>5 activities</strong> planned for this month</span>
                            <span><strong>3 completed</strong> • <strong>2 pending</strong></span>
                        </div>
                    </div>
                    <span class="risk-indicator risk-medium">
                        <span class="risk-dot" style="background: #d97706;"></span>MEDIUM RISK
                    </span>
                </div>
            </div>
        </div>

        <!-- Monthly Target Item 3 - On Track -->
        <div class="milestone-item">
            <div class="milestone-date-box">
                <div class="day">25</div>
                <div class="month">Apr</div>
            </div>
            <div class="milestone-content">
                <div style="display: flex; align-items: start; justify-content: space-between; gap: 10px;">
                    <div style="flex: 1;">
                        <div class="milestone-title">Structural Inspection & Quality Assurance</div>
                        <div class="milestone-meta">PRJ-2026-021 • Premier Construction • Target: 70% → 85%</div>
                        <div style="margin-top: 8px; font-size: 12px; color: #374151;">
                            <strong>Current Progress:</strong> 70% | <strong>Monthly Target:</strong> 85% | <strong>Gap:</strong> 15%
                        </div>
                        <div class="milestone-progress-bar" style="margin-top: 8px;">
                            <div class="fill" style="width: 70%; background: #10b981;"></div>
                        </div>
                        <div style="display: flex; gap: 16px; margin-top: 8px; font-size: 11px; color: #6b7280; flex-wrap: wrap;">
                            <span><strong>3 activities</strong> planned for this month</span>
                            <span><strong>2 completed</strong> • <strong>1 pending</strong></span>
                        </div>
                    </div>
                    <span class="risk-indicator risk-low">
                        <span class="risk-dot" style="background: #059669;"></span>ON TRACK
                    </span>
                </div>
            </div>
        </div>

        <!-- Monthly Target Item 4 -->
        <div class="milestone-item">
            <div class="milestone-date-box">
                <div class="day">30</div>
                <div class="month">Apr</div>
            </div>
            <div class="milestone-content">
                <div style="display: flex; align-items: start; justify-content: space-between; gap: 10px;">
                    <div style="flex: 1;">
                        <div class="milestone-title">HVAC System Installation & Testing</div>
                        <div class="milestone-meta">PRJ-2026-023 • RoadWorks Corp. • Target: 50% → 70%</div>
                        <div style="margin-top: 8px; font-size: 12px; color: #374151;">
                            <strong>Current Progress:</strong> 50% | <strong>Monthly Target:</strong> 70% | <strong>Gap:</strong> 20%
                        </div>
                        <div class="milestone-progress-bar" style="margin-top: 8px;">
                            <div class="fill" style="width: 50%; background: #2563eb;"></div>
                        </div>
                        <div style="display: flex; gap: 16px; margin-top: 8px; font-size: 11px; color: #6b7280; flex-wrap: wrap;">
                            <span><strong>4 activities</strong> planned for this month</span>
                            <span><strong>1 completed</strong> • <strong>3 pending</strong></span>
                        </div>
                    </div>
                    <span class="risk-indicator risk-low">
                        <span class="risk-dot" style="background: #059669;"></span>ON TRACK
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Activities Checklist for Current Month -->
    <div style="background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-top: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div style="font-weight: 600; color: #1f2937; font-size: 15px;">
                <i class="bi bi-list-check" style="color: #2563eb; margin-right: 8px;"></i>Monthly Activities by Project
            </div>
            <div style="display: flex; gap: 8px;">
                <button class="btn btn-sm btn-outline-secondary" style="font-size: 12px;">This Month</button>
                <a href="{{ route('admin.projects') }}" class="btn btn-sm btn-outline-primary">View Details</a>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
            <!-- Project 1 Activities -->
            <div style="border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; background: #f9fafb;">
                <div style="font-weight: 600; color: #1f2937; font-size: 13px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; background: #dbeafe; color: #2563eb; border-radius: 6px; font-size: 12px; font-weight: 700;">1</span>
                    Science Building Phase 2 (PRJ-2026-015)
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px; background: white; border-radius: 6px; font-size: 12px;">
                        <input type="checkbox" checked style="width: 16px; height: 16px; cursor: pointer;">
                        <span style="color: #374151; flex: 1;">✓ Excavation & Site Preparation</span>
                        <span style="color: #6b7280; font-size: 11px;">Mar 30</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px; background: white; border-radius: 6px; font-size: 12px;">
                        <input type="checkbox" checked style="width: 16px; height: 16px; cursor: pointer;">
                        <span style="color: #374151; flex: 1;">✓ Foundation Pouring Started</span>
                        <span style="color: #6b7280; font-size: 11px;">Apr 5</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px; background: white; border-radius: 6px; font-size: 12px;">
                        <input type="checkbox" style="width: 16px; height: 16px; cursor: pointer;">
                        <span style="color: #6b7280; flex: 1;">Foundation Pouring Completed</span>
                        <span style="color: #d97706; font-size: 11px;">Due: Apr 12</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px; background: white; border-radius: 6px; font-size: 12px;">
                        <input type="checkbox" style="width: 16px; height: 16px; cursor: pointer;">
                        <span style="color: #6b7280; flex: 1;">Structural Steel Installation</span>
                        <span style="color: #d97706; font-size: 11px;">Due: Apr 20</span>
                    </label>
                </div>
                <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; font-size: 11px;">
                    <span style="color: #6b7280;">Progress: <strong style="color: #1f2937;">50% (2/4)</strong></span>
                    <span class="risk-indicator risk-high">
                        <span class="risk-dot" style="background: #d97706;"></span>AT RISK
                    </span>
                </div>
            </div>

            <!-- Project 2 Activities -->
            <div style="border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; background: #f9fafb;">
                <div style="font-weight: 600; color: #1f2937; font-size: 13px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; background: #dbeafe; color: #2563eb; border-radius: 6px; font-size: 12px; font-weight: 700;">2</span>
                    Electrical Installation Phase 1 (PRJ-2026-020)
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px; background: white; border-radius: 6px; font-size: 12px;">
                        <input type="checkbox" checked style="width: 16px; height: 16px; cursor: pointer;">
                        <span style="color: #374151; flex: 1;">✓ Conduit Installation</span>
                        <span style="color: #6b7280; font-size: 11px;">Apr 3</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px; background: white; border-radius: 6px; font-size: 12px;">
                        <input type="checkbox" checked style="width: 16px; height: 16px; cursor: pointer;">
                        <span style="color: #374151; flex: 1;">✓ Wiring Installation (Ground Floor)</span>
                        <span style="color: #6b7280; font-size: 11px;">Apr 8</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px; background: white; border-radius: 6px; font-size: 12px;">
                        <input type="checkbox" style="width: 16px; height: 16px; cursor: pointer;">
                        <span style="color: #6b7280; flex: 1;">Wiring Installation (Upper Floors)</span>
                        <span style="color: #d97706; font-size: 11px;">Due: Apr 15</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px; background: white; border-radius: 6px; font-size: 12px;">
                        <input type="checkbox" style="width: 16px; height: 16px; cursor: pointer;">
                        <span style="color: #6b7280; flex: 1;">Electrical Testing & Inspection</span>
                        <span style="color: #d97706; font-size: 11px;">Due: Apr 22</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px; background: white; border-radius: 6px; font-size: 12px;">
                        <input type="checkbox" style="width: 16px; height: 16px; cursor: pointer;">
                        <span style="color: #6b7280; flex: 1;">Final Approval & Sign-off</span>
                        <span style="color: #d97706; font-size: 11px;">Due: Apr 25</span>
                    </label>
                </div>
                <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; font-size: 11px;">
                    <span style="color: #6b7280;">Progress: <strong style="color: #1f2937;">40% (2/5)</strong></span>
                    <span class="risk-indicator risk-medium">
                        <span class="risk-dot" style="background: #d97706;"></span>MEDIUM
                    </span>
                </div>
            </div>

            <!-- Project 3 Activities -->
            <div style="border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; background: #f9fafb;">
                <div style="font-weight: 600; color: #1f2937; font-size: 13px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; background: #d1fae5; color: #059669; border-radius: 6px; font-size: 12px; font-weight: 700;">3</span>
                    Admin Building Renovation (PRJ-2026-021)
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px; background: white; border-radius: 6px; font-size: 12px;">
                        <input type="checkbox" checked style="width: 16px; height: 16px; cursor: pointer;">
                        <span style="color: #374151; flex: 1;">✓ Roof Inspection & Repairs</span>
                        <span style="color: #6b7280; font-size: 11px;">Apr 2</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px; background: white; border-radius: 6px; font-size: 12px;">
                        <input type="checkbox" checked style="width: 16px; height: 16px; cursor: pointer;">
                        <span style="color: #374151; flex: 1;">✓ Interior Wall Renovation</span>
                        <span style="color: #6b7280; font-size: 11px;">Apr 9</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px; background: white; border-radius: 6px; font-size: 12px;">
                        <input type="checkbox" style="width: 16px; height: 16px; cursor: pointer;">
                        <span style="color: #6b7280; flex: 1;">Painting & Finishing</span>
                        <span style="color: #059669; font-size: 11px;">Due: Apr 18</span>
                    </label>
                </div>
                <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; font-size: 11px;">
                    <span style="color: #6b7280;">Progress: <strong style="color: #1f2937;">67% (2/3)</strong></span>
                    <span class="risk-indicator risk-low">
                        <span class="risk-dot" style="background: #059669;"></span>ON TRACK
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Projects Requiring Follow-up Table - Monthly Target Focus -->
    <div class="follow-up-section" id="followUpSection">
        <div class="follow-up-header" style="background: linear-gradient(135deg, #3b82f6, #1e40af); color: white; padding: 15px 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                <i class="bi bi-bullseye"></i> Monthly Target Status
            </h3>
            <a href="{{ route('admin.projects') }}" class="btn-primary" style="padding: 8px 16px; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; transition: all 0.2s; text-decoration: none;">View All</a>
        </div>

        <div class="follow-up-table-container">
            <table class="follow-up-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Project Title</th>
                    <th>Contractor</th>
                    <th>Current</th>
                    <th>Target</th>
                    <th>Gap</th>
                    <th>Activities</th>
                    <th>Status</th>
                    <th style="text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <!-- Project 1 - At Risk -->
                <tr>
                    <td class="project-code">PRJ-2026-015</td>
                    <td>
                        <div class="project-title">Science Building Phase 2</div>
                        <div class="project-category">New Construction</div>
                    </td>
                    <td>ABC Construction Co.</td>
                    <td>
                        <div style="font-weight: 700; color: #1f2937; font-size: 13px;">45%</div>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: #059669; font-size: 13px;">70%</div>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: #dc2626; font-size: 13px;">-25%</div>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 6px; font-size: 12px;">
                            <span style="background: #d1fae5; color: #059669; padding: 2px 6px; border-radius: 4px; font-weight: 600;">2/4</span>
                            <span style="color: #6b7280;">completed</span>
                        </div>
                    </td>
                    <td><span class="status-badge status-delayed">AT RISK</span></td>
                    <td style="text-align: center;">
                        <a href="{{ route('admin.projects') }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>

                <!-- Project 2 - Warning -->
                <tr>
                    <td class="project-code">PRJ-2026-020</td>
                    <td>
                        <div class="project-title">Electrical Installation Phase 1</div>
                        <div class="project-category">Infrastructure</div>
                    </td>
                    <td>TechNet Solutions</td>
                    <td>
                        <div style="font-weight: 700; color: #1f2937; font-size: 13px;">60%</div>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: #059669; font-size: 13px;">80%</div>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: #d97706; font-size: 13px;">-20%</div>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 6px; font-size: 12px;">
                            <span style="background: #fef3c7; color: #92400e; padding: 2px 6px; border-radius: 4px; font-weight: 600;">3/5</span>
                            <span style="color: #6b7280;">completed</span>
                        </div>
                    </td>
                    <td><span class="status-badge status-at-risk">MONITOR</span></td>
                    <td style="text-align: center;">
                        <a href="{{ route('admin.projects') }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>

                <!-- Project 3 - On Track -->
                <tr>
                    <td class="project-code">PRJ-2026-021</td>
                    <td>
                        <div class="project-title">Admin Building Renovation</div>
                        <div class="project-category">Renovation</div>
                    </td>
                    <td>Premier Construction</td>
                    <td>
                        <div style="font-weight: 700; color: #1f2937; font-size: 13px;">70%</div>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: #059669; font-size: 13px;">85%</div>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: #059669; font-size: 13px;">-15%</div>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 6px; font-size: 12px;">
                            <span style="background: #d1fae5; color: #059669; padding: 2px 6px; border-radius: 4px; font-weight: 600;">2/3</span>
                            <span style="color: #6b7280;">completed</span>
                        </div>
                    </td>
                    <td><span class="status-badge status-on-track">ON TRACK</span></td>
                    <td style="text-align: center;">
                        <a href="{{ route('admin.projects') }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>

                <!-- Project 4 - On Track -->
                <tr>
                    <td class="project-code">PRJ-2026-023</td>
                    <td>
                        <div class="project-title">HVAC System Installation</div>
                        <div class="project-category">Systems</div>
                    </td>
                    <td>RoadWorks Corp.</td>
                    <td>
                        <div style="font-weight: 700; color: #1f2937; font-size: 13px;">50%</div>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: #059669; font-size: 13px;">70%</div>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: #059669; font-size: 13px;">-20%</div>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 6px; font-size: 12px;">
                            <span style="background: #d1fae5; color: #059669; padding: 2px 6px; border-radius: 4px; font-weight: 600;">1/4</span>
                            <span style="color: #6b7280;">completed</span>
                        </div>
                    </td>
                    <td><span class="status-badge status-on-track">ON TRACK</span></td>
                    <td style="text-align: center;">
                        <a href="{{ route('admin.projects') }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>

                <!-- Project 5 - No Updates -->
                <tr>
                    <td class="project-code">PRJ-2026-019</td>
                    <td>
                        <div class="project-title">Gymnasium Electrical System</div>
                        <div class="project-category">Repair</div>
                    </td>
                    <td>ElectroPro Services</td>
                    <td>
                        <div style="font-weight: 700; color: #1f2937; font-size: 13px;">60%</div>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: #059669; font-size: 13px;">75%</div>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: #059669; font-size: 13px;">-15%</div>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 6px; font-size: 12px;">
                            <span style="background: #d1fae5; color: #059669; padding: 2px 6px; border-radius: 4px; font-weight: 600;">3/4</span>
                            <span style="color: #6b7280;">completed</span>
                        </div>
                    </td>
                    <td><span class="status-badge status-on-track">ON TRACK</span></td>
                    <td style="text-align: center;">
                        <a href="{{ route('admin.projects') }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Status & Progress Charts -->
    <div class="row g-3">
        <!-- Project Status Distribution -->
        <div class="col-lg-6">
            <div class="chart-container">
                <div style="font-weight: 600; color: #1f2937; font-size: 15px; margin-bottom: 16px;">
                    <i class="bi bi-pie-chart-fill" style="color: #2563eb; margin-right: 8px;"></i>Project Status Distribution
                </div>

                <div class="progress-section">
                    <div class="progress-label">
                        <span>Ongoing</span>
                        <span style="color: #6b7280;">8 projects</span>
                    </div>
                    <div class="progress-bar-custom">
                        <div class="fill" style="width: 35%; background: #f59e0b;"></div>
                    </div>
                </div>

                <div class="progress-section">
                    <div class="progress-label">
                        <span>Planned</span>
                        <span style="color: #6b7280;">4 projects</span>
                    </div>
                    <div class="progress-bar-custom">
                        <div class="fill" style="width: 17%; background: #3b82f6;"></div>
                    </div>
                </div>

                <div class="progress-section">
                    <div class="progress-label">
                        <span>Completed</span>
                        <span style="color: #6b7280;">10 projects</span>
                    </div>
                    <div class="progress-bar-custom">
                        <div class="fill" style="width: 43%; background: #10b981;"></div>
                    </div>
                </div>

                <div class="progress-section">
                    <div class="progress-label">
                        <span>Suspended</span>
                        <span style="color: #6b7280;">1 project</span>
                    </div>
                    <div class="progress-bar-custom">
                        <div class="fill" style="width: 5%; background: #ef4444;"></div>
                    </div>
                </div>

                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #f3f4f6; display: flex; justify-content: space-between;">
                    <span style="color: #6b7280; font-weight: 500;">Total Projects</span>
                    <span style="font-weight: 700; color: #1f2937; font-size: 18px;">23</span>
                </div>
            </div>
        </div>

        <!-- Progress Breakdown -->
        <div class="col-lg-6">
            <div class="chart-container">
                <div style="font-weight: 600; color: #1f2937; font-size: 15px; margin-bottom: 16px;">
                    <i class="bi bi-bar-chart-fill" style="color: #2563eb; margin-right: 8px;"></i>Progress Breakdown (Ongoing)
                </div>

                <div class="row text-center g-3" style="margin-bottom: 16px;">
                    <div class="col-6 col-md-3">
                        <div style="padding: 16px; border-radius: 10px; background: #fee2e2;">
                            <div style="font-size: 28px; font-weight: 700; color: #dc2626; line-height: 1;">2</div>
                            <div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500;">0-25%</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div style="padding: 16px; border-radius: 10px; background: #fef3c7;">
                            <div style="font-size: 28px; font-weight: 700; color: #d97706; line-height: 1;">3</div>
                            <div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500;">26-50%</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div style="padding: 16px; border-radius: 10px; background: #d1fae5;">
                            <div style="font-size: 28px; font-weight: 700; color: #059669; line-height: 1;">2</div>
                            <div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500;">51-75%</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div style="padding: 16px; border-radius: 10px; background: #dbeafe;">
                            <div style="font-size: 28px; font-weight: 700; color: #2563eb; line-height: 1;">1</div>
                            <div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500;">76-99%</div>
                        </div>
                    </div>
                </div>

                <div style="padding-top: 16px; border-top: 1px solid #f3f4f6;">
                    <div style="font-weight: 600; color: #1f2937; font-size: 14px; margin-bottom: 12px;">
                        <i class="bi bi-tags-fill" style="color: #6b7280; margin-right: 8px;"></i>By Category
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                        <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f3f4f6; border-radius: 6px; font-size: 12px; font-weight: 500; color: #374151;">
                            <i class="bi bi-building" style="color: #2563eb;"></i> New Construction: 5
                        </span>
                        <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f3f4f6; border-radius: 6px; font-size: 12px; font-weight: 500; color: #374151;">
                            <i class="bi bi-hammer" style="color: #10b981;"></i> Renovation: 3
                        </span>
                        <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f3f4f6; border-radius: 6px; font-size: 12px; font-weight: 500; color: #374151;">
                            <i class="bi bi-tools" style="color: #d97706;"></i> Repair: 2
                        </span>
                        <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f3f4f6; border-radius: 6px; font-size: 12px; font-weight: 500; color: #374151;">
                            <i class="bi bi-wrench" style="color: #7e22ce;"></i> Maintenance: 1
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{--  
<!-- ===== CAMPUS MAP WIDGET ===== -->
<div class="dashboard-zone" style="margin: 30px 0;">
    @include('admin.components.campus-map-widget')
</div>
--}}
<!-- ===== ZONE 3: CONTRACTOR INTELLIGENCE ===== -->
<div class="dashboard-zone">
    <div class="zone-header">
        <i class="bi bi-people-fill"></i>
        Contractor Intelligence
    </div>

    <!-- Contractor Performance Panel -->
    <div class="contractor-performance">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div style="font-weight: 600; color: #1f2937; font-size: 15px;">
                <i class="bi bi-graph-up" style="color: #2563eb; margin-right: 8px;"></i>Contractor Performance Summary
            </div>
            <a href="{{ route('admin.projects') }}" class="btn btn-sm btn-outline-primary">View Details</a>
        </div>

        <div class="contractor-grid">
            <!-- Contractor 1 -->
            <div class="contractor-card">
                <div class="contractor-header">
                    <div class="contractor-avatar" style="background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);">AC</div>
                    <div class="contractor-name">
                        <div class="title">ABC Construction Co.</div>
                        <div class="projects">2 active projects</div>
                    </div>
                    <span class="contractor-risk medium">Medium Risk</span>
                </div>

                <div class="contractor-metrics">
                    <div class="metric-row">
                        <span class="label">Avg. Progress</span>
                        <span class="value">48%</span>
                    </div>
                    <div class="metric-bar">
                        <div class="metric-bar-track">
                            <div class="metric-bar-fill" style="width: 48%;"></div>
                        </div>
                    </div>

                    <div class="metric-row" style="margin-top: 10px;">
                        <span class="label">On-Time %</span>
                        <span class="value">60%</span>
                    </div>
                    <div class="metric-bar">
                        <div class="metric-bar-track">
                            <div class="metric-bar-fill" style="width: 60%; background: #f59e0b;"></div>
                        </div>
                    </div>

                    <div class="metric-row" style="margin-top: 10px;">
                        <span class="label">Last Update</span>
                        <span class="value" style="font-size: 13px;">2 days ago</span>
                    </div>
                </div>
            </div>

            <!-- Contractor 2 -->
            <div class="contractor-card">
                <div class="contractor-header">
                    <div class="contractor-avatar" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">XB</div>
                    <div class="contractor-name">
                        <div class="title">XYZ Builders Inc.</div>
                        <div class="projects">1 active project</div>
                    </div>
                    <span class="contractor-risk high">High Risk</span>
                </div>

                <div class="contractor-metrics">
                    <div class="metric-row">
                        <span class="label">Avg. Progress</span>
                        <span class="value">30%</span>
                    </div>
                    <div class="metric-bar">
                        <div class="metric-bar-track">
                            <div class="metric-bar-fill" style="width: 30%; background: #ef4444;"></div>
                        </div>
                    </div>

                    <div class="metric-row" style="margin-top: 10px;">
                        <span class="label">On-Time %</span>
                        <span class="value">40%</span>
                    </div>
                    <div class="metric-bar">
                        <div class="metric-bar-track">
                            <div class="metric-bar-fill" style="width: 40%; background: #ef4444;"></div>
                        </div>
                    </div>

                    <div class="metric-row" style="margin-top: 10px;">
                        <span class="label">Last Update</span>
                        <span class="value" style="font-size: 13px;">5 days ago</span>
                    </div>
                </div>
            </div>

            <!-- Contractor 3 -->
            <div class="contractor-card">
                <div class="contractor-header">
                    <div class="contractor-avatar" style="background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%);">PC</div>
                    <div class="contractor-name">
                        <div class="title">Premier Construction</div>
                        <div class="projects">2 active projects</div>
                    </div>
                    <span class="contractor-risk low">Low Risk</span>
                </div>

                <div class="contractor-metrics">
                    <div class="metric-row">
                        <span class="label">Avg. Progress</span>
                        <span class="value">62%</span>
                    </div>
                    <div class="metric-bar">
                        <div class="metric-bar-track">
                            <div class="metric-bar-fill" style="width: 62%;"></div>
                        </div>
                    </div>

                    <div class="metric-row" style="margin-top: 10px;">
                        <span class="label">On-Time %</span>
                        <span class="value">85%</span>
                    </div>
                    <div class="metric-bar">
                        <div class="metric-bar-track">
                            <div class="metric-bar-fill" style="width: 85%; background: #10b981;"></div>
                        </div>
                    </div>

                    <div class="metric-row" style="margin-top: 10px;">
                        <span class="label">Last Update</span>
                        <span class="value" style="font-size: 13px;">1 day ago</span>
                    </div>
                </div>
            </div>

            <!-- Contractor 4 -->
            <div class="contractor-card">
                <div class="contractor-header">
                    <div class="contractor-avatar" style="background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);">TN</div>
                    <div class="contractor-name">
                        <div class="title">TechNet Solutions</div>
                        <div class="projects">1 active project</div>
                    </div>
                    <span class="contractor-risk low">Low Risk</span>
                </div>

                <div class="contractor-metrics">
                    <div class="metric-row">
                        <span class="label">Avg. Progress</span>
                        <span class="value">75%</span>
                    </div>
                    <div class="metric-bar">
                        <div class="metric-bar-track">
                            <div class="metric-bar-fill" style="width: 75%;"></div>
                        </div>
                    </div>

                    <div class="metric-row" style="margin-top: 10px;">
                        <span class="label">On-Time %</span>
                        <span class="value">90%</span>
                    </div>
                    <div class="metric-bar">
                        <div class="metric-bar-track">
                            <div class="metric-bar-fill" style="width: 90%; background: #10b981;"></div>
                        </div>
                    </div>

                    <div class="metric-row" style="margin-top: 10px;">
                        <span class="label">Last Update</span>
                        <span class="value" style="font-size: 13px;">Today</span>
                    </div>
                </div>
            </div>

            <!-- Contractor 5 -->
            <div class="contractor-card">
                <div class="contractor-header">
                    <div class="contractor-avatar" style="background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);">EP</div>
                    <div class="contractor-name">
                        <div class="title">ElectroPro Services</div>
                        <div class="projects">1 active project</div>
                    </div>
                    <span class="contractor-risk low">Low Risk</span>
                </div>

                <div class="contractor-metrics">
                    <div class="metric-row">
                        <span class="label">Avg. Progress</span>
                        <span class="value">60%</span>
                    </div>
                    <div class="metric-bar">
                        <div class="metric-bar-track">
                            <div class="metric-bar-fill" style="width: 60%;"></div>
                        </div>
                    </div>

                    <div class="metric-row" style="margin-top: 10px;">
                        <span class="label">On-Time %</span>
                        <span class="value">75%</span>
                    </div>
                    <div class="metric-bar">
                        <div class="metric-bar-track">
                            <div class="metric-bar-fill" style="width: 75%; background: #10b981;"></div>
                        </div>
                    </div>

                    <div class="metric-row" style="margin-top: 10px;">
                        <span class="label">Last Update</span>
                        <span class="value" style="font-size: 13px;">3 days ago</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity Section -->
    <div class="activity-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div style="font-weight: 600; color: #1f2937; font-size: 15px;">
                <i class="bi bi-clock-history" style="color: #2563eb; margin-right: 8px;"></i>Recent Activity
            </div>
            <a href="{{ route('admin.activity.logs') }}" class="btn btn-sm btn-outline-primary">View All</a>
        </div>

        <div class="row g-3">
            <!-- Activity Column 1 -->
            <div class="col-md-6">
                <div class="activity-item">
                    <div class="activity-icon progress">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-text">
                            <span style="font-weight: 600;">PRJ-2026-020</span> progress updated to <span style="background: #d1f8e6; padding: 2px 6px; border-radius: 4px; font-weight: 600; color: #059669;">75%</span>
                        </div>
                        <div class="activity-time">2 hours ago by TechNet Solutions</div>
                    </div>
                </div>

                <div class="activity-item">
                    <div class="activity-icon complete">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-text">
                            Milestone <span style="font-weight: 600;">"Electrical Wiring"</span> completed in PRJ-2026-019
                        </div>
                        <div class="activity-time">5 hours ago by ElectroPro Services</div>
                    </div>
                </div>

                <div class="activity-item">
                    <div class="activity-icon update">
                        <i class="bi bi-pencil-square"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-text">
                            Project <span style="font-weight: 600;">PRJ-2026-021</span> details updated by PMU Staff
                        </div>
                        <div class="activity-time">Yesterday at 4:30 PM</div>
                    </div>
                </div>
            </div>

            <!-- Activity Column 2 -->
            <div class="col-md-6">
                <div class="activity-item">
                    <div class="activity-icon create">
                        <i class="bi bi-plus-circle"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-text">
                            New project <span style="font-weight: 600;">"Auditorium Sound System"</span> created
                        </div>
                        <div class="activity-time">Yesterday at 2:15 PM by PMU Director</div>
                    </div>
                </div>

                <div class="activity-item">
                    <div class="activity-icon alert">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-text">
                            Milestone <span style="font-weight: 600;">"Foundation Work"</span> marked as delayed
                        </div>
                        <div class="activity-time">2 days ago • Est. +5 days behind schedule</div>
                    </div>
                </div>

                <div class="activity-item">
                    <div class="activity-icon" style="background: #f0fdf4; color: #16a34a;">
                        <i class="bi bi-building"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-text">
                            New contractor <span style="font-weight: 600;">AudioPro Inc.</span> registered in system
                        </div>
                        <div class="activity-time">2 days ago by PMU Administrator</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Calendar Widget -->
    @include('admin.calendar-widget')

    <!-- Quick Admin Links -->
    <div class="quick-links-grid">
        <a href="{{ route('users.index') }}" class="quick-link-card">
            <div class="quick-link-icon" style="background: #eff6ff; color: #2563eb;">
                <i class="bi bi-people"></i>
            </div>
            <div class="quick-link-title">Manage Staff Accounts</div>
            <div class="quick-link-desc">Add, edit, or remove PMU staff users</div>
        </a>

        <a href="{{ route('admin.projects') }}" class="quick-link-card">
            <div class="quick-link-icon" style="background: #f0fdf4; color: #16a34a;">
                <i class="bi bi-folder-plus"></i>
            </div>
            <div class="quick-link-title">Project Management</div>
            <div class="quick-link-desc">View, create, and manage all projects</div>
        </a>

        <a href="{{ route('admin.activity.logs') }}" class="quick-link-card">
            <div class="quick-link-icon" style="background: #fef3c7; color: #d97706;">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="quick-link-title">Activity Logs</div>
            <div class="quick-link-desc">View system activity history and audit trail</div>
        </a>
    </div>
</div>

@endsection
