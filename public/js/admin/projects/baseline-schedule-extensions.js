/**
 * Baseline Schedule Extensions Feature
 * Handles project deadline extension tracking, display, and management
 * 
 * Dependencies:
 * - window.projectId (set by baseline-schedule.blade.php)
 * - window.routePrefix (set by baseline-schedule.blade.php)
 */

// Load extensions when page loads
document.addEventListener('DOMContentLoaded', function() {
    reloadExtensionsTable();
});

/**
 * Load and display the extensions table
 * Fetches extension data from the API and renders as a table or empty state
 */
function reloadExtensionsTable() {
    const projectId = window.projectId;
    const container = document.getElementById('extensionsTableContainer');
    
    if (!container) {
        console.error('[reloadExtensionsTable] Extensions table container not found');
        return;
    }
    
    // Determine role-aware endpoint
    const routePrefix = window.routePrefix;
    const extensionsEndpoint = routePrefix === 'admin.projects' 
        ? `/admin/projects/${projectId}/extensions`
        : (routePrefix === 'staff.projects' 
            ? `/staff/projects/${projectId}/extensions`
            : `/contractor/projects/${projectId}/extensions`);
    
    console.log('[reloadExtensionsTable] Fetching extensions from:', extensionsEndpoint);
    
    fetch(extensionsEndpoint, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
    })
    .then(data => {
        console.log('[reloadExtensionsTable] Extensions data received:', data);
        
        // Verify templates exist
        const tableTemplate = document.getElementById('extensionsTableTemplate');
        const emptyTemplate = document.getElementById('extensionsEmptyTemplate');
        
        if (!tableTemplate || !emptyTemplate) {
            console.error('[reloadExtensionsTable] Templates not found. Table template:', !!tableTemplate, 'Empty template:', !!emptyTemplate);
            container.innerHTML = '<div style="color: #ef4444; padding: 20px; text-align: center; font-size: 13px;">Error: Templates not found</div>';
            return;
        }
        
        // If extensions exist, render the table
        if (data.extensions && data.extensions.length > 0) {
            try {
                const tableClone = tableTemplate.content.cloneNode(true);
                const tbody = tableClone.querySelector('#extensionsTableBody');
                
                if (!tbody) {
                    console.error('[reloadExtensionsTable] Table body not found in template');
                    return;
                }
                
                // The ACTIVE approved extension — the highest-numbered
                // approved one currently driving the project deadline —
                // cannot be deleted. Mirrors the backend guard.
                // (Extensions arrive ordered ascending by extension_number.)
                let activeApprovedId = null;
                data.extensions.forEach(ext => {
                    if (ext.status === 'approved') activeApprovedId = ext.id;
                });

                // Build table rows for each extension
                data.extensions.forEach((ext, index) => {
                    const row = document.createElement('tr');
                    if (index % 2 === 0) row.style.background = '#f9fafb';
                    row.style.borderBottom = '1px solid #e5e7eb';
                    
                    // Calculate days added
                    const daysAdded = Math.ceil((new Date(ext.new_end_date) - new Date(ext.original_end_date)) / (1000 * 60 * 60 * 24));
                    
                    // Format dates as readable strings
                    const fromDate = new Date(ext.original_end_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
                    const toDate = new Date(ext.new_end_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});

                    // Status badge — reflects the real status from the
                    // backend instead of a hardcoded "Approved".
                    const statusStyles = {
                        approved: { bg: '#dcfce7', color: '#166534', label: '✓ Approved' },
                        pending: { bg: '#fef3c7', color: '#92400e', label: '⏳ Pending' },
                        rejected: { bg: '#fee2e2', color: '#991b1b', label: '✕ Rejected' },
                        superseded: { bg: '#e2e8f0', color: '#475569', label: '↩ Superseded' }
                    };
                    const statusStyle = statusStyles[ext.status] || statusStyles.pending;
                    const statusTitle = (ext.status === 'rejected' && ext.rejection_reason)
                        ? ` title="${ext.rejection_reason.replace(/"/g, '&quot;')}"`
                        : '';

                    // Review aging (Decision 5): days awaiting review for pendings.
                    let agingBadge = '';
                    if (ext.status === 'pending' && ext.days_awaiting_review > 0) {
                        agingBadge = `<span style="background: #fffbeb; color: #b45309; border: 1px solid #fde68a; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; margin-left: 4px;" title="Days awaiting admin review">${ext.days_awaiting_review}d</span>`;
                    }

                    // Evidence (Decision 4): count chip + links to each file.
                    let evidenceCell = '';
                    if (ext.attachments_count > 0) {
                        const links = (ext.attachments || []).map(a =>
                            `<a href="${a.file_url}" target="_blank" style="color:#4f46e5;text-decoration:none;font-size:11px;" title="${(a.original_name || '').replace(/"/g, '&quot;')}"><i class="bi bi-paperclip"></i> ${a.original_name || a.file_url}</a>`
                        ).join('<br>');
                        evidenceCell = `<div style="display:inline-flex;align-items:center;gap:4px;color:#4f46e5;font-size:11px;font-weight:600;">${links}</div>`;
                    }

                    // Editable for admin always; for staff only while
                    // pending/rejected. Backend is the real enforcement
                    // point regardless — this just controls button visibility.
                    const isAdmin = window.routePrefix === 'admin.projects';
                    const isStaff = window.routePrefix === 'staff.projects';
                    const canEdit = isAdmin || ext.status === 'pending' || ext.status === 'rejected';
                    // Delete: admin may delete any row EXCEPT the active
                    // approved extension; staff may delete pending/rejected
                    // requests. Backend enforces the same rules.
                    const isActiveApproved = ext.status === 'approved' && ext.id === activeApprovedId;
                    const canDelete = isAdmin ? !isActiveApproved : (isStaff && (ext.status === 'pending' || ext.status === 'rejected'));
                    // Resubmit (Decision 6): only rejected rows, staff or admin
                    // (contractors have no write routes).
                    const canResubmit = ext.status === 'rejected' && (isAdmin || isStaff);

                    row.innerHTML = `
                        <td style="padding: 12px 15px; font-size: 13px; font-weight: 600; color: #667eea;">#${ext.extension_number}</td>
                        <td style="padding: 12px 15px; font-size: 13px; color: #374151;">${fromDate}</td>
                        <td style="padding: 12px 15px; font-size: 13px; color: #374151; font-weight: 600;">${toDate}${ext.approved_new_end_date && ext.approved_new_end_date !== ext.new_end_date ? `<div style="font-size:10px;color:#7c3aed;font-weight:600;">Granted: ${new Date(ext.approved_new_end_date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</div>` : ''}</td>
                        <td style="padding: 12px 15px; text-align: center; font-size: 13px; font-weight: 600; color: #f59e0b;">+${daysAdded} days</td>
                        <td style="padding: 12px 15px; font-size: 12px; color: #475569;">${ext.reason_category ? `<span style="background:#f1f5f9;color:#334155;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;">${ext.reason_category}</span>` : '<span style="color:#cbd5e1;">—</span>'}</td>
                        <td style="padding: 12px 15px; font-size: 13px; color: #6b7280;">${ext.reason || 'N/A'}${evidenceCell ? `<div style="margin-top:4px;">${evidenceCell}</div>` : ''}</td>
                        <td style="padding: 12px 15px; text-align: center;">
                            <span${statusTitle} style="background: ${statusStyle.bg}; color: ${statusStyle.color}; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: ${ext.status === 'rejected' && ext.rejection_reason ? 'help' : 'default'};">${statusStyle.label}</span>${agingBadge}
                        </td>
                        <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                            ${canEdit ? `<button onclick='openEditExtensionModal(${JSON.stringify(ext)})' style="background: #eef2ff; color: #4f46e5; border: 1px solid #c7d2fe; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer; margin-right: 6px;"><i class="bi bi-pencil"></i> Edit</button>` : ''}
                            ${canResubmit ? `<button onclick='openResubmitExtensionModal(${JSON.stringify(ext)})' style="background: #ecfeff; color: #0e7490; border: 1px solid #a5f3fc; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer; margin-right: 6px;" title="Resubmit as a new request"><i class="bi bi-arrow-repeat"></i> Resubmit</button>` : ''}
                            ${canDelete ? `<button onclick='deleteExtension(${JSON.stringify(ext)})' style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer;"><i class="bi bi-trash"></i> Delete</button>` : ''}
                            ${(!canEdit && !canDelete && !canResubmit) ? '<span style="color: #d1d5db; font-size: 11px;">—</span>' : ''}
                        </td>
                    `;
                    tbody.appendChild(row);
                });
                
                // Clear container and insert table
                container.innerHTML = '';
                container.appendChild(tableClone);
                
                console.log('[reloadExtensionsTable] Table rendered successfully with', data.extensions.length, 'extensions');
            } catch (error) {
                console.error('[reloadExtensionsTable] Error building table:', error);
                container.innerHTML = '<div style="color: #ef4444; padding: 20px; text-align: center; font-size: 13px;">Error rendering extensions table</div>';
            }
        } else {
            // No extensions - render empty state
            try {
                const emptyClone = emptyTemplate.content.cloneNode(true);
                container.innerHTML = '';
                container.appendChild(emptyClone);
                console.log('[reloadExtensionsTable] No extensions - empty state rendered');
            } catch (error) {
                console.error('[reloadExtensionsTable] Error rendering empty state:', error);
                container.innerHTML = '<div style="padding: 40px; text-align: center; color: #9ca3af; border: 2px dashed #d1d5db; border-radius: 8px;"><i class="bi bi-inbox" style="font-size: 40px; display: block; margin-bottom: 12px; opacity: 0.5;"></i><div style="font-size: 14px; font-weight: 500; margin-bottom: 6px;">No extensions recorded yet</div><div style="font-size: 12px; color: #d1d5db;">Click "Request Extension" to extend the project deadline</div></div>';
            }
        }
    })
    .catch(error => {
        console.error('[reloadExtensionsTable] Fetch error:', error);
        container.innerHTML = '<div style="color: #ef4444; padding: 20px; text-align: center; font-size: 13px;">Error loading extensions: ' + error.message + '</div>';
    });
}

/**
 * Reload project data and refresh the page
 * Used after operations that modify extension data
 */
function reloadProjectData() {
    console.log('[reloadProjectData] Reloading page to refresh all data');
    setTimeout(() => location.reload(), 1500);
}

/**
 * Delete an extension via the role-aware DELETE endpoint.
 * Success: queue a success toast and reload, matching the baseline
 * toast-after-reload pattern. Failure (incl. the backend's guard
 * rejection): show an inline danger toast and refresh the table.
 */
function deleteExtension(ext) {
    const confirmed = confirm(
        `Delete extension #${ext.extension_number} (${ext.status})?\nThis cannot be undone.`
    );
    if (!confirmed) return;

    console.log(`[deleteExtension] Deleting extension #${ext.extension_number}...`);

    fetch(buildExtensionsEndpoint(ext.id), {
        method: 'DELETE',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        return response.json().catch(() => ({}))
            .then(body => ({ ok: response.ok, body }));
    })
    .then(({ ok, body }) => {
        if (ok) {
            queueToastAfterReload(`Extension #${ext.extension_number} deleted.`, 'success');
            setTimeout(() => window.location.reload(), 600);
        } else {
            showToast(body.message || `Could not delete extension #${ext.extension_number}.`, 'danger');
            reloadExtensionsTable();
        }
    })
    .catch(error => {
        console.error('[deleteExtension] Error:', error);
        showToast('Could not delete this extension.', 'danger');
        reloadExtensionsTable();
    });
}

// ==== ARCHIVED EXTENSIONS PANEL (Decision 12, admin only) ====

/**
 * Toggle the archived (soft-deleted) extensions panel.
 */
window.toggleArchivedPanel = function () {
    const panel = document.getElementById('archivedExtensionsPanel');
    if (!panel) return;
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        loadArchivedExtensions();
    } else {
        panel.style.display = 'none';
    }
};

/**
 * Load the archived (soft-deleted) extensions list into the panel.
 */
window.loadArchivedExtensions = function () {
    const container = document.getElementById('archivedExtensionsContainer');
    if (!container) return;

    const projectId = window.projectId;
    const endpoint = `/admin/projects/${projectId}/extensions/archived`;

    fetch(endpoint, {
        method: 'GET',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) throw new Error(data.message || 'Failed to load archived extensions');

        const panel = document.getElementById('archivedExtensionsPanel');
        if (panel) panel.style.display = 'block';

        if (!data.extensions || data.extensions.length === 0) {
            container.innerHTML = '<div style="padding: 20px; text-align: center; color: #94a3b8; font-size: 13px;">No archived extensions.</div>';
            return;
        }

        let rows = data.extensions.map(ext => {
            const fromDate = new Date(ext.original_end_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
            const toDate = new Date(ext.new_end_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
            return `
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px;">
                    <div style="min-width:0;">
                        <div style="font-size:13px;font-weight:700;color:#334155;">#${ext.extension_number} <span style="font-weight:500;color:#64748b;">· ${fromDate} → ${toDate}</span></div>
                        <div style="font-size:12px;color:#94a3b8;">${ext.reason_category ? ext.reason_category + ' · ' : ''}${(ext.reason || 'N/A').substring(0, 60)}${(ext.reason || '').length > 60 ? '…' : ''}</div>
                    </div>
                    <div style="white-space:nowrap;">
                        <button onclick='restoreArchivedExtension(${JSON.stringify(ext)})' style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:5px 10px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;margin-right:6px;"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                        <button onclick='forceDeleteArchivedExtension(${JSON.stringify(ext)})' style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;padding:5px 10px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;"><i class="bi bi-trash"></i> Delete Forever</button>
                    </div>
                </div>
            `;
        }).join('');

        container.innerHTML = rows;
    })
    .catch(error => {
        console.error('[loadArchivedExtensions] Error:', error);
        container.innerHTML = `<div style="padding: 20px; text-align: center; color: #dc2626; font-size: 13px;">Error: ${error.message}</div>`;
    });
};

/**
 * Restore a soft-deleted extension (admin only).
 */
window.restoreArchivedExtension = function (ext) {
    const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) return;

    const endpoint = `/admin/projects/${window.projectId}/extensions/${ext.id}/restore`;

    fetch(endpoint, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json().catch(() => ({})))
    .then(body => {
        if (body.success) {
            showToast(`Extension #${ext.extension_number} restored.`, 'success');
            loadArchivedExtensions();
            reloadExtensionsTable();
        } else {
            showToast(body.message || 'Could not restore this extension.', 'danger');
        }
    })
    .catch(error => {
        console.error('[restoreArchivedExtension] Error:', error);
        showToast('Could not restore this extension.', 'danger');
    });
};

/**
 * Permanently delete a soft-deleted extension (admin only).
 */
window.forceDeleteArchivedExtension = function (ext) {
    const confirmed = confirm(
        `Permanently delete extension #${ext.extension_number}?\nThis removes the record AND its evidence files. This cannot be undone.`
    );
    if (!confirmed) return;

    const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) return;

    const endpoint = `/admin/projects/${window.projectId}/extensions/${ext.id}/force`;

    fetch(endpoint, {
        method: 'DELETE',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json().catch(() => ({})))
    .then(body => {
        if (body.success) {
            showToast(`Extension #${ext.extension_number} permanently deleted.`, 'success');
            loadArchivedExtensions();
        } else {
            showToast(body.message || 'Could not permanently delete this extension.', 'danger');
        }
    })
    .catch(error => {
        console.error('[forceDeleteArchivedExtension] Error:', error);
        showToast('Could not permanently delete this extension.', 'danger');
    });
};