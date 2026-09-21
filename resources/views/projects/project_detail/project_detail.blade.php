@php
    $layout = match(auth()->user()->role) {
        'contractor' => 'layouts.contractorlayouts',
        'staff' => 'layouts.stafflayouts',
        default => 'layouts.app'
    };
@endphp
@extends($layout)

@section('content')

<!-- Back Button -->
<div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
    @php
        $backRoute = match(auth()->user()->role) {
            'contractor' => route('contractor.projects'),
            'staff' => route('staff.projects'),
            default => route('admin.projects')  // admin
        };
    @endphp
    <a href="{{ $backRoute }}" class="btn btn-secondary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back to Projects
    </a>

</div>


<!-- Header & Stats -->
@include('projects.project_detail.components.header', ['project' => $project, 'data' => $data])
@include('projects.project_detail.components.stats-row', ['project' => $project, 'data' => $data])

<!-- TAB NAVIGATION -->
<div class="tab-navigation">
    <button class="tab-btn active" data-tab="overview" onclick="switchMainTab('overview', event)">
        <i class="bi bi-info-circle" style="margin-right: 6px; font-size: 14px;"></i>
        Overview
    </button>

    <button class="tab-btn" data-tab="s-curve-planning" onclick="switchMainTab('s-curve-planning', event)">
        <i class="bi bi-calendar-check" style="margin-right: 6px; font-size: 14px;"></i>
        Baseline & Activities
    </button>
    <button class="tab-btn" data-tab="progress" onclick="switchMainTab('progress', event)">
        <i class="bi bi-graph-up" style="margin-right: 6px; font-size: 14px;"></i>
        Progress Tracking
    </button>

    <button class="tab-btn" data-tab="documents" onclick="switchMainTab('documents', event)">
        <i class="bi bi-file-earmark-pdf" style="margin-right: 6px; font-size: 14px;"></i>
        Documents
    </button>

    <button class="tab-btn" data-tab="issues" onclick="switchMainTab('issues', event)">
        <i class="bi bi-exclamation-circle" style="margin-right: 6px; font-size: 14px;"></i>
        {{-- Was "Issues & Risks" — the risk register is hidden (textfiles/risk_register_hide_plan.md) --}}
        Issues
    </button>
</div>

<!-- TAB CONTENTS -->
@include('projects.project_detail.components.tabs.overview', ['project' => $project, 'data' => $data, 'contractors' => $contractors])
@php
    $routePrefix = auth()->user()->role === 'admin' ? 'admin.projects' : (auth()->user()->role === 'staff' ? 'staff.projects' : 'contractor.projects');
    $isAssignedStaff = $project->isAssignedTo(auth()->user());
@endphp
@include('projects.project_detail.components.tabs.baseline-schedule', ['project' => $project, 'routePrefix' => $routePrefix, 'isAssignedStaff' => $isAssignedStaff])
@include('projects.project_detail.components.tabs.progress', ['project' => $project, 'data' => $data])
@include('projects.project_detail.components.tabs.documents', ['project' => $project, 'data' => $data])
@include('projects.project_detail.components.tabs.issues', ['project' => $project, 'isAssignedStaff' => $isAssignedStaff])

<!-- MODALS -->

@can('update', $project)
    <!-- Include project add/edit modal -->
    @php
        $routePrefix = auth()->user()->role === 'admin' ? 'admin.projects' : 'staff.projects';
    @endphp
    @include('admin._create_project_modal', ['admins' => $admins, 'staff' => $staff, 'routePrefix' => $routePrefix, 'documentTypes' => $documentTypes ?? []])
    @include('projects.project_detail.components.modals.upload_document_modal', [
        'documentTypes' => $documentTypes ?? [],
    ])
    
    @if(auth()->user()->role === 'admin')
        <!-- Include category management modal -->
        @include('admin._manage_categories_modal')
        @include('admin._manage_budget_sources_modal')
        @include('admin._manage_document_types_modal')
    @endif
@endcan

@php
    $hasBaseline = $project->baselineSchedules()->exists();
@endphp

<!-- Include project extension modal (only if baseline exists) -->
@if($hasBaseline)
@include('projects.project_detail.components.modals.request_extension_modal')
@if(auth()->user()->role === 'admin' || $isAssignedStaff)
@include('projects.project_detail.components.modals.review_extension_modal')
@endif
@endif

<!-- Include issues modal -->
@include('projects.project_detail.components.modals.issues_modal')

@endsection

<script>
window.projectId = {{ $project->id }};
const projectId = window.projectId;
const csrfToken = '{{ csrf_token() }}';
window.csrfToken = csrfToken;
window.userRole = '{{ auth()->user()->role }}';
window.authUserId = {{ auth()->id() }};
window.isAssignedStaff = {{ $isAssignedStaff ? 'true' : 'false' }};

// Build the correct API base URL based on user role
function getIssuesApiUrl(action = '') {
    const role = window.userRole;
    const baseUrl = `/${role}/projects/${projectId}/issues`;
    return action ? `${baseUrl}/${action}` : baseUrl;
}

{{-- RISK REGISTER HIDDEN (textfiles/risk_register_hide_plan.md) — API URL helper used only by risk-register.js
// Build the correct Risk Register API base URL based on user role
function getRisksApiUrl(action = '') {
    const role = window.userRole;
    const baseUrl = `/${role}/projects/${projectId}/risks`;
    return action ? `${baseUrl}/${action}` : baseUrl;
}
--}}

let updateProgressModal;

document.addEventListener('DOMContentLoaded', function() {
    
    updateProgressModal = new bootstrap.Modal(document.getElementById('updateProgressModal'));
    
    // Load initial issues data
    if (typeof loadTabIssues === 'function') {
        loadTabIssues();
    }
    
    // Restore the active tab if it was stored before page reload
    const activeTab = sessionStorage.getItem('activeTab');
    if (activeTab) {
        switchMainTab(activeTab);
        sessionStorage.removeItem('activeTab');
    } else if (location.hash) {
        // Notification deep-link: map hash anchors to tab names.
        const hashTabMap = {
            '#overview': 'overview',
            '#s-curve-planning': 's-curve-planning',
            '#baseline-schedule': 's-curve-planning',
            '#extensions': 's-curve-planning',
            '#progress': 'progress',
            '#documents': 'documents',
            '#issues': 'issues',
            '#risks': 'issues',
        };
        const tabName = hashTabMap[location.hash] || 'overview';
        switchMainTab(tabName);
    }
});

// ==================== TAB NAVIGATION FUNCTION ====================
function switchMainTab(tabName, event) {
    // Guard: if the Set Up Baseline modal is open with unsaved months,
    // warn before switching tabs (data would be lost).
    var setupModalEl = document.getElementById('setupBaselineModal');
    if (setupModalEl && setupModalEl.classList.contains('show') &&
        typeof setupLocalMonths !== 'undefined' && setupLocalMonths.length > 0) {
        if (event) event.preventDefault();
        var count = setupLocalMonths.length;
        showDeleteConfirmation(
            'Discard Unsaved Baseline?',
            'You have added <strong>' + count + '</strong> month target' +
            (count > 1 ? 's' : '') +
            ' that ' + (count > 1 ? 'have' : 'has') +
            ' not been saved yet. Switching tabs will discard this data.',
            function () {
                setupLocalMonths = [];
                setupCurrentMaxPercent = 0;
                var modal = bootstrap.Modal.getInstance(setupModalEl);
                if (modal) modal.hide();
                switchMainTab(tabName);
            }
        );
        return;
    }

    if (event) {
        event.preventDefault();
    }
    
    // Hide all tab content
    const tabContents = document.querySelectorAll('.tab-content');
    tabContents.forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    const tabButtons = document.querySelectorAll('.tab-btn');
    tabButtons.forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show the selected tab content
    const selectedContent = document.getElementById(tabName);
    if (selectedContent) {
        selectedContent.classList.add('active');
    }
    
    // Add active class to clicked button
    const selectedButton = document.querySelector(`[data-tab="${tabName}"]`);
    if (selectedButton) {
        selectedButton.classList.add('active');
    }
    
    // Re-initialize charts if needed
    if (tabName === 'progress') {
        window.dispatchEvent(new Event('resize'));
        // Lazy load chart data on first visit to the Progress tab
        if (typeof loadProgressChartData === 'function') {
            loadProgressChartData();
        }
    }
    
    // Load issues tab data when switched to issues tab
    if (tabName === 'issues') {
        if (typeof loadTabIssues === 'function') {
            loadTabIssues();
        }
        {{-- RISK REGISTER HIDDEN — risk list loader (defined in risk-register.js)
        if (typeof loadTabRisks === 'function') {
            loadTabRisks();
        }
        --}}
    }
}

// ==================== PROGRESS HISTORY FUNCTIONS ====================
// Edit/delete functions are now in baseline-schedule-edit-progress-modal.js
// This file keeps a page-level reference helper for the modal.

@php
    $progressRecords = $project->progressHistory->load('recordedBy');
    $progressRecords->each->setAppends(['recorded_by_name', 'activity_names']);
@endphp
window._progressRecordData = @json($progressRecords->keyBy('id'));

// ==================== UTILITY FUNCTIONS ====================
function switchTab(el, tabId) {
    el.parentElement.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    el.closest('.card').querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
}
</script>

{{-- Include Proposal Detail Modal if a proposal is linked --}}
@if($project->proposal)
    @include('projects.project_detail._proposal_detail_modal')
@endif

{{-- Show the create/edit success toast AFTER the post-save redirect to this page
     (flash set by ProjectController@store/update → session('toast_success')). --}}
@if(session('toast_success'))
<script>
    document.addEventListener('DOMContentLoaded', function() {
        window.showToast(@json(session('toast_success')), 'success');
    });
</script>
@endif