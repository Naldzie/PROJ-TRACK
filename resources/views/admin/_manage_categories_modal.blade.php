<!-- === CATEGORY MANAGEMENT MODAL === -->
<div class="modal fade" id="manageCategoriesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title">
                    <i class="bi bi-tag"></i> Manage Categories
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
 
                <!-- Add New Category Section -->
                <div class="mb-4 p-3 bg-light border rounded">
                    <h6 class="mb-3"><i class="bi bi-plus-circle"></i> Add New Category</h6>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <input type="text" id="newCategoryName" class="form-control"
                                   placeholder="Category name (min. 3 characters)" maxlength="255">
                            {{-- Inline error: sits right under the input, always in the DOM --}}
                            <div id="nameError" class="invalid-feedback d-none" style="display:block!important;"></div>
                        </div>
                        <div class="col-md-6">
                            <button class="btn btn-primary w-100" onclick="addNewCategory()">
                                <i class="bi bi-plus"></i> Add
                            </button>
                        </div>
                    </div>
                    <textarea id="newCategoryDescription" class="form-control form-control-sm mt-2"
                              rows="2" placeholder="Description (optional)"></textarea>
                </div>
 
                <!-- Categories List -->
                <div>
                    <h6 class="mb-3"><i class="bi bi-list"></i> Existing Categories</h6>
                    <div id="categoriesList" class="list-group">
                        <!-- Categories loaded here -->
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
function categoryHeaders() {
    return {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    };
}
 
// ===================================================
// EXTRACT ERROR MESSAGE FROM LARAVEL RESPONSE
// Laravel validation: { message, errors: { field: ["msg"] } }
// ===================================================
function extractErrorMessage(data, fallback = 'Something went wrong') {
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
function showNameError(message) {
    const el = document.getElementById('nameError');
    if (!el) return;
    el.textContent = message;
    el.classList.remove('d-none');
    const input = document.getElementById('newCategoryName');
    if (input) input.classList.add('is-invalid');
}
 
function clearNameError() {
    const el = document.getElementById('nameError');
    if (el) {
        el.textContent = '';
        el.classList.add('d-none');
    }
    const input = document.getElementById('newCategoryName');
    if (input) input.classList.remove('is-invalid');
}
 
 
// ===================================================
// LOAD ALL CATEGORIES FOR MANAGEMENT
// ===================================================
async function loadCategoriesForManagement() {
    try {
        const response = await fetch('{{ route("admin.categories.all") }}', {
            headers: { 'Accept': 'application/json' }
        });
        const categories = await response.json();
 
        const listContainer = document.getElementById('categoriesList');
        listContainer.innerHTML = '';
 
        if (!categories || categories.length === 0) {
            listContainer.innerHTML = '<p class="text-muted">No categories yet. Add one above.</p>';
            return;
        }
 
        let html = '';
        categories.forEach((category, index) => {
            try {
                html += `
                    <div class="list-group-item d-flex justify-content-between align-items-center p-3 border-bottom">
                        <div style="flex: 1;">
                            <h6 class="mb-1">${escapeHtml(category.name)}</h6>
                            ${category.description ? `<small class="text-muted">${escapeHtml(category.description)}</small>` : ''}
                            <small class="text-muted d-block">${category.projects_count || 0} project(s)</small>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary"
                                onclick="editCategory(${category.id}, '${escapeHtml(category.name)}', '${escapeHtml(category.description || '')}')">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                onclick="deleteCategory(${category.id}, '${escapeHtml(category.name)}')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            } catch (e) {
                console.error('Error rendering category ' + index + ':', e, category);
            }
        });
 
        listContainer.innerHTML = html;
    } catch (error) {
        console.error('Error loading categories:', error);
        showToast('Failed to load categories', 'error');
    }
}
 
// ===================================================
// ADD NEW CATEGORY
// ===================================================
async function addNewCategory() {
    clearNameError();
 
    const name = document.getElementById('newCategoryName').value.trim();
    const description = document.getElementById('newCategoryDescription').value.trim();
 
    if (!name) {
        showNameError('Please enter a category name');
        return;
    }
    if (name.length < 3) {
        showNameError('Category name must be at least 3 characters');
        return;
    }
 
    try {
        const response = await fetch('{{ route("admin.categories.store") }}', {
            method: 'POST',
            headers: categoryHeaders(),
            body: JSON.stringify({ name, description })
        });
 
        let data = {};
        try {
            data = await response.json();
        } catch {}
 
        console.log('Add category response:', data);
 
        if (!response.ok) {
            showNameError(extractErrorMessage(data, 'Failed to create category'));
            return;
        }
 
        document.getElementById('newCategoryName').value = '';
        document.getElementById('newCategoryDescription').value = '';
        showToast('Category added successfully', 'success');
 
        setTimeout(() => {
            loadCategoriesForManagement();
            if (typeof loadProjectCategories === 'function') loadProjectCategories();
        }, 300);
 
    } catch (error) {
        console.error('Error adding category:', error);
        showNameError('Network error — failed to add category');
    }
}
 
// ===================================================
// EDIT CATEGORY
// ===================================================
async function editCategory(categoryId, name, description) {
    const newName = prompt('Enter new category name:', name);
    if (newName === null) return;
 
    if (!newName.trim()) {
        showToast('Category name cannot be empty', 'error');
        return;
    }
    if (newName.trim().length < 3) {
        showToast('Category name must be at least 3 characters', 'error');
        return;
    }
 
    try {
        const response = await fetch(`/admin/categories/${categoryId}`, {
            method: 'PUT',
            headers: categoryHeaders(),
            body: JSON.stringify({ name: newName.trim(), description })
        });
 
        let data = {};
        try {
            data = await response.json();
        } catch {}
 
        console.log('Edit category response:', data);
 
        if (!response.ok) {
            showToast(extractErrorMessage(data, 'Failed to update category'), 'error');
            return;
        }
 
        showToast('Category updated successfully', 'success');
        loadCategoriesForManagement();
        if (typeof loadProjectCategories === 'function') loadProjectCategories();
 
    } catch (error) {
        console.error('Error updating category:', error);
        showToast('Network error — failed to update category', 'error');
    }
}
 
// ===================================================
// DELETE CATEGORY
// ===================================================
async function deleteCategory(categoryId, categoryName) {
    if (!confirm(`Delete category "${categoryName}"? This cannot be undone.`)) {
        return;
    }
 
    try {
        const response = await fetch(`/admin/categories/${categoryId}`, {
            method: 'DELETE',
            headers: categoryHeaders()
        });
 
        let data = {};
        try {
            data = await response.json();
        } catch {}
 
        console.log('Delete response status:', response.status, response.ok);
        console.log('Delete response body:', data);
 
        if (!response.ok) {
            showToast(extractErrorMessage(data, 'Failed to delete category'), 'error');
            return;
        }
 
        showToast('Category deleted successfully', 'success');
        loadCategoriesForManagement();
        if (typeof loadProjectCategories === 'function') loadProjectCategories();
 
    } catch (error) {
        console.error('Error deleting category:', error);
        showToast('Network error — failed to delete category', 'error');
    }
}
 
// ===================================================
// ESCAPE HTML
// ===================================================
function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}
 
// Clear inline error when user starts typing
document.addEventListener('DOMContentLoaded', function () {
    const nameInput = document.getElementById('newCategoryName');
    if (nameInput) {
        nameInput.addEventListener('input', clearNameError);
    }
});
 
// Load categories when modal opens
document.addEventListener('show.bs.modal', function (e) {
    if (e.target.id === 'manageCategoriesModal') {
        clearNameError();
        loadCategoriesForManagement();
    }
});
 
// Reopen create project modal when this modal closes
document.addEventListener('hidden.bs.modal', function (e) {
    if (e.target.id === 'manageCategoriesModal') {
        const returnModalId = e.target.dataset.returnModal || 'addProjectModal';
        e.target.dataset.returnModal = '';
        const returnModal = document.getElementById(returnModalId);
        if (!returnModal) return;

        // Stacking flow: return modal is still open underneath — just refresh dropdowns
        if (returnModal.classList.contains('show')) {
            if (typeof loadProjectCategories === 'function') {
                loadProjectCategories();
            }
            return;
        }

        // Chaining flow: clean up and reopen
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.overflow    = '';
        document.body.style.paddingRight = '';

        if (typeof loadProjectCategories === 'function') {
            Promise.resolve(loadProjectCategories()).finally(() => {
                new bootstrap.Modal(returnModal).show();
            });
        } else {
            new bootstrap.Modal(returnModal).show();
        }
    }
});
</script>