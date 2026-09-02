// ===================================================
// MODAL MODE: 'create' or 'edit'
// ===================================================
window._projectModalMode = 'create';
window._editingProjectId = null;
window._keepModalMode = false;

// Baseline months held in memory from the Set Up Baseline modal (return-data mode).
// Null = no setup attempted; [] = setup was cleared; [{...}, ...] = months ready
window._baselineSetupMonths = null;

// ===================================================
// WIZARD MODE
// Edit mode removes the Documents step entirely (docs live on the project
// detail page's dedicated tab), so the wizard shrinks from 6 to 5 steps and
// its step indicators become clickable for quick skipping. Create mode keeps
// the full 6-step sequential flow (document autofill depends on the order).
// ===================================================
const STEP_LABELS_CREATE = ['Documents', 'Project Info', 'Description', 'Project Setup', 'Timeline', 'Assignment'];
const STEP_LABELS_EDIT = ['Project Info', 'Description', 'Project Setup', 'Timeline', 'Assignment'];

function applyWizardMode() {
    const isEdit = window._projectModalMode === 'edit';
    const labels = isEdit ? STEP_LABELS_EDIT : STEP_LABELS_CREATE;

    for (let i = 1; i <= 6; i++) {
        const indicator = document.getElementById('step' + i + '-indicator');
        const label = document.getElementById('step' + i + '-label');
        const connector = document.getElementById('step' + i + '-connector');

        if (isEdit && i === 1) {
            // Step 1 (Documents) + its trailing connector are removed in edit mode.
            if (indicator) indicator.style.display = 'none';
            if (connector) connector.style.display = 'none';
            continue;
        }

        if (indicator) indicator.style.display = '';
        if (connector) connector.style.display = '';
        // Edit mode hides step 1 (Documents), so labels shift: DOM step 2 should show edit label 0 (Project Info)
        const labelIndex = isEdit ? i - 2 : i - 1;
        if (label && labelIndex >= 0 && labels[labelIndex]) label.textContent = labels[labelIndex];
    }

    // Clickable step-skipping is edit-mode only.
    document.querySelectorAll('.step-item').forEach(function (item) {
        item.classList.toggle('step-item--clickable', isEdit);
    });
}

// ===================================================
// FETCH UNIQUE PROJECT CODE FROM SERVER
// ===================================================
async function fetchUniqueProjectCode() {
    try {
        const response = await fetch(window._projectModalConfig.generateCodeRoute, {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': window._projectModalConfig.csrfToken
            }
        });
        const data = await response.json();
        if (data.success && data.code) {
            document.getElementById('projectCode').value = data.code;
            codeValidator.clear();
        }
    } catch (error) {
        console.error('Error fetching project code:', error);
        // Fallback to client-side generation (format: PRJ-YYYY-NNNNN)
        const currentYear = new Date().getFullYear();
        const randomNum = String(Math.floor(Math.random() * 99999) + 1).padStart(5, '0');
        document.getElementById('projectCode').value = `PRJ-${currentYear}-${randomNum}`;
        codeValidator.clear();
    }
}

// ===================================================
// GENERATE RANDOM PROJECT CODE (for button click)
// ===================================================
function generateRandomProjectCode() {
    const currentYear = new Date().getFullYear();
    const randomNum = String(Math.floor(Math.random() * 99999) + 1).padStart(5, '0');
    document.getElementById('projectCode').value = `PRJ-${currentYear}-${randomNum}`;
    // Validate the newly generated code
    codeValidator.validate();
}

// ===================================================
// OPEN SET UP BASELINE MODAL (return-data mode)
// ===================================================
function openBaselineSetupModal() {
    window.__setupModalContext = {
        mode: 'return-data',
        existingMonths: window._baselineSetupMonths || [],
        startDate: document.getElementById('plannedStartDate')?.value || null,
        endDate: document.getElementById('plannedEndDate')?.value || null,
        onDone: function(months) {
            window._baselineSetupMonths = months;
            updateBaselineSetupButton();
        },
        onCancel: function() {
            // No data to save — previous setup data (if any) stays intact
        }
    };
    openSetupBaselineModal();
}

function updateBaselineSetupButton() {
    var emptyEl = document.getElementById('baselineSetupEmpty');
    var completeEl = document.getElementById('baselineSetupComplete');
    var countEl = document.getElementById('baselineSetupCount');
    var clearBtn = document.getElementById('clearBaselineBtn');
    if (!emptyEl || !completeEl || !countEl) return;
    if (window._baselineSetupMonths && window._baselineSetupMonths.length > 0) {
        emptyEl.style.display = 'none';
        completeEl.style.display = 'flex';
        countEl.textContent = window._baselineSetupMonths.length;
        if (clearBtn) clearBtn.style.display = 'inline-flex';
    } else {
        emptyEl.style.display = 'flex';
        completeEl.style.display = 'none';
        if (clearBtn) clearBtn.style.display = 'none';
    }
}

function clearBaselineSetup() {
    if (!window._baselineSetupMonths || window._baselineSetupMonths.length === 0) return;
    var count = window._baselineSetupMonths.length;
    var msg = 'Are you sure you want to clear all <strong>' + count + '</strong> month target' +
              (count > 1 ? 's' : '') + '? This cannot be undone within this session.';
    if (typeof showDeleteConfirmation === 'function') {
        showDeleteConfirmation('Clear Baseline Setup', msg, function() {
            window._baselineSetupMonths = null;
            updateBaselineSetupButton();
        });
    } else {
        // Fallback if showDeleteConfirmation is not available
        if (confirm('Clear all ' + count + ' month target' + (count > 1 ? 's' : '') + '?')) {
            window._baselineSetupMonths = null;
            updateBaselineSetupButton();
        }
    }
}

// ===================================================
// INITIALIZE FIELD VALIDATORS (using field-validation.js)
// These will be initialized in DOMContentLoaded event
// ===================================================
let codeValidator;
let titleValidator;

// ===================================================
// RESET MODAL TO CREATE MODE
// ===================================================
function resetModalToCreateMode() {
    window._projectModalMode = 'create';
    window._editingProjectId = null;
    
    // Reset the autofill "already applied" flag so a new modal session
    // starts fresh (Next will re-run the document scan again).
    window._autofillApplied = false;
    
    // Restore the full 5-step wizard (Documents first)
    applyWizardMode();
    
    // Update modal title and button
    document.getElementById('projectModalIcon').className = 'bi bi-plus-circle';
    document.getElementById('projectModalTitleText').textContent = 'Create Project';
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> Create Project';
    }
    
    // Reset form action and method
    const form = document.getElementById('createProjectForm');
    // Build the correct route based on the routePrefix
    const routePrefix = window._projectModalConfig.routePrefix;
    let storeRoute = '/admin/projects';
    if (routePrefix.includes('staff')) {
        storeRoute = '/staff/projects';
    } else if (routePrefix.includes('contractor')) {
        storeRoute = '/contractor/projects';
    }
    form.action = storeRoute;
    document.getElementById('formMethodInput').value = 'POST';
    document.getElementById('projectIdInput').value = '';

    
    // Reset all form fields
    form.reset();
    
    // Clear pending documents (new document manager)
    if (typeof clearPendingDocuments === 'function') {
        clearPendingDocuments();
    }

    // Hide the autofill banner — must be done here in resetModalToCreateMode()
    // because show.bs.modal calls this function BEFORE hidden.bs.modal's
    // setTimeout fires, so the banner would still be visible on reopen
    // if we only cleared it in the hidden.bs.modal handler.
    if (typeof hideAutofillBanner === 'function') {
        hideAutofillBanner();
    }

    // Cancel any in-flight autofill run (e.g. a scan started then the modal was
    // closed) so it can't re-show the banner or pop the preview modal later.
    if (typeof cancelDocumentAutofill === 'function') {
        cancelDocumentAutofill();
    }
    
    // Update code field help text for create mode
    document.getElementById('projectCodeHelp').textContent = 'Format: PRJ-YYYY-NNNNN (auto-filled on open)';
    
    // Clear code value to trigger fetch
    document.getElementById('projectCode').value = '';
    codeValidator.clear();
    
    // Clear project title validation
    titleValidator.clear();
    
    // Clear document uploads (legacy support)
    if (typeof resetDocumentUploads === 'function') {
        resetDocumentUploads();
    }
    
    // Reset form page to 1
    currentFormPage = 1;
    document.getElementById('currentPage').value = 1;
    
    // Generate new code
    fetchUniqueProjectCode();
    
    // Clear errors
    hideError();
    clearFieldErrors();
    
    // Reset baseline setup data
    window._baselineSetupMonths = null;
    updateBaselineSetupButton();
    
    // Show baseline setup section (only in create mode)
    const baselineSetupSection = document.getElementById('baselineSetupSection');
    if (baselineSetupSection) {
        baselineSetupSection.style.display = 'block';
    }
}

// ===================================================
// OPEN MODAL FOR EDITING A PROJECT
// ===================================================
async function openEditProjectModal(projectId, showModal = false) {
    window._projectModalMode = 'edit';
    window._editingProjectId = projectId;
    
    // Switch the wizard to its 4-step edit layout (Documents removed)
    applyWizardMode();
    
    // Only show modal if explicitly requested (e.g., from URL parameter)
    // When triggered by button click, modal is already shown via data-bs-toggle
    if (showModal) {
        const modalElement = document.getElementById('addProjectModal');
        let modalInstance = bootstrap.Modal.getInstance(modalElement);
        if (!modalInstance) {
            modalInstance = new bootstrap.Modal(modalElement);
        }
        modalInstance.show();
    }
    
    // Update modal title and button
    document.getElementById('projectModalIcon').className = 'bi bi-pencil-square';
    document.getElementById('projectModalTitleText').textContent = 'Edit Project';
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> Save Changes';
    }
    
    hideError();
    clearFieldErrors();
    
    try {
        // First, load categories so they're available before populating form
        await loadProjectCategories();
        
        // Fetch project data (role-aware endpoint)
        const routePrefix = window._projectModalConfig.routePrefix;
        const dataEndpoint = routePrefix.includes('staff') 
            ? `/staff/projects/${projectId}/data`
            : `/admin/projects/${projectId}/data`;
        
        const response = await fetch(dataEndpoint, {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': window._projectModalConfig.csrfToken
            }
        });
        
        if (!response.ok) {
            throw new Error('Failed to load project data');
        }
        
        const result = await response.json();
        
        if (result.success) {
            populateFormWithProjectData(result.project);
        } else {
            showError('Failed to load project data: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error loading project:', error);
        showError('Error loading project data: ' + error.message);
    }
}

// ===================================================
// POPULATE FORM WITH PROJECT DATA
// ===================================================
function populateFormWithProjectData(project) {
    // Set form action for update (role-based route)
    const form = document.getElementById('createProjectForm');
    // Determine the correct update endpoint based on route prefix
    const routePrefix = window._projectModalConfig.routePrefix;
    const updateEndpoint = routePrefix.includes('staff') 
        ? `/staff/projects/${project.id}`
        : `/admin/projects/${project.id}`;
    form.action = updateEndpoint;
    document.getElementById('formMethodInput').value = 'PUT';
    document.getElementById('projectIdInput').value = project.id;
    
    // Project Information
    document.getElementById('projectTitle').value = project.title || '';
    // Display the full code as stored (should be PRJ-YYYY-NNNNN)
    document.getElementById('projectCode').value = project.code || '';
    document.getElementById('projectCodeHelp').textContent = 'Format: PRJ-YYYY-NNNNN (edit as needed)';
    codeValidator.clear();
    document.getElementById('projectCategory').value = project.category_id || '';
    document.getElementById('projectLocation').value = project.location || '';
    document.getElementById('projectDescription').value = project.description || '';
    
    // Budget and Contractor
    document.getElementById('projectBudget').value = project.approved_budget || '';
    document.getElementById('projectAbc').value = project.abc || '';
    document.getElementById('projectBudgetSource').value = project.budget_source_id || '';
    document.getElementById('contractorSelect').value = project.contractor_id || '';
    
    // Status
    document.getElementById('projectStatus').value = project.status || 'Planned';
    
    // Timeline
    document.getElementById('plannedStartDate').value = project.planned_start_date || '';
    document.getElementById('plannedDuration').value = project.planned_duration_days || '';
    document.getElementById('plannedEndDate').value = project.planned_end_date || '';
    document.getElementById('projectActualStartDate').value = project.actual_start_date || '';
    document.getElementById('projectActualEndDate').value = project.actual_end_date || '';
    
    // Assignment & Details
    // For the project manager dropdown, try to match the stored value
    const projectManagerSelect = document.getElementById('projectManager');
    const projectManagerHidden = document.getElementById('projectManagerHidden');
    if (project.assigned_project_manager && projectManagerSelect) {
        if (projectManagerSelect.disabled && projectManagerHidden) {
            // Staff: dropdown is disabled, update hidden input with current value
            projectManagerHidden.value = String(project.assigned_project_manager);
        } else {
        // Try to find matching option by value (ID) or by manager name
        const managerValue = String(project.assigned_project_manager);
        let found = false;
        
        for (let option of projectManagerSelect.options) {
            if (option.value === managerValue) {
                projectManagerSelect.value = managerValue;
                found = true;
                break;
            }
        }
        
        // If not found by value, try to match by name (for backward compatibility)
        if (!found) {
            for (let option of projectManagerSelect.options) {
                const managerName = option.getAttribute('data-manager-name') || '';
                if (managerName && managerName.toLowerCase() === managerValue.toLowerCase()) {
                    projectManagerSelect.value = option.value;
                    found = true;
                    break;
                }
            }
        }
        
        // Trigger change event to auto-fill contact info
        projectManagerSelect.dispatchEvent(new Event('change'));
        }
    }
    
    // Set contact if not auto-filled from selection
    if (project.project_manager_contact && !document.getElementById('projectManagerContact').value) {
        document.getElementById('projectManagerContact').value = project.project_manager_contact;
    }
    
    document.getElementById('projectWorkers').value = project.estimated_workers || '';
    document.getElementById('projectHandoverDate').value = project.planned_handing_over_date || '';
    
    // Initialize page display for multi-page form
    currentFormPage = 1;
    document.getElementById('currentPage').value = 1;
    updatePageDisplay();
    
    // Hide baseline setup section in edit mode (users can add targets from project detail page)
    const baselineSetupSection = document.getElementById('baselineSetupSection');
    if (baselineSetupSection) {
        baselineSetupSection.style.display = 'none';
    }
    
    // Clear any stale baseline setup data from a previous create session
    window._baselineSetupMonths = null;
}

// ===================================================
// SHOW/HIDE ERROR FUNCTIONS
// ===================================================
function showError(message) {
    const errorDiv = document.getElementById('createProjectError');
    const errorMsg = document.getElementById('createProjectErrorMessage');
    errorMsg.textContent = message;
    errorDiv.classList.remove('d-none');
    errorDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function hideError() {
    document.getElementById('createProjectError').classList.add('d-none');
}

function clearFieldErrors() {
    document.querySelectorAll('#createProjectForm .is-invalid').forEach(el => {
        el.classList.remove('is-invalid');
    });
    document.querySelectorAll('#createProjectForm .invalid-feedback').forEach(el => {
        el.textContent = '';
        el.classList.add('utility-none');
    });
}

function showFieldError(fieldName, message) {
    const field = document.querySelector(`#createProjectForm [name="${fieldName}"]`);
    if (field) {
        field.classList.add('is-invalid');
        // Look for invalid-feedback in parent element (handles input-groups)
        let parent = field.parentElement;
        let feedback = parent.querySelector('.invalid-feedback');
        
        // If not found in immediate parent, check parent's parent (for input-groups with prefix)
        if (!feedback && parent.classList.contains('input-group')) {
            feedback = parent.parentElement.querySelector('.invalid-feedback');
        }
        
        // Also check as sibling
        if (!feedback) {
            feedback = field.nextElementSibling;
            if (feedback && !feedback.classList.contains('invalid-feedback')) {
                feedback = null;
            }
        }
        
        if (feedback) {
            feedback.textContent = message;
            feedback.classList.remove('utility-none');
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Ensure the wizard matches the initial mode (create)
    applyWizardMode();

    // ===================================================
    // STEP-SKIPPING: click a step indicator to jump to it (edit mode only)
    // ===================================================
    const stepWizard = document.querySelector('#addProjectModal .step-wizard');
    if (stepWizard) {
        stepWizard.addEventListener('click', function (e) {
            if (window._projectModalMode !== 'edit') return;
            const item = e.target.closest('.step-item');
            if (!item) return;
            const domStep = parseInt(item.id.replace('step', '').replace('-indicator', ''), 10);
            // Edit hides DOM step 1 (Documents), so DOM 2 → logical 1 (Project Info)
            const logicalStep = window._projectModalMode === 'edit' ? domStep - 1 : domStep;
            if (logicalStep && typeof goToStep === 'function') {
                goToStep(logicalStep);
            }
        });
    }

    // ===================================================
    // INITIALIZE FIELD VALIDATORS (Create instances here after field-validation.js is loaded)
    // ===================================================
    
    // Project Code Validator Instance
    codeValidator = createFieldValidator({
        fieldId: 'projectCode',
        feedbackId: 'projectCodeFeedback',
        fieldName: 'code',
        validationEndpoint: window._projectModalConfig.validateCodeRoute,
        successMessage: 'Project code is available',
        getExtraData() {
            return {
                project_id: window._editingProjectId || null
            };
        }
    });

    // Project Title Validator Instance
    titleValidator = createFieldValidator({
        fieldId: 'projectTitle',
        feedbackId: 'projectTitleFeedback',
        fieldName: 'title',
        validationEndpoint: window._projectModalConfig.validateTitleRoute,
        successMessage: 'Project title is available',
        getExtraData() {
            return {
                project_id: window._editingProjectId || null
            };
        }
    });

    // Handle modal show event
    const addProjectModal = document.getElementById('addProjectModal');
    if (addProjectModal) {
        addProjectModal.addEventListener('show.bs.modal', function(event) {
            
            // If triggered by a button with data-project-id, it's edit mode
            const button = event.relatedTarget;
            if (button && button.getAttribute('data-project-id')) {
                const projectId = button.getAttribute('data-project-id');
                // openEditProjectModal will handle updatePageDisplay() after fetching data
                openEditProjectModal(projectId);
                return; // Don't call updatePageDisplay here, wait for async operation
            } else if (window._projectModalMode !== 'edit') {
                // Only reset to create mode if not already in edit mode
                resetModalToCreateMode();
            }
            
            // Initialize page display (only for non-edit operations)
            if (currentFormPage === undefined) currentFormPage = 1;
            updatePageDisplay();
        });
        
        // Handle modal shown event (after modal is fully displayed)
        // This ensures project code generation happens when DOM is fully ready
        addProjectModal.addEventListener('shown.bs.modal', function() {
            if (window._projectModalMode === 'create') {
                // Ensure code is generated and visible after modal transition completes
                const codeInput = document.getElementById('projectCode');
                if (codeInput && !codeInput.value) {
                    fetchUniqueProjectCode();
                }
            }
        });
        
        // Reset to create mode when modal is hidden
        addProjectModal.addEventListener('hidden.bs.modal', function() {
            // Cancel any in-flight autofill run immediately (don't wait for the
            // setTimeout below) so the scan can't continue while the modal is closed.
            if (typeof cancelDocumentAutofill === 'function') {
                cancelDocumentAutofill();
            }
            // Small delay to allow any redirects to happen first
            setTimeout(() => {
                // Reset form page to 1
                currentFormPage = 1;
                document.getElementById('currentPage').value = 1;
                
                // Clear all validation states
                codeValidator.clear();
                titleValidator.clear();
                hideError();
                clearFieldErrors();
                
                if (!window._keepModalMode) {
                    window._projectModalMode = 'create';
                    window._editingProjectId = null;
                }
                window._keepModalMode = false;
                
                // Clear baseline setup data when modal closes
                window._baselineSetupMonths = null;
            }, 200);
        });
    }

    // Generate random code on button click
    const generateRandomCodeBtn = document.getElementById('generateRandomCodeBtn');
    if (generateRandomCodeBtn) {
        generateRandomCodeBtn.addEventListener('click', function() {
            generateRandomProjectCode();
        });
    }

    // ===================================================
    // PROJECT CODE INPUT - FORMATTING & VALIDATION
    // ===================================================
    const projectCodeInput = document.getElementById('projectCode');
    if (projectCodeInput) {
        projectCodeInput.addEventListener('input', function(e) {
            let value = e.target.value.trim();
            
            // Only allow: digits, hyphens, and 'PRJ' letters
            value = value.replace(/[^0-9PRJprj\-]/g, '');
            e.target.value = value.toUpperCase();
            
            // Validate if user is typing
            if (value.length > 0) {
                codeValidator.validate();
            } else {
                codeValidator.clear();
            }
        });
        
        projectCodeInput.addEventListener('change', function(e) {
            let value = e.target.value.trim();
            if (!value) {
                if (window._projectModalMode === 'create') {
                    fetchUniqueProjectCode();
                }
            } else {
                codeValidator.validate();
            }
        });
        
        projectCodeInput.addEventListener('paste', function(e) {
            setTimeout(() => {
                let value = e.target.value.trim();
                value = value.replace(/[^0-9PRJprj\-]/g, '');
                e.target.value = value.toUpperCase();
                codeValidator.validate();
            }, 0);
        });
    }

    // ===================================================
    // PROJECT TITLE INPUT - VALIDATION
    // ===================================================
    const projectTitleInput = document.getElementById('projectTitle');
    if (projectTitleInput) {
        projectTitleInput.addEventListener('input', function(e) {
            let value = e.target.value.trim();
            
            if (value.length > 0) {
                titleValidator.validate();
            } else {
                titleValidator.clear();
            }
        });
        
        projectTitleInput.addEventListener('change', function(e) {
            let value = e.target.value.trim();
            if (!value) {
                titleValidator.clear();
            } else {
                titleValidator.validate();
            }
        });
        
        projectTitleInput.addEventListener('paste', function(e) {
            setTimeout(() => {
                titleValidator.validate();
            }, 0);
        });
    }

    // ===================================================
    // PROJECT MANAGER DROPDOWN - AUTO-FILL CONTACT INFO
    // ===================================================
    const projectManagerSelect = document.getElementById('projectManager');
    const projectManagerContactInput = document.getElementById('projectManagerContact');
    
    if (projectManagerSelect) {
        projectManagerSelect.addEventListener('change', function() {
            if (this.value) {
                const selectedOption = this.options[this.selectedIndex];
                const managerEmail = selectedOption.getAttribute('data-manager-email') || '';
                const managerPhone = selectedOption.getAttribute('data-manager-phone') || '';
                
                // Build contact info: show both phone and email if available
                let contactInfo = [];
                if (managerPhone) contactInfo.push(managerPhone);
                if (managerEmail) contactInfo.push(managerEmail);
                
                projectManagerContactInput.value = contactInfo.length > 0 ? contactInfo.join(' / ') : '';
            } else {
                projectManagerContactInput.value = '';
            }
        });
    }

    // ===================================================
    // AJAX FORM SUBMISSION (handles both create and update)
    // ===================================================
    const createProjectForm = document.getElementById('createProjectForm');
    const submitBtn = document.getElementById('submitBtn');
    
    if (createProjectForm) {
        createProjectForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            hideError();
            clearFieldErrors();

            // === VALIDATE PROJECT CODE BEFORE SUBMISSION ===
            const codeInput = document.getElementById('projectCode');
            const code = codeInput.value.trim();
            
            if (!code) {
                showError('Project code is required');
                codeInput.classList.add('is-invalid');
                return;
            }

            // Check if code has been validated and is unique
            if (codeInput.classList.contains('is-invalid')) {
                showError('Project code is not available. Please use a different code.');
                return;
            }

            // Show loading state
            const originalText = submitBtn.textContent;
            submitBtn.textContent = window._projectModalMode === 'edit' ? 'Saving...' : 'Creating...';
            submitBtn.disabled = true;

            try {
                const formData = new FormData(createProjectForm);
                
                // === APPEND PENDING DOCUMENTS ===
                // Create mode only — edit mode has no document step (documents
                // are managed on the project detail page's dedicated tab), and
                // the update endpoint ignores these fields anyway.
                if (window._projectModalMode !== 'edit' &&
                    typeof pendingDocuments !== 'undefined' && pendingDocuments.length > 0) {
                    pendingDocuments.forEach((doc, index) => {
                        // Use flat key structure that Laravel can properly parse
                        formData.append(`pending_document_files[${index}]`, doc.file);
                        formData.append(`pending_document_types[${index}]`, doc.typeId);
                    });
                }
                
                // Use the code exactly as entered (should already be in PRJ-YYYY-NNNNN format)
                // If it's missing the PRJ- prefix, add it
                if (codeInput && codeInput.value) {
                    let fullCode = codeInput.value.trim();
                    if (!fullCode.startsWith('PRJ-')) {
                        // Extract just the numeric and dash parts
                        const parts = fullCode.match(/(\d{4})-?(\d{5})/);
                        if (parts) {
                            fullCode = `PRJ-${parts[1]}-${parts[2]}`;
                        } else {
                            fullCode = `PRJ-${fullCode}`;
                        }
                    }
                    formData.set('code', fullCode);
                }
                
                // Collect and append Project Baseline Schedule targets from setup modal (if any)
                var baselineMonths = window._baselineSetupMonths;
                if (baselineMonths && baselineMonths.length > 0) {
                    var baselinePayload = baselineMonths.map(function(month) {
                        return {
                            planned_date: month.planned_date,
                            month_label: month.month_label,
                            planned_progress_percent: month.planned_progress_percent,
                            original_planned_progress_percent: month.planned_progress_percent,
                            description: month.description || '',
                            activities: (month.activities || []).map(function(a) {
                                return {
                                    activity_name: a.activity_name,
                                    description: a.description || ''
                                };
                            })
                        };
                    });
                    formData.append('baseline_schedule_targets', JSON.stringify(baselinePayload));
                }
                
                // Dismiss modal and show full-screen preloader during save
                try {
                    var modal = bootstrap.Modal.getInstance(document.getElementById('createProjectModal'));
                    if (modal) modal.hide();
                } catch (err) {}
                if (window.ProjTrackPreloader && window.ProjTrackPreloader.show) {
                    window.ProjTrackPreloader.show(window._projectModalMode === 'edit' ? 'Saving changes&hellip;' : 'Creating project&hellip;');
                }

                const response = await fetch(createProjectForm.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': window._projectModalConfig.csrfToken,
                        'Accept': 'application/json'
                    }
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    // Clear pending documents before redirect
                    if (typeof clearPendingDocuments !== 'undefined') {
                        clearPendingDocuments();
                    }
                    
                    // Success - redirect to project detail
                    window.location.href = data.redirect;
                } else {
                    // Hide preloader on validation/error response
                    if (window.ProjTrackPreloader && window.ProjTrackPreloader.hide) window.ProjTrackPreloader.hide();

                    // Handle validation errors
                    if (data.errors) {
                        for (const [field, messages] of Object.entries(data.errors)) {
                            showFieldError(field, messages[0]);
                        }
                    }
                    
                    // Show general error message
                    showError(data.message || 'Failed to save project. Please check the form and try again.');
                    
                    // Refresh the project code in case of conflict (only in create mode)
                    if (window._projectModalMode === 'create' && data.errors && data.errors.code) {
                        fetchUniqueProjectCode();
                    }
                }
            } catch (error) {
                if (window.ProjTrackPreloader && window.ProjTrackPreloader.hide) window.ProjTrackPreloader.hide();
                console.error('Error:', error);
                showError('An unexpected error occurred. Please try again.');
            } finally {
                // Restore button state
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            }
        });
    }

    // ===================================================
    // PROJECT FORM AUTO-CALCULATIONS (Bi-directional)
    // ===================================================
    const startDateInput = document.getElementById('plannedStartDate');
    const durationInput = document.getElementById('plannedDuration');
    const endDateInput = document.getElementById('plannedEndDate');
    
    // Track which field was last changed
    let projectLastChanged = 'duration';

    // Calculate end date from start date + duration
    function calculateEndDateFromDuration() {
        if (startDateInput && startDateInput.value && durationInput && durationInput.value) {
            const startDate = new Date(startDateInput.value);
            const duration = parseInt(durationInput.value);
            if (duration > 0) {
                const endDate = new Date(startDate);
                endDate.setDate(endDate.getDate() + (duration - 1));
                if (endDateInput) {
                    endDateInput.value = endDate.toISOString().split('T')[0];
                }
            }
        }
    }
    
    // Calculate duration from start date + end date
    function calculateDurationFromEndDate() {
        if (startDateInput && startDateInput.value && endDateInput && endDateInput.value) {
            const startDate = new Date(startDateInput.value);
            const endDate = new Date(endDateInput.value);
            if (endDate >= startDate) {
                const diffTime = endDate - startDate;
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                if (durationInput) {
                    durationInput.value = diffDays;
                }
            }
        }
    }
    
    function handleProjectDateCalculation() {
        if (projectLastChanged === 'duration') {
            calculateEndDateFromDuration();
        } else {
            calculateDurationFromEndDate();
        }
    }

    if (startDateInput) {
        startDateInput.addEventListener('change', handleProjectDateCalculation);
    }
    if (durationInput) {
        durationInput.addEventListener('change', function() {
            projectLastChanged = 'duration';
            calculateEndDateFromDuration();
        });
        durationInput.addEventListener('input', function() {
            projectLastChanged = 'duration';
            calculateEndDateFromDuration();
        });
    }
    if (endDateInput) {
        endDateInput.addEventListener('change', function() {
            projectLastChanged = 'endDate';
            calculateDurationFromEndDate();
        });
    }

    // ===================================================
    // DATE RANGE CONSTRAINTS (gray out invalid dates)
    // ===================================================
    // When start date changes, end date cannot be before it
    if (startDateInput) {
        startDateInput.addEventListener('change', function() {
            if (endDateInput && startDateInput.value) {
                endDateInput.min = startDateInput.value;
            }
        });
    }

    // When end date changes, start date cannot be after it
    if (endDateInput) {
        endDateInput.addEventListener('change', function() {
            if (startDateInput && endDateInput.value) {
                startDateInput.max = endDateInput.value;
            }
            // Handover date must be on or after end date
            const handoverInput = document.getElementById('projectHandoverDate');
            if (handoverInput && endDateInput.value) {
                handoverInput.min = endDateInput.value;
            }
        });
    }

    // Initialize on page load if values exist
    calculateEndDateFromDuration();
});
// ===================================================
// CHECK URL FOR EDIT PARAMETER ON PAGE LOAD
// ===================================================
document.addEventListener('DOMContentLoaded', function() {
    // Check if URL has edit parameter
    const urlParams = new URLSearchParams(window.location.search);
    const editProjectId = urlParams.get('edit');
    if (editProjectId) {
        // Remove the edit parameter from URL without reloading
        const newUrl = window.location.pathname;
        window.history.replaceState({}, document.title, newUrl);
        
        // Open modal in edit mode (pass true to show the modal)
        setTimeout(() => {
            openEditProjectModal(editProjectId, true);
        }, 100);
    }

    // Load categories on page load
    loadProjectCategories();
    loadBudgetSources();
});

// ===================================================
// LOAD PROJECT CATEGORIES DYNAMICALLY
// ===================================================
async function loadProjectCategories() {
    try {
        // Determine the route based on current URL
        const currentUrl = window.location.pathname;
        let route = window._projectModalConfig.categoriesListRoute;
        
        // Override route based on current URL
        if (currentUrl.includes('/staff/')) {
            route = window._projectModalConfig.staffCategoriesListRoute;
        } else if (currentUrl.includes('/contractor/')) {
            route = window._projectModalConfig.contractorCategoriesListRoute;
        }
        
        const response = await fetch(route);
        const categories = await response.json();
        
        const categorySelect = document.getElementById('projectCategory');
        const currentValue = categorySelect.value;
        
        // Clear existing options
        categorySelect.innerHTML = '<option value="">-- Select Category --</option>';
        
        // Add categories
        categories.forEach(category => {
            const option = document.createElement('option');
            option.value = category.id;
            option.textContent = category.name;
            categorySelect.appendChild(option);
        });
        
        // Restore previous value if it exists
        if (currentValue) {
            categorySelect.value = currentValue;
        }
        
        return true;
    } catch (error) {
        console.error('Error loading categories:', error);
        return false;
    }
}

// ===================================================
// LOAD BUDGET SOURCES DYNAMICALLY
// ===================================================
async function loadBudgetSources() {
    try {
        // Pick role-aware route like categories — staff was getting 403 on admin route
        const currentUrl = window.location.pathname;
        let route = window._projectModalConfig.budgetSourcesListRoute;
        if (currentUrl.includes('/staff/')) {
            route = window._projectModalConfig.staffBudgetSourcesListRoute || route;
        } else if (currentUrl.includes('/contractor/')) {
            route = window._projectModalConfig.contractorBudgetSourcesListRoute || route;
        }
        // Fallback to routePrefix-based check for modals opened from staff layouts
        if (!route || route.includes('/admin/') && window._projectModalConfig.routePrefix === 'staff.projects') {
            route = window._projectModalConfig.staffBudgetSourcesListRoute || route;
        }
        if (!route) return false;

        const response = await fetch(route);
        // If middleware redirects to /unauthorized (HTML), treat as empty rather than throwing SyntaxError
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            console.warn('Budget sources endpoint did not return JSON (likely unauthorized), skipping.');
            return false;
        }
        const sources = await response.json();

        const select = document.getElementById('projectBudgetSource');
        if (!select) return false;

        const currentValue = select.value;

        select.innerHTML = '<option value="">-- Select Source --</option>';

        sources.forEach(source => {
            const option = document.createElement('option');
            option.value = source.id;
            option.textContent = source.name;
            select.appendChild(option);
        });

        if (currentValue) {
            select.value = currentValue;
        }

        return true;
    } catch (error) {
        console.error('Error loading budget sources:', error);
        return false;
    }
}