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
                        rejected: { bg: '#fee2e2', color: '#991b1b', label: '✕ Rejected' }
                    };
                    const statusStyle = statusStyles[ext.status] || statusStyles.pending;
                    const statusTitle = (ext.status === 'rejected' && ext.rejection_reason)
                        ? ` title="${ext.rejection_reason.replace(/"/g, '&quot;')}"`
                        : '';

                    // Editable for admin always; for staff only while
                    // pending/rejected. Backend is the real enforcement
                    // point regardless — this just controls button visibility.
                    const isAdmin = window.routePrefix === 'admin.projects';
                    const canEdit = isAdmin || ext.status === 'pending' || ext.status === 'rejected';

                    row.innerHTML = `
                        <td style="padding: 12px 15px; font-size: 13px; font-weight: 600; color: #667eea;">#${ext.extension_number}</td>
                        <td style="padding: 12px 15px; font-size: 13px; color: #374151;">${fromDate}</td>
                        <td style="padding: 12px 15px; font-size: 13px; color: #374151; font-weight: 600;">${toDate}</td>
                        <td style="padding: 12px 15px; text-align: center; font-size: 13px; font-weight: 600; color: #f59e0b;">+${daysAdded} days</td>
                        <td style="padding: 12px 15px; font-size: 13px; color: #6b7280;">${ext.reason || 'N/A'}</td>
                        <td style="padding: 12px 15px; text-align: center;">
                            <span${statusTitle} style="background: ${statusStyle.bg}; color: ${statusStyle.color}; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: ${ext.status === 'rejected' && ext.rejection_reason ? 'help' : 'default'};">${statusStyle.label}</span>
                        </td>
                        <td style="padding: 12px 15px; text-align: center;">
                            ${canEdit ? `<button onclick='openEditExtensionModal(${JSON.stringify(ext)})' style="background: #eef2ff; color: #4f46e5; border: 1px solid #c7d2fe; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer;"><i class="bi bi-pencil"></i> Edit</button>` : '<span style="color: #d1d5db; font-size: 11px;">—</span>'}
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