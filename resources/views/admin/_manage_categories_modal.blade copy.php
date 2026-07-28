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
                <!-- Error Alert -->
                <div id="categoryError" class="alert alert-danger d-none" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <span id="categoryErrorMessage"></span>
                </div>
 
                <!-- Add New Category Section -->
                <div class="mb-4 p-3 bg-light border rounded">
                    <h6 class="mb-3"><i class="bi bi-plus-circle"></i> Add New Category</h6>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <input type="text" id="newCategoryName" class="form-control" 
                                   placeholder="Category name (min. 3 characters)" maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <button class="btn btn-primary w-100" onclick="addNewCategory()">
                                <i class="bi bi-plus"></i> Add
                            </button>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">
                        <textarea id="newCategoryDescription" class="form-control form-control-sm mt-2" 
                                  rows="2" placeholder="Description (optional)"></textarea>
                    </small>
                </div>
 
                <!-- Categories List -->
                <div>
                    <h6 class="mb-3"><i class="bi bi-list"></i> Existing Categories</h6>
                    <div id="categoriesList" class="list-group">
                        <!-- Categories will be loaded here -->
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
// Defined as a function so the CSRF token is only
// looked up when a request is made, not at page load.
// Using a const instead caused: "Cannot read properties
// of null (reading 'content')" because the script ran
// before the meta tag was available.
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
// Laravel validation returns: { message, errors: { field: ["msg"] } }
// This pulls the first field-level error when present
// ===================================================
function extractErrorMessage(data, fallback = 'Something went wrong') {
    if (data.errors) {
        const firstField = Object.values(data.errors)[0];
        if (Array.isArray(firstField) && firstField.length > 0) {
            return firstField[0];
        }
    }
    return data.message || fallback;
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
                            <button class="btn btn-sm btn-outline-primary" onclick="editCategory(${category.id}, '${escapeHtml(category.name)}', '${escapeHtml(category.description || '')}')">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteCategory(${category.id})">
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
        showCategoryError('Failed to load categories');
    }
}
 
// ===================================================
// ADD NEW CATEGORY
// ===================================================
async function addNewCategory() {
    const name = document.getElementById('newCategoryName').value.trim();
    const description = document.getElementById('newCategoryDescription').value.trim();
 
    if (!name) {
        showCategoryError('Please enter a category name');
        return;
    }
 
    if (name.length < 3) {
        showCategoryError('Category name must be at least 3 characters');
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
            console.log('Server Response:', data);
        } catch {
            // Response body wasn't JSON (e.g. Laravel returned HTML for the error)
        }
 
        if (!response.ok) {
            showCategoryError(extractErrorMessage(data, 'Failed to create category'));
            return;
        }
 
        document.getElementById('newCategoryName').value = '';
        document.getElementById('newCategoryDescription').value = '';
 
        showCategorySuccess('Category added successfully');
 
        setTimeout(() => {
            loadCategoriesForManagement();
            loadProjectCategories();
        }, 500);
    } catch (error) {
        console.error('Error adding category:', error);
        showCategoryError('Failed to add category');
    }
}
 
// ===================================================
// EDIT CATEGORY
// ===================================================
async function editCategory(categoryId, name, description) {
    const newName = prompt('Enter new category name:', name);
    if (newName === null) return;
 
    if (!newName.trim()) {
        showCategoryError('Category name cannot be empty');
        return;
    }
 
    if (newName.trim().length < 3) {
        showCategoryError('Category name must be at least 3 characters');
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
        } catch {
            // Response body wasn't JSON
        }
 
        if (!response.ok) {
            showCategoryError(extractErrorMessage(data, 'Failed to update category'));
            return;
        }
 
        loadCategoriesForManagement();
        loadProjectCategories();
        showCategorySuccess('Category updated successfully');
    } catch (error) {
        console.error('Error updating category:', error);
        showCategoryError('Failed to update category');
    }
}
 
// ===================================================
// DELETE CATEGORY
// ===================================================
async function deleteCategory(categoryId) {
    if (!confirm('Are you sure you want to delete this category?')) {
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
        } catch {
            // Response body wasn't JSON
        }
 
        if (!response.ok) {
            showCategoryError(extractErrorMessage(data, 'Failed to delete category'));
            return;
        }
 
        loadCategoriesForManagement();
        loadProjectCategories();
        showCategorySuccess('Category deleted successfully');
    } catch (error) {
        console.error('Error deleting category:', error);
        showCategoryError('Failed to delete category');
    }
}
 
// ===================================================
// SHOW ERROR AND SUCCESS
// ===================================================
function showCategoryError(message) {
    const errorDiv = document.getElementById('categoryError');
    const errorMsg = document.getElementById('categoryErrorMessage');

    //Guard: if elements are not in DOM yet, fall back to console
    if(!errorDiv || !errorMsg) {
        console.error('showCategoryError: (element not found): ', message);
        return;
    }
    errorMsg.textContent = message;
    errorDiv.classList.remove('d-none');
    setTimeout(() => errorDiv.classList.add('d-none'), 5000);
}
 
function showCategorySuccess(message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-success position-fixed bottom-0 end-0 m-3';
    alertDiv.innerHTML = `<i class="bi bi-check-circle"></i> ${message}`;
    document.body.appendChild(alertDiv);
    setTimeout(() => alertDiv.remove(), 3000);
}
 
// ===================================================
// ESCAPE HTML
// ===================================================
function escapeHtml(text) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return text.replace(/[&<>"']/g, m => map[m]);
}
 
// Load categories when modal is shown
document.addEventListener('show.bs.modal', function(e) {
    if (e.target.id === 'manageCategoriesModal') {
        loadCategoriesForManagement();
    }
});
 
// Reopen create project modal when categories modal is closed
document.addEventListener('hidden.bs.modal', function(e) {
    if (e.target.id === 'manageCategoriesModal') {
        const projectModal = document.getElementById('addProjectModal');
        if (projectModal) {
            new bootstrap.Modal(projectModal).show();
        }
    }
});
</script>
 