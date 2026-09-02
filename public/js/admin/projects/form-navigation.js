// ===================================================
// MULTI-PAGE FORM NAVIGATION
// ===================================================
let currentFormPage = 1;

// In edit mode the Documents step is removed, so the wizard runs 5 steps
// (Project Info → Description → Project Setup → Timeline → Assignment) mapped to DOM pages 2-6.
function getTotalPages() {
    return window._projectModalMode === 'edit' ? 5 : 6;
}

// Map a logical step number (1-based, as shown in the wizard) to the actual
// DOM form page id. Edit mode skips page 1 (Documents).
function getDomPageForStep(step) {
    return window._projectModalMode === 'edit' ? step + 1 : step;
}

// Jump straight to a wizard step (clickable step indicator). Edit mode only —
// create mode stays sequential so the document-autofill flow is preserved.
// No per-step validation on jump; the final submit validates every page.
function goToStep(step) {
    if (window._projectModalMode !== 'edit') return;
    if (step < 1 || step > getTotalPages()) return;
    if (step === currentFormPage) return;
    currentFormPage = step;
    document.getElementById('currentPage').value = step;
    updatePageDisplay();
}

function updatePageDisplay() {
    // Hide all DOM pages (1-6)
    for (let i = 1; i <= 6; i++) {
        const page = document.getElementById(`page${i}`);
        if (page) page.classList.remove('active');
    }
    
    // Show the DOM page for the current logical step
    const domPage = getDomPageForStep(currentFormPage);
    document.getElementById(`page${domPage}`).classList.add('active');
    
    // Update step indicators
    updateStepIndicators();
    
    // Update button states
    const totalPages = getTotalPages();
    const prevBtn = document.getElementById('prevPageBtn');
    const nextBtn = document.getElementById('nextPageBtn');
    const submitBtn = document.getElementById('submitBtn');
    
    if (currentFormPage === 1) {
        prevBtn.classList.add('d-none');
    } else {
        prevBtn.classList.remove('d-none');
    }
    
    if (currentFormPage === totalPages) {
        nextBtn.classList.add('d-none');
        submitBtn.classList.remove('d-none');
    } else {
        nextBtn.classList.remove('d-none');
        submitBtn.classList.add('d-none');
    }
    
    // Scroll to top of modal
    const modalBody = document.querySelector('#addProjectModal .modal-body');
    if (modalBody) {
        modalBody.scrollTop = 0;
    }
}

function updateStepIndicators() {
    for (let i = 1; i <= getTotalPages(); i++) {
        // Edit mode hides DOM step 1 (Documents), so logical step 1 = DOM step 2
        const domIndex = window._projectModalMode === 'edit' ? i + 1 : i;
        const indicator = document.getElementById(`step${domIndex}-indicator`);
        const dot = indicator ? indicator.querySelector('.step-dot') : null;
        const connector = document.getElementById(`step${domIndex}-connector`);

        if (i < currentFormPage) {
            // Completed step — green check inside the dot
            indicator.classList.remove('current', 'upcoming');
            indicator.classList.add('done');
            if (dot) dot.innerHTML = '<i class="bi bi-check-lg" aria-hidden="true"></i>';
            if (connector) connector.classList.add('done');
        } else if (i === currentFormPage) {
            // Current step — filled blue dot with the step number
            indicator.classList.remove('done', 'upcoming');
            indicator.classList.add('current');
            if (dot) dot.textContent = i;
            if (connector) connector.classList.remove('done');
        } else {
            // Future step — outlined gray dot with the step number
            indicator.classList.remove('done', 'current');
            indicator.classList.add('upcoming');
            if (dot) dot.textContent = i;
            if (connector) connector.classList.remove('done');
        }
    }
}

// ===================================================
// NEXT PAGE
// On the Page 1 → Page 2 transition, trigger document
// parsing AFTER the page is shown so the user sees the
// scanning spinner immediately while text is extracted.
// ===================================================
function nextPage() {
    // Validate current page before moving to next
    validatePage(currentFormPage).then(async isValid => {
        if (!isValid) return;

        const leavingPage = currentFormPage;

        if (currentFormPage < getTotalPages()) {
            currentFormPage++;
            document.getElementById('currentPage').value = currentFormPage;
            updatePageDisplay();

            // ── DOCUMENT AUTOFILL HOOK ──────────────────────────
            // Only triggers on the Page 1 → Page 2 transition and
            // only in create mode (edit mode has its own data).
            // Runs in the background — navigation already happened,
            // the scanning banner shows while extraction runs.
            // Skipped if the user already applied a preview this session.
            if (leavingPage === 1 && window._projectModalMode !== 'edit' &&
                window._autofillApplied !== true) {
                if (typeof runDocumentAutofill === 'function') {
                    runDocumentAutofill();
                }
            }
            // ────────────────────────────────────────────────────
        }
    });
}

function previousPage() {
    if (currentFormPage > 1) {
        const leavingPage = currentFormPage;
        currentFormPage--;
        document.getElementById('currentPage').value = currentFormPage;
        updatePageDisplay();

        // ── AUTOFILL CLEANUP HOOK ────────────────────────────
        // When going back from Page 2 → Page 1, clear any fields
        // that were autofilled from the previous document scan.
        // This ensures the next "Next" click always does a fresh
        // fill with whatever documents are currently in the list,
        // rather than skipping fields that are already populated.
        if (leavingPage === 2 && window._projectModalMode !== 'edit') {
            if (typeof clearAutofillFields === 'function') {
                clearAutofillFields();
            }
            if (typeof hideAutofillBanner === 'function') {
                hideAutofillBanner();
            }
        }
        // ────────────────────────────────────────────────────
    }
}

function validatePage(pageNum) {
    const form = document.getElementById('createProjectForm');
    hideError();
    clearFieldErrors();

    // Convert the logical step to the actual DOM page for validation rules.
    const domPage = getDomPageForStep(pageNum);
    
    // For DOM Page 2 (Project Info), handle async validation with flush
    if (domPage === 2) {
        return new Promise((resolve) => {
            const codeField = document.getElementById('projectCode');
            const titleField = document.getElementById('projectTitle');
            
            // Flush any pending validations (API calls without debounce)
            if (codeField && codeField.value && codeField.value.trim()) {
                codeValidator.flush();
            }
            if (titleField && titleField.value && titleField.value.trim()) {
                titleValidator.flush();
            }
            
            // Wait for async validations to complete
            let checkCount = 0;
            const checkInterval = setInterval(() => {
                checkCount++;
                
                // If both validations are complete or timeout reached (1 second)
                if ((!codeValidator.isPending() && !titleValidator.isPending()) || checkCount > 10) {
                    clearInterval(checkInterval);
                    
                    // Check required fields
                    let requiredFields = ['projectTitle', 'projectCode', 'projectCategory'];
                    for (let fieldId of requiredFields) {
                        const field = document.getElementById(fieldId);
                        if (field && !field.value.trim()) {
                            showFieldError(field.name, 'This field is required');
                            showError(`Please fill in all required fields on this page`);
                            resolve(false);
                            return;
                        }
                    }
                    
                    // Now check for validation errors
                    if (codeField && codeField.value) {
                        const code = codeField.value.trim();
                        // Accept format: PRJ-YYYY-NNNNN (e.g., PRJ-2026-00001)
                        if (!/^PRJ-\d{4}-\d{5}$/.test(code)) {
                            showFieldError('code', 'Project code must be in format: PRJ-YYYY-NNNNN (e.g., PRJ-2026-00001)');
                            showError('Invalid project code format');
                            resolve(false);
                            return;
                        }
                        
                        // Check if project code has validation errors (is-invalid class)
                        // Don't show duplicate error - AJAX validation already shows the message
                        if (codeField.classList.contains('is-invalid')) {
                            showError('Please fix the project code error before proceeding');
                            resolve(false);
                            return;
                        }
                    }
                    
                    // Validate project title is not a duplicate
                    // Don't show duplicate error - AJAX validation already shows the message
                    if (titleField && titleField.classList.contains('is-invalid')) {
                        showError('Please fix the project title error before proceeding');
                        resolve(false);
                        return;
                    }
                    
                    resolve(true);
                }
            }, 100); // Check every 100ms
        });
    }
    
    // For other pages, use synchronous validation
    // Get required fields for this page
    let requiredFields = [];
    
    switch(domPage) {
        case 1:
            // Page 1: Documents - no required fields
            requiredFields = [];
            break;
        case 3:
            // Page 3: Description - no required fields
            requiredFields = [];
            break;
        case 4:
            // Page 4: Project Setup
            requiredFields = ['projectBudget', 'projectStatus'];
            break;
        case 5:
            // Page 5: Timeline
            requiredFields = ['plannedStartDate', 'plannedEndDate'];
            break;
        case 6:
            // Assignment validation is optional
            break;
    }
    
    // Check required fields
    for (let fieldId of requiredFields) {
        const field = document.getElementById(fieldId);
        if (field && !field.value.trim()) {
            showFieldError(field.name, 'This field is required');
            showError(`Please fill in all required fields on this page`);
            return Promise.resolve(false);
        }
    }
    
    // DOM Page 4 - Additional validation for budget
    if (domPage === 4) {
        const budgetField = document.getElementById('projectBudget');
        if (budgetField && budgetField.value) {
            const budget = parseFloat(budgetField.value);
            if (isNaN(budget) || budget < 0) {
                showFieldError('approved_budget', 'Budget must be a positive number');
                showError('Invalid budget amount. Please enter a positive number.');
                return Promise.resolve(false);
            }
        }
    }
    
    return Promise.resolve(true);
}

// Update form submission to handle submit button
document.addEventListener('DOMContentLoaded', function() {
    const createProjectForm = document.getElementById('createProjectForm');
    const submitBtn = document.getElementById('submitBtn');
    
    if (submitBtn) {
        submitBtn.addEventListener('click', async function() {
            // Validate all pages before submitting
            let isValid = true;
            for (let i = 1; i <= getTotalPages(); i++) {
                const pageValid = await validatePage(i);
                if (!pageValid) {
                    isValid = false;
                    currentFormPage = i;
                    document.getElementById('currentPage').value = i;
                    updatePageDisplay();
                    break;
                }
            }
            
            if (isValid && createProjectForm) {
                createProjectForm.dispatchEvent(new Event('submit'));
            }
        });
    }
});