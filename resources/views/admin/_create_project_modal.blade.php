@php
    // Set default route prefix if not provided (for backward compatibility)
    $routePrefix = $routePrefix ?? 'admin.projects';
@endphp
<!-- === CREATE/EDIT PROJECT MODAL === -->
{{-- 2026-09-21 redesign (pilot plan §8): a FIXED-SIZE dialog. The header, the step
     bar, the error strip and the footer never move; only the page area (.modal-body)
     scrolls. Things the parser inserts while it works — the scan strip, the result
     banner, the per-field "filled" notes — therefore scroll inside the page instead
     of making the whole dialog grow and shrink. Styles: _create_project_modal.css
     (the "CPM" section). Every id, name and handler the scripts use is unchanged.
     Second pass (same day, user: "not make the user scroll as much as possible"):
     extra-large and taller dialog, no per-page heading (the step bar already names
     the page), fields three to a row, short help text beside the label instead of
     on its own line. --}}

<div class="modal fade" id="addProjectModal" tabindex="-1" aria-hidden="true" aria-labelledby="projectModalTitle" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-centered cpm-dialog">
        <div class="modal-content cpm-content">

            <!-- Header -->
            <div class="cpm-header">
                <div class="cpm-header-icon"><i class="bi bi-plus-circle" id="projectModalIcon"></i></div>
                <div class="cpm-header-text">
                    <h5 class="cpm-title" id="projectModalTitle"><span id="projectModalTitleText">Create Project</span></h5>
                    {{-- Text switched per mode by _create_project_modal.js (create / edit). --}}
                    <p class="cpm-subtitle" id="projectModalSubtitle">Add the project documents first — the details can be read from them for you.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Step bar (fixed — never scrolls away) -->
            <div class="cpm-steps">
                <div class="step-wizard" role="list" aria-label="Form steps">
                    <div class="step-item current" id="step1-indicator" role="listitem">
                        <div class="step-dot">1</div>
                        <div class="step-label" id="step1-label">Documents</div>
                    </div>
                    <div class="step-connector" id="step1-connector"></div>
                    <div class="step-item upcoming" id="step2-indicator" role="listitem">
                        <div class="step-dot">2</div>
                        <div class="step-label" id="step2-label">Project Info</div>
                    </div>
                    <div class="step-connector" id="step2-connector"></div>
                    <div class="step-item upcoming" id="step3-indicator" role="listitem">
                        <div class="step-dot">3</div>
                        <div class="step-label" id="step3-label">Description</div>
                    </div>
                    <div class="step-connector" id="step3-connector"></div>
                    <div class="step-item upcoming" id="step4-indicator" role="listitem">
                        <div class="step-dot">4</div>
                        <div class="step-label" id="step4-label">Project Setup</div>
                    </div>
                    <div class="step-connector" id="step4-connector"></div>
                    <div class="step-item upcoming" id="step5-indicator" role="listitem">
                        <div class="step-dot">5</div>
                        <div class="step-label" id="step5-label">Timeline</div>
                    </div>
                    <div class="step-connector" id="step5-connector"></div>
                    <div class="step-item upcoming" id="step6-indicator" role="listitem">
                        <div class="step-dot">6</div>
                        <div class="step-label" id="step6-label">Assignment</div>
                    </div>
                </div>
            </div>

            <!-- Error strip (fixed, above the scrolling page — it takes space from the
                 page area, never from the dialog). Shown by showError() / document-manager.js. -->
            <div id="createProjectError" class="alert alert-danger cpm-error utility-none" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <span id="createProjectErrorMessage"></span>
            </div>

            <!-- Page area: the ONLY scrolling region (form-navigation.js scrolls it to the top per step) -->
            <div class="modal-body cpm-body">
                <form id="createProjectForm" method="POST" action="{{ route($routePrefix . '.store') }}" enctype="multipart/form-data">
                    @csrf
                    <!-- Method field for PUT when editing -->
                    <input type="hidden" name="_method" id="formMethodInput" value="POST">
                    <!-- Hidden field for project_id when editing -->
                    <input type="hidden" name="project_id" id="projectIdInput" value="">
                    <!-- Track current page -->
                    <input type="hidden" id="currentPage" value="1">

                    <!-- === PAGE 1: DOCUMENTS === -->
                    <div id="page1" class="form-page active">
                        <div class="cpm-card">
                            <p class="cpm-card-hint">Upload the contract, proposal or other papers. Choose the document type first, then select file(s) — they are added automatically (max 20MB each). On the next step their details are read and offered for you to check.</p>
                            <div class="row gx-3 gy-2">
                                <!-- Document Type Selector (must be chosen first) -->
                                <div class="col-md-4">
                                    <label class="form-label" for="documentTypeSelect">Document Type <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <select id="documentTypeSelect" class="form-select">
                                            <option value="">-- Select Type --</option>
                                            @forelse($documentTypes ?? [] as $type)
                                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                                            @empty
                                                <option value="">Loading types...</option>
                                            @endforelse
                                        </select>
                                        @can('create', App\Models\Category::class)
                                        <button class="btn btn-outline-primary" type="button" id="addDocumentTypeBtn"
                                                title="Add new document type" onclick="openManageDocTypesFromCreateProject()">
                                            <i class="bi bi-plus-circle"></i>
                                        </button>
                                        @endcan
                                    </div>
                                </div>

                                <!-- File Upload Input (auto-added on selection) -->
                                <div class="col-md-8">
                                    <label class="form-label" for="documentFileInput">Select File(s)</label>
                                    <input type="file" id="documentFileInput" class="form-control" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                                </div>
                            </div>

                            <!-- Documents List (chip preview) -->
                            <div id="documentsListContainer" class="d-none cpm-doc-list">
                                <label class="form-label">Added Documents</label>
                                <div id="documentsTableBody" class="d-flex flex-wrap gap-1"></div>
                            </div>
                        </div>

                        <!-- Autofill preview (runs extraction without navigating) -->
                        <div class="cpm-page-actions">
                            {{-- L2 learned-keywords review desk launcher (staff + admin; contractors never open this modal) --}}
                            @if(auth()->check() && in_array(auth()->user()->role, ['admin', 'staff']))
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openManageParserKeywords()"
                                    title="Review wording the document reader learned from corrections">
                                <i class="bi bi-magic"></i> Learned Keywords
                            </button>
                            @endif
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="runDocumentAutofill()">
                                <i class="bi bi-eye"></i> Preview Extracted Data
                            </button>
                        </div>
                    </div>

                    <!-- === PAGE 2: PROJECT INFORMATION === -->
                    <div id="page2" class="form-page">
                        <div class="cpm-card">
                            <div class="row gx-3 gy-2">
                                <div class="col-md-12">
                                    <label class="form-label" for="projectTitle">Project Title <span class="text-danger">*</span></label>
                                    <input type="text" name="title" id="projectTitle" class="form-control"
                                           placeholder="Project title" required>
                                    <div id="projectTitleFeedback" class="field-feedback"></div>
                                    <div class="invalid-feedback" id="titleError"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="projectCode">Project Code <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="text" id="projectCode" name="code" class="form-control" placeholder="e.g., 2026-00001"
                                               pattern="^PRJ-\d{4}-\d{5}$|^\d{5}$" maxlength="14" required>
                                        <button class="btn btn-outline-secondary" type="button" id="generateRandomCodeBtn" title="Generate Random Code">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </button>
                                    </div>
                                    {{-- Text rewritten by _create_project_modal.js per mode (create / edit). --}}
                                    <small class="text-muted cpm-help" id="projectCodeHelp">Format: PRJ-YYYY-NNNNN (auto-filled or enter manually)</small>
                                    <div id="projectCodeFeedback" class="field-feedback"></div>
                                    <div class="invalid-feedback" id="codeError"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="projectCategory">Category <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <select name="category_id" id="projectCategory" class="form-select" required>
                                            <option value="">-- Select --</option>
                                        </select>
                                        @can('create', App\Models\Category::class)
                                        <button class="btn btn-outline-primary" type="button" id="addCategoryBtn"
                                                title="Add new category" onclick="openManageCategoriesFromCreateProject()">
                                            <i class="bi bi-plus-circle"></i>
                                        </button>
                                        @endcan
                                    </div>
                                    <div class="invalid-feedback" id="categoryError"></div>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label" for="projectLocation">Location</label>
                                    <input type="text" name="location" id="projectLocation" class="form-control" placeholder="Project location">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- === PAGE 3: DESCRIPTION === -->
                    <div id="page3" class="form-page">
                        <div class="cpm-card">
                            <label class="form-label" for="projectDescription">Project Description <span class="cpm-label-note">the scope of work</span></label>
                            <textarea name="description" id="projectDescription" class="form-control cpm-description"
                                      rows="12" placeholder="Project description..."></textarea>
                            <div class="invalid-feedback"></div>
                        </div>
                    </div>

                    <!-- === PAGE 4: BUDGET, CONTRACTOR & STATUS === -->
                    <div id="page4" class="form-page">
                        <div class="cpm-card">
                            <div class="row gx-3 gy-2">
                                <div class="col-md-6">
                                    <label class="form-label" for="projectAbc">ABC <span class="text-danger">*</span> <span class="cpm-label-note">Approved Budget for the Contract</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" step="0.01" name="abc" id="projectAbc" class="form-control"
                                           placeholder="0.00">
                                    </div>
                                    <div class="invalid-feedback"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="projectBudget">Contract Price <span class="text-danger">*</span> <span class="cpm-label-note">winning bid</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" step="0.01" name="approved_budget" id="projectBudget" class="form-control"
                                           placeholder="0.00" required>
                                    </div>
                                    <div class="invalid-feedback"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="projectBudgetSource">Budget Source</label>
                                    <div class="input-group">
                                        <select name="budget_source_id" id="projectBudgetSource" class="form-select">
                                            <option value="">-- Select Source --</option>
                                        </select>
                                        @can('create', App\Models\BudgetSource::class)
                                        <button class="btn btn-outline-primary" type="button" id="addBudgetSourceBtn"
                                                title="Add new budget source" onclick="openManageBudgetSourcesFromCreateProject()">
                                            <i class="bi bi-plus-circle"></i>
                                        </button>
                                        @endcan
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="projectStatus">Project Status</label>
                                    <select name="status" id="projectStatus" class="form-select">
                                        <option value="Planned" selected>Planned</option>
                                        <option value="Ongoing">Ongoing</option>
                                        <option value="Completed">Completed</option>
                                        <option value="Suspended">Suspended</option>
                                        <option value="Cancelled">Cancelled</option>
                                    </select>
                                    <div class="invalid-feedback"></div>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label" for="contractorSelect">Contractor</label>
                                    <select name="contractor_id" id="contractorSelect" class="form-select">
                                        <option value="">-- Select Contractor --</option>
                                        @foreach($contractors as $c)
                                            <option value="{{ $c->id }}">{{ $c->company_name ?: ($c->contractor_name ?: ($c->user?->name ?? 'Unnamed')) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- === PAGE 5: TIMELINE === -->
                    <div id="page5" class="form-page">
                        <div class="cpm-card">
                            <div class="row gx-3 gy-2">
                                <div class="col-md-6">
                                    <label class="form-label" for="plannedStartDate">Contract Start Date <span class="text-danger">*</span></label>
                                    <input type="date" id="plannedStartDate" name="planned_start_date" class="form-control" required>
                                    <div class="invalid-feedback" style="display: none;">Contract start date is required</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="plannedDuration">Duration (days) <span class="cpm-label-note">or an end date</span></label>
                                    <input type="number" id="plannedDuration" name="planned_duration_days" class="form-control" min="1">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="plannedEndDate">Planned End Date <span class="text-danger">*</span> <span class="cpm-label-note">auto-calculated</span></label>
                                    <input type="date" id="plannedEndDate" name="planned_end_date" class="form-control" required>
                                    <div class="invalid-feedback" style="display: none;">Planned end date is required</div>
                                </div>
                            </div>
                            {{-- One card, split by a thin divider (third pass) — a second card cost a row of height. --}}
                            <div class="cpm-card-divider">Actual dates <span class="cpm-card-title-note">optional</span></div>
                            <div class="row gx-3 gy-2">
                                <div class="col-md-6">
                                    <label class="form-label" for="projectActualStartDate">Actual Start Date <span class="cpm-label-note" title="Only applicable for ongoing or completed projects">ongoing / completed</span></label>
                                    <input type="date" name="actual_start_date" id="projectActualStartDate" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="projectActualEndDate">Actual End Date <span class="cpm-label-note" title="Only applicable for completed projects">completed only</span></label>
                                    <input type="date" name="actual_end_date" id="projectActualEndDate" class="form-control">
                                </div>
                            </div>
                        </div>
                        <!-- === PROJECT PROGRESS BASELINE SETUP === -->
                        {{-- Inline display styles below are toggled by _create_project_modal.js and
                             scurve-import-ui.js (style.display) — keep them inline. --}}
                        <div class="cpm-card cpm-baseline" id="baselineSetupSection">
                            <div class="cpm-baseline-row">
                                <div class="cpm-baseline-text">
                                    <div class="cpm-card-title"><i class="bi bi-graph-up"></i> Monthly Progress Targets</div>
                                    <div id="baselineSetupEmpty" class="cpm-baseline-note" style="display: flex;">
                                        <span>Set up monthly progress targets with activities. You can add more after the project is created.</span>
                                    </div>
                                    <div id="baselineSetupComplete" class="cpm-baseline-done" style="display: none;">
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span id="baselineSetupCount">0</span> month(s) set up
                                    </div>
                                </div>
                                <div class="cpm-baseline-actions">
                                    <button type="button" onclick="openBaselineSetupModal()" class="btn btn-sm cpm-btn-teal">
                                        <i class="bi bi-flag-fill"></i> Set Up Baseline
                                    </button>
                                    <button type="button" id="scurveImportBtn" class="btn btn-sm cpm-btn-teal" data-mode="" data-parse-url="{{ route($routePrefix . '.s-curve.parse') }}" style="display: none;">
                                        <i class="bi bi-cloud-arrow-up-fill"></i> Import from S-curve
                                    </button>
                                    <button type="button" id="clearBaselineBtn" class="btn btn-sm btn-outline-danger" onclick="clearBaselineSetup()" style="display: none;">
                                        <i class="bi bi-trash"></i> Clear Baseline
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- === PAGE 6: ASSIGNMENT & DETAILS === -->
                    <div id="page6" class="form-page">
                        <div class="cpm-card">
                            <div class="row gx-3 gy-2">
                                <div class="col-md-6">
                                    <label class="form-label" for="projectManager">Project In Charge</label>
                                    @php $isStaff = auth()->user()->role === 'staff'; @endphp
                                    @if($isStaff)
                                    <input type="hidden" name="assigned_project_manager" id="projectManagerHidden" value="{{ auth()->id() }}">
                                    @endif
                                    <select name="{{ $isStaff ? '' : 'assigned_project_manager' }}" id="projectManager" class="form-select" {{ $isStaff ? 'disabled' : '' }}>
                                        @if($isStaff)
                                            <option value="{{ auth()->id() }}" selected>{{ auth()->user()->display_name }}</option>
                                        @else
                                        <option value="">-- Select Project Manager --</option>
                                        <optgroup label="Admins">
                                            @foreach($admins as $admin)
                                                <option value="{{ $admin->user_id }}"
                                                        data-manager-type="admin"
                                                        data-manager-name="{{ $admin->full_name }}"
                                                        data-manager-email="{{ $admin->user->email ?? '' }}"
                                                        data-manager-phone="">
                                                    {{ $admin->full_name }} (Admin)
                                                </option>
                                            @endforeach
                                        </optgroup>
                                        <optgroup label="Staff">
                                            @foreach($staff as $staffMember)
                                                <option value="{{ $staffMember->user_id }}"
                                                        data-manager-type="staff"
                                                        data-manager-name="{{ $staffMember->first_name }} {{ $staffMember->last_name }}"
                                                        data-manager-email="{{ $staffMember->user->email ?? '' }}"
                                                        data-manager-phone="{{ $staffMember->contact_number ?? '' }}">
                                                    {{ $staffMember->first_name }} {{ $staffMember->last_name }} (Staff)
                                                </option>
                                            @endforeach
                                        </optgroup>
                                        @endif
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="projectManagerContact">PIC (Phone/Email)</label>
                                    <input type="text" name="project_manager_contact" id="projectManagerContact" class="form-control" placeholder="+63 912-345-6789 / email@example.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="projectWorkers">Estimated Number of Workers</label>
                                    <input type="number" name="estimated_workers" id="projectWorkers" class="form-control" min="1" placeholder="e.g., 50">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="projectHandoverDate">Planned Handover Date</label>
                                    <input type="date" name="planned_handing_over_date" id="projectHandoverDate" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Footer (fixed) -->
            <div class="cpm-footer">
                <button type="button" id="prevPageBtn" class="btn btn-outline-secondary d-none" onclick="previousPage()">
                    <i class="bi bi-chevron-left"></i> Previous
                </button>
                {{-- "Step X of N" — written by updatePageDisplay() in form-navigation.js. --}}
                <span class="cpm-step-count" id="cpmStepCount" aria-live="polite"></span>
                <div class="cpm-footer-actions">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="nextPageBtn" class="btn btn-primary" onclick="nextPage()">
                        Next <i class="bi bi-chevron-right"></i>
                    </button>
                    <button type="submit" form="createProjectForm" class="btn btn-success d-none" id="submitBtn">
                        <i class="bi bi-check-circle"></i> Create Project
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- === END CREATE/EDIT PROJECT MODAL === -->

<!-- === AUTO-FILL PREVIEW MODAL (shown by document-parser.js) === -->
@include('components.autofill-preview-modal')


<!-- Initialize global variables for the modal JS -->
<script>
// Set up global variables that the external JS will use
window._projectModalConfig = {
    routePrefix: '{{ $routePrefix }}',
    csrfToken: '{{ csrf_token() }}',
    generateCodeRoute: '{{ route($routePrefix . '.generateCode') }}',
    validateCodeRoute: '{{ route($routePrefix . '.validateCode') }}',
    validateTitleRoute: '{{ route($routePrefix . '.validateTitle') }}',
    categoriesListRoute: '{{ route("admin.categories.list") }}',
    staffCategoriesListRoute: '{{ route("staff.categories.list") }}',
    contractorCategoriesListRoute: '{{ route("contractor.categories.list") }}',
    budgetSourcesListRoute: '{{ route("admin.budget-sources.list") }}',
    // Staff can CHOOSE budget source when creating/editing (only admin can MANAGE add/edit/delete)
    staffBudgetSourcesListRoute: '{{ route("staff.budget-sources.list") }}',
    contractorBudgetSourcesListRoute: '{{ route("contractor.budget-sources.list") }}',
    // Q3(a) 2026-09-18 — the auto-fill preview can create a MISSING category or budget
    // source in place, so a value the parser read from a document is not lost just
    // because nobody has added it yet. Store routes are admin-only by design, so the
    // capability flags below mirror the same policy the (+) buttons already use and the
    // preview hides the action for anyone who cannot create.
    categoriesStoreRoute: '{{ route("admin.categories.store") }}',
    budgetSourcesStoreRoute: '{{ route("admin.budget-sources.store") }}',
    canCreateCategory: {{ (auth()->check() && auth()->user()->can('create', \App\Models\Category::class)) ? 'true' : 'false' }},
    canCreateBudgetSource: {{ (auth()->check() && auth()->user()->can('create', \App\Models\BudgetSource::class)) ? 'true' : 'false' }},
};

// Initialize modal state
window._projectModalMode = 'create';
window._editingProjectId = null;
window._keepModalMode = false;

// ── Open manage doc types modal (stacking — create modal stays open) ──
window.openManageDocTypesFromCreateProject = function () {
    const manageModal = document.getElementById('manageDocumentTypesModal');
    if (!manageModal) return;
    manageModal.dataset.returnModal = 'addProjectModal';
    new bootstrap.Modal(manageModal).show();
};

// ── Open manage categories modal (stacking — create modal stays open) ──
window.openManageCategoriesFromCreateProject = function () {
    const manageModal = document.getElementById('manageCategoriesModal');
    if (!manageModal) return;
    manageModal.dataset.returnModal = 'addProjectModal';
    new bootstrap.Modal(manageModal).show();
};

// ── Open manage budget sources modal (stacking — create modal stays open) ──
window.openManageBudgetSourcesFromCreateProject = function () {
    const manageModal = document.getElementById('manageBudgetSourcesModal');
    if (!manageModal) return;
    manageModal.dataset.returnModal = 'addProjectModal';
    new bootstrap.Modal(manageModal).show();
};

// ── Dashboard "New Project" (2026-09-21) ──
// The dashboard links here with ?create=1. Open the create modal once the page is
// ready, then drop the flag from the address bar so a refresh or Back does not open
// it again. A plain show() with no relatedTarget is exactly the "New Project" button
// path: the modal's show handler resets it to create mode.
document.addEventListener('DOMContentLoaded', function () {
    try {
        const params = new URLSearchParams(window.location.search);
        if (params.get('create') !== '1') return;
        params.delete('create');
        const qs = params.toString();
        window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
        const modalEl = document.getElementById('addProjectModal');
        if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    } catch (e) { /* opening on arrival is a convenience — the page works without it */ }
});
</script>

<!-- Load external modal JS -->
{{-- Cache-bust: public/js files are served without versioning, so append the
     file mtime to force browsers to pick up edits in development. --}}
@php
    $jsv = fn($p) => asset($p) . '?v=' . filemtime(public_path($p));
@endphp
<script src="{{ $jsv('js/_create_project_modal.js') }}"></script>
<script src="{{ $jsv('js/document-upload-handler.js') }}"></script>
<script src="{{ $jsv('js/document-manager.js') }}"></script>
{{-- Parser core is an ESM module (export statements) — must load with type="module".
     It attaches itself to window.documentParserCore for the classic script below. --}}
<script type="module" src="{{ $jsv('js/admin/projects/document-parser-core.js') }}"></script>
<script src="{{ $jsv('js/admin/projects/document-parser.js') }}"></script>
<script type="module" src="{{ $jsv('js/admin/projects/scurve-import-core.js') }}"></script>
<script src="{{ $jsv('js/admin/projects/scurve-import-ui.js') }}"></script>
<script type="module" src="{{ $jsv('js/admin/projects/scurve-pdf-parser-core.js') }}"></script>
<script src="{{ $jsv('js/admin/projects/scurve-pdf-parser.js') }}"></script>
