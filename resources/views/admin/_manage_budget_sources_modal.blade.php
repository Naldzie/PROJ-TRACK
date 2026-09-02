<!-- === BUDGET SOURCE MANAGEMENT MODAL === -->
<div class="modal fade" id="manageBudgetSourcesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title">
                    <i class="bi bi-bank"></i> Manage Budget Sources
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">

                <!-- Add New Budget Source Section -->
                <div class="mb-4 p-3 bg-light border rounded">
                    <h6 class="mb-3"><i class="bi bi-plus-circle"></i> Add New Budget Source</h6>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <input type="text" id="newBudgetSourceName" class="form-control"
                                   placeholder="Source name (min. 3 characters)" maxlength="255">
                            <div id="bsNameError" class="invalid-feedback d-none" style="display:block!important;"></div>
                        </div>
                        <div class="col-md-6">
                            <button class="btn btn-primary w-100" onclick="addNewBudgetSource()">
                                <i class="bi bi-plus"></i> Add
                            </button>
                        </div>
                    </div>
                    <textarea id="newBudgetSourceDescription" class="form-control form-control-sm mt-2"
                              rows="2" placeholder="Description (optional)"></textarea>
                </div>

                <!-- Budget Sources List -->
                <div>
                    <h6 class="mb-3"><i class="bi bi-list"></i> Existing Budget Sources</h6>
                    <div id="budgetSourcesList" class="list-group">
                        <!-- Budget sources loaded here -->
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// ===================================================
// SHARED HEADERS
// ===================================================
function bsHeaders() {
    return {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    };
}

// ===================================================
// EXTRACT ERROR MESSAGE FROM LARAVEL RESPONSE
// ===================================================
function bsExtractErrorMessage(data, fallback = 'Something went wrong') {
    if (data && data.errors) {
        const firstField = Object.values(data.errors)[0];
        if (Array.isArray(firstField) && firstField.length > 0) {
            return firstField[0];
        }
    }
    return (data && data.message) ? data.message : fallback;
}

// ===================================================
// INLINE ERROR — shown below the name input
// ===================================================
function showBsNameError(message) {
    const el = document.getElementById('bsNameError');
    if (!el) return;
    el.textContent = message;
    el.classList.remove('d-none');
    const input = document.getElementById('newBudgetSourceName');
    if (input) input.classList.add('is-invalid');
}

function clearBsNameError() {
    const el = document.getElementById('bsNameError');
    if (el) {
        el.textContent = '';
        el.classList.add('d-none');
    }
    const input = document.getElementById('newBudgetSourceName');
    if (input) input.classList.remove('is-invalid');
}

// ===================================================
// ESCAPE HTML
// ===================================================
function bsEscapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// ===================================================
// LOAD ALL BUDGET SOURCES FOR MANAGEMENT
// ===================================================
async function loadBudgetSourcesForManagement() {
    try {
        const response = await fetch('{{ route("admin.budget-sources.all") }}', {
            headers: { 'Accept': 'application/json' }
        });
        const sources = await response.json();

        const listContainer = document.getElementById('budgetSourcesList');
        listContainer.innerHTML = '';

        if (!sources || sources.length === 0) {
            listContainer.innerHTML = '<p class="text-muted">No budget sources yet. Add one above.</p>';
            return;
        }

        let html = '';
        sources.forEach((source, index) => {
            try {
                html += `
                    <div class="list-group-item d-flex justify-content-between align-items-center p-3 border-bottom">
                        <div style="flex: 1;">
                            <h6 class="mb-1">${bsEscapeHtml(source.name)}</h6>
                            ${source.description ? `<small class="text-muted">${bsEscapeHtml(source.description)}</small>` : ''}
                            <small class="text-muted d-block">${source.projects_count || 0} project(s)</small>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary"
                                onclick="editBudgetSource(${source.id}, '${bsEscapeHtml(source.name)}', '${bsEscapeHtml(source.description || '')}')">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                onclick="deleteBudgetSource(${source.id}, '${bsEscapeHtml(source.name)}')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            } catch (e) {
                console.error('Error rendering budget source ' + index + ':', e, source);
            }
        });

        listContainer.innerHTML = html;
    } catch (error) {
        console.error('Error loading budget sources:', error);
        showToast('Failed to load budget sources', 'error');
    }
}

// ===================================================
// ADD NEW BUDGET SOURCE
// ===================================================
async function addNewBudgetSource() {
    clearBsNameError();

    const name = document.getElementById('newBudgetSourceName').value.trim();
    const description = document.getElementById('newBudgetSourceDescription').value.trim();

    if (!name) {
        showBsNameError('Please enter a budget source name');
        return;
    }
    if (name.length < 3) {
        showBsNameError('Budget source name must be at least 3 characters');
        return;
    }

    try {
        const response = await fetch('{{ route("admin.budget-sources.store") }}', {
            method: 'POST',
            headers: bsHeaders(),
            body: JSON.stringify({ name, description })
        });

        let data = {};
        try {
            data = await response.json();
        } catch {}

        if (!response.ok) {
            showBsNameError(bsExtractErrorMessage(data, 'Failed to create budget source'));
            return;
        }

        document.getElementById('newBudgetSourceName').value = '';
        document.getElementById('newBudgetSourceDescription').value = '';
        showToast('Budget source added successfully', 'success');

        setTimeout(() => {
            loadBudgetSourcesForManagement();
            if (typeof loadBudgetSources === 'function') loadBudgetSources();
        }, 300);

    } catch (error) {
        console.error('Error adding budget source:', error);
        showBsNameError('Network error — failed to add budget source');
    }
}

// ===================================================
// EDIT BUDGET SOURCE
// ===================================================
async function editBudgetSource(sourceId, name, description) {
    const newName = prompt('Enter new budget source name:', name);
    if (newName === null) return;

    if (!newName.trim()) {
        showToast('Budget source name cannot be empty', 'error');
        return;
    }
    if (newName.trim().length < 3) {
        showToast('Budget source name must be at least 3 characters', 'error');
        return;
    }

    try {
        const response = await fetch(`/admin/budget-sources/${sourceId}`, {
            method: 'PUT',
            headers: bsHeaders(),
            body: JSON.stringify({ name: newName.trim(), description })
        });

        let data = {};
        try {
            data = await response.json();
        } catch {}

        if (!response.ok) {
            showToast(bsExtractErrorMessage(data, 'Failed to update budget source'), 'error');
            return;
        }

        showToast('Budget source updated successfully', 'success');
        loadBudgetSourcesForManagement();
        if (typeof loadBudgetSources === 'function') loadBudgetSources();

    } catch (error) {
        console.error('Error updating budget source:', error);
        showToast('Network error — failed to update budget source', 'error');
    }
}

// ===================================================
// DELETE BUDGET SOURCE
// ===================================================
async function deleteBudgetSource(sourceId, sourceName) {
    showDeleteConfirmation(
        'Delete Budget Source',
        `Are you sure you want to delete <strong>"${bsEscapeHtml(sourceName)}"</strong>?<br><small style="color:#6b7280;">This action cannot be undone.</small>`,
        async () => {
            try {
                const response = await fetch(`/admin/budget-sources/${sourceId}`, {
                    method: 'DELETE',
                    headers: bsHeaders()
                });

                let data = {};
                try {
                    data = await response.json();
                } catch {}

                if (!response.ok) {
                    showToast(bsExtractErrorMessage(data, 'Failed to delete budget source'), 'error');
                    return;
                }

                showToast('Budget source deleted successfully', 'success');
                loadBudgetSourcesForManagement();
                if (typeof loadBudgetSources === 'function') loadBudgetSources();

            } catch (error) {
                console.error('Error deleting budget source:', error);
                showToast('Network error — failed to delete budget source', 'error');
            }
        }
    );
}

// ===================================================
// CLEAR INLINE ERROR ON INPUT
// ===================================================
document.addEventListener('DOMContentLoaded', function () {
    const nameInput = document.getElementById('newBudgetSourceName');
    if (nameInput) {
        nameInput.addEventListener('input', clearBsNameError);
    }
});

// ===================================================
// LOAD SOURCES WHEN MODAL OPENS
// ===================================================
document.addEventListener('show.bs.modal', function (e) {
    if (e.target.id === 'manageBudgetSourcesModal') {
        clearBsNameError();
        loadBudgetSourcesForManagement();
    }
});

// ===================================================
// REOPEN CREATE PROJECT MODAL WHEN THIS MODAL CLOSES
// ===================================================
document.addEventListener('hidden.bs.modal', function (e) {
    if (e.target.id === 'manageBudgetSourcesModal') {
        const returnModalId = e.target.dataset.returnModal || 'addProjectModal';
        e.target.dataset.returnModal = '';
        const returnModal = document.getElementById(returnModalId);
        if (!returnModal) return;

        // Stacking flow: return modal is still open underneath — just refresh dropdowns
        if (returnModal.classList.contains('show')) {
            if (typeof loadBudgetSources === 'function') {
                loadBudgetSources();
            }
            return;
        }

        // Chaining flow: clean up and reopen
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.overflow    = '';
        document.body.style.paddingRight = '';

        if (typeof loadBudgetSources === 'function') {
            Promise.resolve(loadBudgetSources()).finally(() => {
                new bootstrap.Modal(returnModal).show();
            });
        } else {
            new bootstrap.Modal(returnModal).show();
        }
    }
});
</script>
