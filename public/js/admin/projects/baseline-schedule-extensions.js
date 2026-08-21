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
 * Open the document viewer for an extension attachment using role-scoped routes.
 */
function openExtensionAttachmentById(id) {
    var att = (window._extensionAttachments || {})[id];
    if (!att || typeof openDocumentViewer !== 'function') return;
    var role = window.userRole;
    openDocumentViewer({
        id: att.id,
        name: att.original_name,
        type: 'Extension Evidence',
        size: att.display_size || '',
        uploadedBy: att.uploaded_by || '',
        uploadedAt: att.created_at || '',
        viewUrl: '/' + role + '/attachments/' + att.id + '/view',
        downloadUrl: '/' + role + '/attachments/' + att.id + '/download',
        previewUrl: '/' + role + '/attachments/' + att.id + '/preview'
    });
}

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

        // Toggle "Add Extension" button: hidden when a pending extension exists.
        const addBtn = document.getElementById('addExtensionBtn');
        if (addBtn) {
            const hasPending = data.extensions && data.extensions.some(e => e.status === 'pending');
            addBtn.style.display = hasPending ? 'none' : 'inline-flex';
        }
        
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
                    
                    // Calculate days added — always against the EFFECTIVE date
                    // (granted when partially approved, else requested) so a
                    // partial grant of 15/31 days shows +15, matching the cards.
                    const effectiveDate = ext.effective_end_date || ext.new_end_date;
                    const daysAdded = Math.ceil((new Date(effectiveDate) - new Date(ext.original_end_date)) / (1000 * 60 * 60 * 24));
                    
                    // Format dates as readable strings
                    const fromDate = new Date(ext.original_end_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
                    const toDate = new Date(effectiveDate).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
                    // Partial approval (Decision 7): granted differs from what
                    // was requested — show the original ask as the small note.
                    const isPartialGrant = ext.approved_new_end_date && ext.approved_new_end_date !== ext.new_end_date;

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

                    // Evidence (Decision 4): count chip + clickable chips that
                    // open the document viewer modal (same pattern as issues/
                    // progress/risk attachments).
                    let evidenceCell = '';
                    if (ext.attachments_count > 0) {
                        window._extensionAttachments = window._extensionAttachments || {};
                        const links = (ext.attachments || []).map(a => {
                            window._extensionAttachments[a.id] = a;
                            return `<span class="attachment-chip me-1 mb-1" title="${(a.original_name || '').replace(/"/g, '&quot;')}" onclick="openExtensionAttachmentById(${a.id})" style="cursor:pointer;color:#4f46e5;font-size:11px;font-weight:600;"><i class="bi bi-paperclip"></i> ${a.original_name || 'file'}</span>`;
                        }).join(' ');
                        evidenceCell = `<div style="display:inline-flex;align-items:center;gap:4px;flex-wrap:wrap;">${links}</div>`;
                    }

                    // Editable for admin always; for staff only while
                    // PENDING and OWNED by them (Decision 14). Backend is the
                    // real enforcement point regardless — this just controls
                    // button visibility.
                    const isAdmin = window.routePrefix === 'admin.projects';
                    const isStaff = window.routePrefix === 'staff.projects';
                    const isOwner = Number(ext.created_by_user_id) === Number(window.authUserId);
                    // Admin may edit any row (corrections); staff may edit their
                    // own pending request only. Rejected is NOT editable —
                    // Resubmit is the only way back to review.
                    const canEdit = isAdmin || (isStaff && isOwner && ext.status === 'pending');
                    // Delete: admin may delete any row EXCEPT the active
                    // approved extension; staff may delete their OWN
                    // pending/rejected requests (Decision 14).
                    const isActiveApproved = ext.status === 'approved' && ext.id === activeApprovedId;
                    const canDelete = isAdmin ? !isActiveApproved : (isStaff && isOwner && (ext.status === 'pending' || ext.status === 'rejected'));
                    // Resubmit (Decision 6): only rejected rows; staff may only
                    // resubmit their OWN (Decision 14). Admin may resubmit any.
                    const canResubmit = ext.status === 'rejected' && (isAdmin || (isStaff && isOwner));
                    // Review (Decision 13): admin decides on pending requests
                    // via the dedicated Review modal.
                    const canReview = isAdmin && ext.status === 'pending';

                    row.innerHTML = `
                        ${isAdmin ? `<td style="padding: 12px 15px; text-align: center;"><input type="checkbox" class="extension-row-checkbox" value="${ext.id}" data-number="${ext.extension_number}" data-status="${ext.status}" style="cursor: pointer; width: 14px; height: 14px;"></td>` : ''}
                        <td style="padding: 12px 15px; font-size: 13px; font-weight: 600; color: #667eea;">#${ext.extension_number}</td>
                        <td style="padding: 12px 15px; font-size: 12px; color: #475569;">${ext.created_by || '<span style="color:#cbd5e1;">—</span>'}</td>
                        <td style="padding: 12px 15px; font-size: 13px; color: #374151;">${fromDate}</td>
                        <td style="padding: 12px 15px; font-size: 13px; color: #374151; font-weight: 600;">${toDate}${isPartialGrant ? `<div style="font-size:10px;color:#7c3aed;font-weight:600;">Requested: ${new Date(ext.new_end_date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</div>` : ''}</td>
                        <td style="padding: 12px 15px; text-align: center; font-size: 13px; font-weight: 600; color: #f59e0b;">+${daysAdded} days</td>
                        <td style="padding: 12px 15px; font-size: 12px; color: #475569;">${ext.reason_category ? `<span style="background:#f1f5f9;color:#334155;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;">${ext.reason_category}</span>` : '<span style="color:#cbd5e1;">—</span>'}</td>
                        <td style="padding: 12px 15px; font-size: 13px; color: #6b7280;">${ext.reason || 'N/A'}${evidenceCell ? `<div style="margin-top:4px;">${evidenceCell}</div>` : ''}</td>
                        <td style="padding: 12px 15px; text-align: center;">
                            <span${statusTitle} style="background: ${statusStyle.bg}; color: ${statusStyle.color}; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: ${ext.status === 'rejected' && ext.rejection_reason ? 'help' : 'default'};">${statusStyle.label}</span>${agingBadge}
                        </td>
                        <td style="padding: 12px 15px; text-align: center; font-size: 12px; color: #475569;">${ext.status === 'approved' ? (ext.approved_by_name || ext.approved_by || '<span style="color:#cbd5e1;">—</span>') : '<span style="color:#cbd5e1;">—</span>'}</td>
                        <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                            ${canReview ? `<button onclick='openReviewExtensionModal(${JSON.stringify(ext)})' style="background: #dcfce7; color: #166534; border: 1px solid #86efac; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer; margin-right: 6px;" title="Approve, partially approve, or reject this request"><i class="bi bi-gavel"></i> Review</button>` : ''}
                            ${canEdit ? `<button onclick='openEditExtensionModal(${JSON.stringify(ext)})' style="background: #eef2ff; color: #4f46e5; border: 1px solid #c7d2fe; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer; margin-right: 6px;"><i class="bi bi-pencil"></i> Edit</button>` : ''}
                            ${canResubmit ? `<button onclick='openResubmitExtensionModal(${JSON.stringify(ext)})' style="background: #ecfeff; color: #0e7490; border: 1px solid #a5f3fc; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer; margin-right: 6px;" title="Resubmit as a new request"><i class="bi bi-arrow-repeat"></i> Resubmit</button>` : ''}
                            ${canDelete ? `<button onclick='deleteExtension(${JSON.stringify(ext)})' style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer;" title="Move to Archived Extensions (reversible)"><i class="bi bi-archive"></i> Archive</button>` : ''}
                            ${(!canEdit && !canDelete && !canResubmit && !canReview) ? '<span style="color: #d1d5db; font-size: 11px;">—</span>' : ''}
                        </td>
                    `;
                    tbody.appendChild(row);
                });
                
                // Clear container and insert table
                container.innerHTML = '';
                container.appendChild(tableClone);

                if (window.routePrefix === 'admin.projects') {
                    wireExtensionSelectionHandlers();
                    updateExtensionSelection();
                }
                
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
                container.innerHTML = '<div style="padding: 40px; text-align: center; color: #9ca3af; border: 2px dashed #d1d5db; border-radius: 8px;"><i class="bi bi-inbox" style="font-size: 40px; display: block; margin-bottom: 12px; opacity: 0.5;"></i><div style="font-size: 14px; font-weight: 500; margin-bottom: 6px;">No extensions recorded yet</div><div style="font-size: 12px; color: #d1d5db;">Click "Add Extension" to extend the project deadline</div></div>';
            }
        }
    })
    .catch(error => {
        console.error('[reloadExtensionsTable] Fetch error:', error);
        container.innerHTML = '<div style="color: #ef4444; padding: 20px; text-align: center; font-size: 13px;">Error loading extensions: ' + error.message + '</div>';
    });
}

/**
 * Wire the bulk-selection checkboxes (admin only, §8 bulk ops):
 * the "Select All" header checkbox and per-row checkboxes. Row checkboxes are
 * rendered inside the template clone, so they need a fresh pass each reload.
 */
function wireExtensionSelectionHandlers() {
    const selectAll = document.getElementById('extensionsSelectAll');
    if (selectAll) {
        selectAll.checked = false;
        selectAll.onchange = function () {
            document.querySelectorAll('.extension-row-checkbox').forEach(cb => {
                cb.checked = selectAll.checked;
            });
            updateExtensionSelection();
        };
    }

    document.querySelectorAll('.extension-row-checkbox').forEach(cb => {
        cb.addEventListener('change', () => {
            const selectAllEl = document.getElementById('extensionsSelectAll');
            if (selectAllEl) {
                const boxes = document.querySelectorAll('.extension-row-checkbox');
                selectAllEl.checked = boxes.length > 0 && Array.from(boxes).every(b => b.checked);
            }
            updateExtensionSelection();
        });
    });
}

/**
 * Update the bulk action bar based on the selected row checkboxes. Only rows
 * that are actually deletable (non-approved) are counted/enabled — approved
 * rows are excluded since they must be reverted first (Decision 10).
 */
function updateExtensionSelection() {
    const bar = document.getElementById('extensionsBulkBar');
    if (!bar) return;

    const selected = getSelectedExtensionIds();
    const countEl = document.getElementById('extensionsBulkCount');
    if (countEl) countEl.textContent = selected.length;

    bar.style.display = selected.length > 0 ? 'flex' : 'none';
}

/**
 * Collect the ids (and statuses) of the currently selected row checkboxes.
 */
function getSelectedExtensionIds() {
    return Array.from(document.querySelectorAll('.extension-row-checkbox:checked'))
        .map(cb => ({
            id: Number(cb.value),
            number: Number(cb.dataset.number),
            status: cb.dataset.status,
        }));
}

/**
 * Clear all selections and hide the bulk bar.
 */
window.clearExtensionSelection = function () {
    document.querySelectorAll('.extension-row-checkbox').forEach(cb => { cb.checked = false; });
    const selectAll = document.getElementById('extensionsSelectAll');
    if (selectAll) selectAll.checked = false;
    updateExtensionSelection();
};

/**
 * Bulk-archive the selected extensions (admin only). Approved rows are
 * excluded from the payload — the backend skips them anyway.
 */
window.archiveSelectedExtensions = function () {
    const selected = getSelectedExtensionIds().filter(s => s.status !== 'approved');
    if (selected.length === 0) {
        showToast('No eligible extensions selected. Approved extensions must be reverted before archiving.', 'danger');
        return;
    }

    const labels = selected.map(s => '#' + s.number).join(', ');
    showDeleteConfirmation(
        `Archive ${selected.length} Extension${selected.length > 1 ? 's' : ''}`,
        `Move extension(s) <strong>${labels}</strong> to the <strong>Archived Extensions</strong> panel?` +
        `<br><br>They can be <strong>restored</strong> at any time from that panel. They will NOT be permanently deleted now.`,
        function () { performBulkArchive(selected); }
    );
};

function performBulkArchive(selected) {
    const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) {
        showToast('Error: CSRF token not found. Please refresh the page and try again.', 'danger');
        return;
    }

    const endpoint = `/admin/projects/${window.projectId}/extensions/bulk-delete`;

    fetch(endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ ids: selected.map(s => s.id) })
    })
    .then(response => {
        return response.json().catch(() => ({}))
            .then(body => ({ ok: response.ok, body }));
    })
    .then(({ ok, body }) => {
        if (ok) {
            queueToastAfterReload(body.message || 'Extensions archived.', 'success');
            sessionStorage.setItem('activeTab', 's-curve-planning');
            setTimeout(() => window.location.reload(), 600);
        } else {
            showToast(body.message || 'Could not archive the selected extensions.', 'danger');
            reloadExtensionsTable();
        }
    })
    .catch(error => {
        console.error('[performBulkArchive] Error:', error);
        showToast('Could not archive the selected extensions.', 'danger');
        reloadExtensionsTable();
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
 * Build the role-aware extension endpoint. With an extension id appended for
 * row-level operations (edit/delete); without, the collection endpoint.
 */
function buildExtensionsEndpoint(extensionId = '') {
    const routePrefix = window.routePrefix;
    const base = routePrefix === 'admin.projects'
        ? `/admin/projects/${window.projectId}/extensions`
        : (routePrefix === 'staff.projects'
            ? `/staff/projects/${window.projectId}/extensions`
            : `/contractor/projects/${window.projectId}/extensions`);
    return extensionId ? `${base}/${extensionId}` : base;
}

/**
 * Archive an extension via the role-aware DELETE endpoint.
 * Archive-first flow: the DELETE endpoint soft-deletes the row — it moves to
 * the admin-only "Archived Extensions" panel and can be Restored. Permanent
 * deletion is only possible from that panel (forceDestroy). Confirmation uses
 * the shared delete-confirmation modal.
 * Success: queue a success toast and reload, matching the baseline
 * toast-after-reload pattern. Failure (incl. the backend's guard
 * rejection): show an inline danger toast and refresh the table.
 */
function deleteExtension(ext) {
    const statusLabel = ext.status.charAt(0).toUpperCase() + ext.status.slice(1);
    const message =
        `Extension <strong>#${ext.extension_number}</strong> (${statusLabel}) will be moved to the ` +
        `<strong>Archived Extensions</strong> panel.` +
        `<br><br>It can be <strong>restored</strong> at any time from that panel. ` +
        `It will NOT be permanently deleted now — only archived extensions can be permanently deleted.`;

    showDeleteConfirmation(
        `Archive Extension #${ext.extension_number}`,
        message,
        function () { performArchiveExtension(ext); }
    );
}

function performArchiveExtension(ext) {
    console.log(`[performArchiveExtension] Archiving extension #${ext.extension_number}...`);

    const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) {
        showToast('Error: CSRF token not found. Please refresh the page and try again.', 'danger');
        return;
    }

    fetch(buildExtensionsEndpoint(ext.id), {
        method: 'DELETE',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        return response.json().catch(() => ({}))
            .then(body => ({ ok: response.ok, body }));
    })
    .then(({ ok, body }) => {
        if (ok) {
            queueToastAfterReload(`Extension #${ext.extension_number} archived.`, 'success');
            // Persist the current tab so the reload doesn't bounce back to overview.
            sessionStorage.setItem('activeTab', 's-curve-planning');
            setTimeout(() => window.location.reload(), 600);
        } else {
            showToast(body.message || `Could not archive extension #${ext.extension_number}.`, 'danger');
            reloadExtensionsTable();
        }
    })
    .catch(error => {
        console.error('[performArchiveExtension] Error:', error);
        showToast('Could not archive this extension.', 'danger');
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
        const btn = document.getElementById('archivedExtensionsBtn');
        const hasArchived = data.extensions && data.extensions.length > 0;

        // Show the Archived Extensions button only when at least one row exists.
        if (btn) btn.style.display = hasArchived ? '' : 'none';

        if (!hasArchived) {
            // Auto-close the panel when nothing is archived anymore.
            if (panel) panel.style.display = 'none';
            const bulkBtn = document.getElementById('archivedBulkDeleteBtn');
            if (bulkBtn) bulkBtn.style.display = 'none';
            container.innerHTML = '<div style="padding: 20px; text-align: center; color: #94a3b8; font-size: 13px;">No archived extensions.</div>';
            return;
        }

        if (panel) panel.style.display = 'block';

        const bulkBtn = document.getElementById('archivedBulkDeleteBtn');
        const bulkCount = document.getElementById('archivedBulkCount');
        if (bulkBtn) { bulkBtn.style.display = 'none'; }
        if (bulkCount) { bulkCount.textContent = '0'; }

        let rows = data.extensions.map(ext => {
            const fromDate = new Date(ext.original_end_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
            const toDate = new Date(ext.effective_end_date || ext.new_end_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
            return `
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px;">
                    <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                        <input type="checkbox" class="archived-row-checkbox" value="${ext.id}" data-number="${ext.extension_number}" style="cursor: pointer; width: 14px; height: 14px; flex-shrink: 0;">
                        <div style="min-width:0;">
                            <div style="font-size:13px;font-weight:700;color:#334155;">#${ext.extension_number} <span style="font-weight:500;color:#64748b;">· ${fromDate} → ${toDate}</span></div>
                            <div style="font-size:12px;color:#94a3b8;">${ext.reason_category ? ext.reason_category + ' · ' : ''}${(ext.reason || 'N/A').substring(0, 60)}${(ext.reason || '').length > 60 ? '…' : ''}</div>
                        </div>
                    </div>
                    <div style="white-space:nowrap;">
                        <button onclick='restoreArchivedExtension(${JSON.stringify(ext)})' style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:5px 10px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;margin-right:6px;"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                        <button onclick='forceDeleteArchivedExtension(${JSON.stringify(ext)})' style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;padding:5px 10px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;"><i class="bi bi-trash"></i> Delete Forever</button>
                    </div>
                </div>
            `;
        }).join('');

        container.innerHTML = rows;

        // Wire archived-row checkboxes to the bulk delete button.
        wireArchivedSelectionHandlers();
    })
    .catch(error => {
        console.error('[loadArchivedExtensions] Error:', error);
        container.innerHTML = `<div style="padding: 20px; text-align: center; color: #dc2626; font-size: 13px;">Error: ${error.message}</div>`;
    });
};

/**
 * Wire the archived-panel bulk-delete checkboxes (admin only).
 */
function wireArchivedSelectionHandlers() {
    document.querySelectorAll('.archived-row-checkbox').forEach(cb => {
        cb.addEventListener('change', updateArchivedSelection);
    });
    updateArchivedSelection();
}

function updateArchivedSelection() {
    const bulkBtn = document.getElementById('archivedBulkDeleteBtn');
    const countEl = document.getElementById('archivedBulkCount');
    if (!bulkBtn || !countEl) return;

    const count = document.querySelectorAll('.archived-row-checkbox:checked').length;
    countEl.textContent = count;
    bulkBtn.style.display = count > 0 ? '' : 'none';
}

/**
 * Bulk permanent-delete selected archived extensions (admin only).
 */
window.forceDeleteSelectedArchived = function () {
    const selected = Array.from(document.querySelectorAll('.archived-row-checkbox:checked'))
        .map(cb => ({ id: Number(cb.value), number: Number(cb.dataset.number) }));
    if (selected.length === 0) {
        showToast('No archived extensions selected.', 'danger');
        return;
    }

    const labels = selected.map(s => '#' + s.number).join(', ');
    showDeleteConfirmation(
        `Permanently Delete ${selected.length} Extension${selected.length > 1 ? 's' : ''}`,
        `Permanently delete archived extension(s) <strong>${labels}</strong>?` +
        `<br><br>This <strong>removes the records AND their evidence files</strong> from the system. This action cannot be undone.`,
        function () { performBulkForceDeleteArchived(selected); }
    );
};

function performBulkForceDeleteArchived(selected) {
    const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) {
        showToast('Error: CSRF token not found. Please refresh the page and try again.', 'danger');
        return;
    }

    const endpoint = `/admin/projects/${window.projectId}/extensions/bulk-force-delete`;

    fetch(endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ ids: selected.map(s => s.id) })
    })
    .then(response => {
        return response.json().catch(() => ({}))
            .then(body => ({ ok: response.ok, body }));
    })
    .then(({ ok, body }) => {
        if (ok) {
            showToast(body.message || 'Archived extensions permanently deleted.', 'success');
            loadArchivedExtensions();
            reloadExtensionsTable();
        } else {
            showToast(body.message || 'Could not permanently delete the selected extensions.', 'danger');
        }
    })
    .catch(error => {
        console.error('[performBulkForceDeleteArchived] Error:', error);
        showToast('Could not permanently delete the selected extensions.', 'danger');
    });
}

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
 * Only available on already-archived extensions — the archive-first flow
 * ensures nothing is hard-deleted straight from the live table. Uses the
 * shared delete-confirmation modal.
 */
window.forceDeleteArchivedExtension = function (ext) {
    const message =
        `Permanently delete extension <strong>#${ext.extension_number}</strong>?` +
        `<br><br>This <strong>removes the record AND its evidence files</strong> from the system. ` +
        `This action cannot be undone.`;

    showDeleteConfirmation(
        `Permanently Delete Extension #${ext.extension_number}`,
        message,
        function () { performForceDeleteArchivedExtension(ext); }
    );
};

function performForceDeleteArchivedExtension(ext) {
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
        console.error('[performForceDeleteArchivedExtension] Error:', error);
        showToast('Could not permanently delete this extension.', 'danger');
    });
}