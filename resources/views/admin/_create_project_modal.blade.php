@php
    // Set default route prefix if not provided (for backward compatibility)
    $routePrefix = $routePrefix ?? 'admin.projects';
@endphp
<!-- === CREATE/EDIT PROJECT MODAL === -->

<div class="modal fade" id="addProjectModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="projectModalTitle"><i class="bi bi-plus-circle" id="projectModalIcon"></i> <span id="projectModalTitleText">Create Project1</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body modal-body-scrollable">
                <!-- Error Alert -->
                <div id="createProjectError" class="alert alert-danger utility-none" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <span id="createProjectErrorMessage"></span>
                </div>

                <!-- === STEP INDICATOR === -->
                <div class="mb-4 pb-3 border-bottom">
                    <div class="progress mb-3 progress-thin">
                        <div id="progressBar" class="progress-bar bg-primary progress-bar-dynamic" role="progressbar" aria-valuenow="20" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <div class="row text-center small">
                        <div class="col">
                            <span id="step1-indicator" class="step-indicator active"><i class="bi bi-circle-fill"></i> Documents</span>
                        </div>
                        <div class="col">
                            <span id="step2-indicator" class="step-indicator inactive"><i class="bi bi-circle"></i> Project Info</span>
                        </div>
                        <div class="col">
                            <span id="step3-indicator" class="step-indicator inactive"><i class="bi bi-circle"></i> Details</span>
                        </div>
                        <div class="col">
                            <span id="step4-indicator" class="step-indicator inactive"><i class="bi bi-circle"></i> Timeline</span>
                        </div>
                        <div class="col">
                            <span id="step5-indicator" class="step-indicator inactive"><i class="bi bi-circle"></i> Assignment</span>
                        </div>
                    </div>
                </div>

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
                        <!-- === DOCUMENTS & FILES === -->
                        <div class="mb-4">
                            <h6 class="mb-3"><i class="bi bi-file-pdf"></i> Project Documents</h6>
                            
                            <!-- Existing Documents (shown only when editing) -->
                            <div id="existingDocumentsSection" class="existing-documents-hidden mb-3">
                                <div id="existingContractInfo" class="d-none mb-2">
                                    <label class="form-label text-muted">Current Contract:</label>
                                    <a href="#" id="currentContractLink" target="_blank" class="d-block">
                                        <i class="bi bi-file-pdf text-danger"></i> <span id="currentContractName">View Contract</span>
                                    </a>
                                </div>
                                <div id="existingSupportingDocs" class="d-none">
                                    <label class="form-label text-muted">Current Supporting Documents:</label>
                                    <ul id="supportingDocsList" class="list-unstyled small"></ul>
                                </div>
                                <hr class="my-3">
                            </div>

                            <!-- New Documents Upload Section -->
                            <div class="card border-light bg-light mb-3">
                                <div class="card-body">
                                    <div class="row g-3 mb-3">
                                        <!-- File Upload Input -->
                                        <div class="col-md-8">
                                            <label class="form-label">Select File</label>
                                            <input type="file" id="documentFileInput" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max 20MB. Accepted: PDF, DOC, XLS, PPT, Images</small>
                                        </div>

                                        <!-- Document Type Selector -->
                                        <div class="col-md-4">
                                            <label class="form-label">Document Type</label>
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
                                    </div>

                                    <!-- Add Document Button -->
                                    <button type="button" id="addDocumentBtn" class="btn btn-sm btn-primary">
                                        <i class="bi bi-plus-circle"></i> Add Document
                                    </button>
                                </div>
                            </div>

                            <!-- Documents List Table -->
                            <div id="documentsListContainer" class="d-none">
                                <label class="form-label fw-bold">Added Documents:</label>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>File Name</th>
                                                <th>Type</th>
                                                <th>Size</th>
                                                <th style="width: 80px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="documentsTableBody">
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- === PAGE 2: PROJECT INFORMATION === -->
                    <div id="page2" class="form-page">

                        <!-- === PROJECT INFORMATION SECTION === -->
                        <div class="mb-4">
                            <h6 class="mb-3"><i class="bi bi-folder"></i> Project Information(admin)</h6>
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label">Project Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" id="projectTitle" class="form-control" 
                                       placeholder="Project title" required>
                                <div id="projectTitleFeedback" class="field-feedback"></div>
                                <div class="invalid-feedback" id="titleError"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Project Code <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" id="projectCode" name="code" class="form-control" placeholder="e.g., 2026-00001" 
                                           pattern="^PRJ-\d{4}-\d{5}$|^\d{5}$" maxlength="14" required>
                                    <button class="btn btn-outline-secondary" type="button" id="generateRandomCodeBtn" title="Generate Random Code">
                                        <i class="bi bi-arrow-repeat"></i>
                                    </button>
                                </div>
                                <small class="text-muted" id="projectCodeHelp">Format: PRJ-YYYY-NNNNN (auto-filled or enter manually)</small>
                                <div id="projectCodeFeedback" class="field-feedback"></div>
                                <div class="invalid-feedback" id="codeError"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Category <span class="text-danger">*</span></label>
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
                            <div class="col-md-6">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" id="projectLocation" class="form-control" placeholder="Project location">
                            </div>
                        </div>
                    </div>
                    </div>

                    <!-- === PAGE 3: DESCRIPTION, BUDGET, CONTRACTOR & STATUS === -->
                    <div id="page3" class="form-page">
                        <!-- === PROJECT DESCRIPTION === -->
                        <div class="mb-4">
                            <label class="form-label"><h6 class="mb-2"><i class="bi bi-file-text"></i> Description</h6></label>
                            <textarea name="description" id="projectDescription" class="form-control" 
                                      rows="4" placeholder="Project description..."></textarea>
                            <div class="invalid-feedback"></div>
                        </div>

                        <!-- === BUDGET AND CONTRACTOR SECTION === -->
                        <div class="mb-4">
                            <h6 class="mb-3"><i class="bi bi-credit-card"></i> Project Setup</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Approved Budget <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" step="0.01" name="approved_budget" id="projectBudget" class="form-control" 
                                           placeholder="0.00" required>
                                </div>
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contractor</label>
                                <select name="contractor_id" id="contractorSelect" class="form-select">
                                    <option value="">-- Select Contractor --</option>
                                    @foreach($contractors as $c)
                                        <option value="{{ $c->id }}">{{ $c->company_name ?: ($c->contractor_name ?: ($c->user?->name ?? 'Unnamed')) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                        <!-- === PROJECT STATUS === -->
                        <div class="mb-4">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Project Status</label>
                                    <select name="status" id="projectStatus" class="form-select">
                                        <option value="Planned" selected>Planned</option>
                                        <option value="Ongoing">Ongoing</option>
                                        <option value="Completed">Completed</option>
                                        <option value="Suspended">Suspended</option>
                                        <option value="Cancelled">Cancelled</option>
                                    </select>
                                    <div class="invalid-feedback"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- === PAGE 4: TIMELINE === -->
                    <div id="page4" class="form-page">
                        <!-- === TIMELINE SECTION === -->
                        <div class="mb-4">
                            <h6 class="mb-3"><i class="bi bi-calendar"></i> Timeline</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Contract Start Date <span class="text-danger">*</span></label>
                                <input type="date" id="plannedStartDate" name="planned_start_date" class="form-control" required>
                                <div class="invalid-feedback" style="display: none;">Contract start date is required</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Contract Duration (days)</label>
                                <input type="number" id="plannedDuration" name="planned_duration_days" class="form-control" min="1">
                                <small class="text-muted">Fill this OR end date</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Planned End Date <span class="text-danger">*</span></label>
                                <input type="date" id="plannedEndDate" name="planned_end_date" class="form-control" required>
                                <small class="text-muted">Auto-calculates, or enter manually</small>
                                <div class="invalid-feedback" style="display: none;">Planned end date is required</div>
                            </div>
                        </div>

                        <div class="row g-3 mt-3">
                            <div class="col-md-6">
                                <label class="form-label">Actual Start Date</label>
                                <input type="date" name="actual_start_date" id="projectActualStartDate" class="form-control">
                                <small class="text-muted"><i class="bi bi-info-circle"></i> Only applicable for ongoing or completed projects</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Actual End Date</label>
                                <input type="date" name="actual_end_date" id="projectActualEndDate" class="form-control">
                                <small class="text-muted"><i class="bi bi-info-circle"></i> Only applicable for completed projects</small>
                            </div>
                        </div>
                    </div>

                        <!-- === PROJECT PROGRESS BASELINE SETUP === -->
                        <div class="mb-4" id="baselineSetupSection">
                            <h6 class="mb-3" style="font-size: 13px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 6px;">
                                <i class="bi bi-graph-up" style="color: #0d9488;"></i> Monthly Progress Targets
                            </h6>
                            <div style="background: #f9fafb; padding: 14px; border-radius: 10px; border: 1px solid #e5e7eb;">
                                <div id="baselineSetupEmpty" style="color: #6b7280; font-size: 12px; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                                    <i class="bi bi-info-circle"></i>
                                    <span>Set up monthly progress targets with activities. You can add more targets after project creation.</span>
                                </div>
                                <div id="baselineSetupComplete" style="display: none; margin-bottom: 10px; padding: 8px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 12px; font-weight: 600; color: #166534; align-items: center; gap: 6px;">
                                    <i class="bi bi-check-circle-fill" style="color: #16a34a;"></i>
                                    <span id="baselineSetupCount">0</span> month(s) set up
                                </div>
                                <div style="display: flex; gap: 8px;">
                                    <button type="button" onclick="openBaselineSetupModal()" class="btn btn-sm btn-outline-primary" style="border-color: #0d9488; color: #0d9488; font-weight: 600;">
                                        <i class="bi bi-flag-fill"></i> Set Up Baseline
                                    </button>
                                    <button type="button" id="clearBaselineBtn" onclick="clearBaselineSetup()" style="display: none; padding: 6px 12px; background: white; color: #dc2626; border: 1px solid #fca5a5; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; align-items: center; gap: 5px; transition: all 0.2s;" onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='white'">
                                        <i class="bi bi-trash"></i> Clear Baseline
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- === PAGE 5: ASSIGNMENT & DETAILS === -->
                    <div id="page5" class="form-page">
                        <!-- === ASSIGNMENT & DETAILS === -->
                        <div class="mb-4">
                            <h6 class="mb-3"><i class="bi bi-people"></i> Assignment & Details</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Project In Charge</label>
                                <select name="assigned_project_manager" id="projectManager" class="form-select">
                                    <option value="">-- Select Project Manager --</option>
                                    <optgroup label="Admins">
                                        @foreach($admins as $admin)
                                            <option value="{{ $admin->id }}" 
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
                                            <option value="{{ $staffMember->id }}" 
                                                    data-manager-type="staff" 
                                                    data-manager-name="{{ $staffMember->first_name }} {{ $staffMember->last_name }}"
                                                    data-manager-email="{{ $staffMember->user->email ?? '' }}"
                                                    data-manager-phone="{{ $staffMember->contact_number ?? '' }}">
                                                {{ $staffMember->first_name }} {{ $staffMember->last_name }} (Staff)
                                            </option>
                                        @endforeach
                                    </optgroup>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">PIC (Phone/Email)</label>
                                <input type="text" name="project_manager_contact" id="projectManagerContact" class="form-control" placeholder="+63 912-345-6789 / email@example.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Estimated Number of Workers</label>
                                <input type="number" name="estimated_workers" id="projectWorkers" class="form-control" min="1" placeholder="e.g., 50">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Planned Handover Date</label>
                                <input type="date" name="planned_handing_over_date" id="projectHandoverDate" class="form-control">
                            </div>
                        </div>
                    </div>
                    </div>
                </form>
            </div>
            
            <div class="modal-footer bg-light">
                <div class="d-flex gap-2 w-100">
                    <button type="button" id="prevPageBtn" class="btn btn-outline-secondary d-none" onclick="previousPage()">
                        <i class="bi bi-chevron-left"></i> Previous
                    </button>
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
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
</div>
<!-- === END CREATE/EDIT PROJECT MODAL === -->


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
    contractorCategoriesListRoute: '{{ route("contractor.categories.list") }}'
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
</script>

<!-- Load external modal JS -->
<script src="{{ asset('js/_create_project_modal.js') }}"></script>
<script src="{{ asset('js/document-upload-handler.js') }}"></script>
<script src="{{ asset('js/document-manager.js') }}"></script>
<script src="{{ asset('js/admin/projects/document-parser.js') }}"></script>
