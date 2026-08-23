<!-- PROJECT EXTENSIONS SECTION -->
<div style="margin-top: 30px; padding: 20px; background: white; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);" data-project-id="{{ $project->id }}" data-project-current-deadline="{{ $project->planned_end_date?->format('M d, Y') ?? 'N/A' }}" data-project-original-deadline="{{ $project->original_planned_end_date?->format('M d, Y') ?? 'N/A' }}">
    
    <!-- Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e5e7eb;">
        <div>
            <h4 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 10px;">
                <i class="bi bi-calendar-check" style="color: #f59e0b; font-size: 20px;"></i> Project Extensions
            </h4>
            <p style="margin: 0; font-size: 12px; color: #6b7280;">Track project deadline extensions and view extension history</p>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            @if(auth()->user()->role === 'admin' || (!empty($isAssignedStaff) && $isAssignedStaff))
                <button id="addExtensionBtn" onclick="openRequestExtensionModal()" 
                        style="background: #f59e0b; color: white; border: none; padding: 10px 16px; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;"
                        onmouseover="this.style.background='#d97706'; this.style.transform='translateY(-2px)';"
                        onmouseout="this.style.background='#f59e0b'; this.style.transform='translateY(0)';">
                    <i class="bi bi-plus-circle"></i> Add Extension
                </button>
            @endif
            @if(auth()->user()->role === 'admin' && $project->extensions()->onlyTrashed()->exists())
                <button id="archivedExtensionsBtn" onclick="loadArchivedExtensions()" 
                        style="background: #f8fafc; color: #374151; border: 1px solid #cbd5e1; padding: 10px 14px; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;"
                        onmouseover="this.style.background='#eef2ff'; this.style.color='#4f46e5';"
                        onmouseout="this.style.background='#f8fafc'; this.style.color='#374151';">
                    <i class="bi bi-archive"></i> Archived Extensions
                </button>
            @endif
        </div>
    </div>

    <!-- Deadline Summary -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px;">
        <div style="padding: 15px; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px;">
            <small style="color: #92400e; font-weight: 600; display: block; margin-bottom: 6px;">Original Deadline</small>
            <div style="font-size: 14px; font-weight: 700; color: #78350f;">{{ $project->original_planned_end_date?->format('F d, Y') ?? 'Not Set' }}</div>
        </div>
        <div style="padding: 15px; background: #dbeafe; border: 1px solid #bae6fd; border-radius: 8px;">
            <small style="color: #0369a1; font-weight: 600; display: block; margin-bottom: 6px;">Current Deadline</small>
            @php
                // Get the latest extension's new_end_date, or use the original
                // Only an APPROVED extension should ever count toward the
                // project's real current deadline / total extension days.
                // Pending/rejected extensions also get an extension_number
                // assigned, so without this filter "latest by number" could
                // wrongly pick a non-approved row.
                // NOTE: using reorder() instead of latest()/orderBy() here
                // on purpose. If Project::extensions() has any default
                // ordering baked into the relationship itself (e.g. an
                // ascending orderBy for normal display purposes),
                // chaining ->latest() on top does NOT override it — SQL
                // applies multiple ORDER BY clauses in the sequence they
                // were added, so the relationship's own order silently
                // wins since extension_number values are unique (no ties
                // for the second clause to break). reorder() clears any
                // existing order clauses first, guaranteeing this actually
                // sorts descending instead of just hoping to.
                $latestExtension = $project->extensions()->where('status', 'approved')->reorder('extension_number', 'desc')->first();
                // Decision 7: the deadline reflects the GRANTED date (partial
                // approvals), not the requested date.
                $currentDeadline = $latestExtension?->effectiveEndDate() ?? $project->original_planned_end_date;
            @endphp
            <div style="font-size: 14px; font-weight: 700; color: #0c4a6e;">{{ $currentDeadline?->format('F d, Y') ?? 'Not Set' }}</div>
        </div>
        <div style="padding: 15px; background: #d1fae5; border: 1px solid #a7f3d0; border-radius: 8px;">
            <small style="color: #065f46; font-weight: 600; display: block; margin-bottom: 6px;">Total Extensions</small>
            <div style="font-size: 14px; font-weight: 700; color: #065f46;">{{ $project->extensions()->where('status', 'approved')->count() }}</div>
        </div>
        @php
            $totalExtensionDays = 0;
            if ($project->original_planned_end_date && $currentDeadline) {
                $totalExtensionDays = $project->original_planned_end_date->diffInDays($currentDeadline);
            }
        @endphp
        <div style="padding: 15px; background: #fecaca; border: 1px solid #fca5a5; border-radius: 8px;">
            <small style="color: #7c2d12; font-weight: 600; display: block; margin-bottom: 6px;">Total Extension Days</small>
            <div style="font-size: 14px; font-weight: 700; color: #7c2d12;">{{ $totalExtensionDays }} days</div>
        </div>
    </div>

    <!-- Extensions Table or Empty State -->
    @if(auth()->user()->role === 'admin')
    <!-- Bulk actions bar (§8 bulk ops, admin only) — hidden until rows selected -->
    <div id="extensionsBulkBar" style="display: none; align-items: center; gap: 10px; padding: 10px 14px; margin-bottom: 12px; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px;">
        <span style="font-size: 12px; font-weight: 600; color: #4338ca;">
            <span id="extensionsBulkCount">0</span> selected
        </span>
        <button type="button" onclick="archiveSelectedExtensions()" style="background: #4f46e5; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer;" title="Move selected extensions to the Archived panel (reversible)">
            <i class="bi bi-archive"></i> Archive Selected
        </button>
        <button type="button" onclick="clearExtensionSelection()" style="background: transparent; color: #6366f1; border: none; font-size: 12px; font-weight: 600; cursor: pointer;">Clear</button>
    </div>
    @endif
    <div id="extensionsTableContainer">
        <!-- Will be loaded via AJAX -->
        <div style="text-align: center; padding: 40px; color: #9ca3af;">
            <i class="bi bi-hourglass-split" style="font-size: 32px; display: block; margin-bottom: 12px; opacity: 0.5;"></i>
            <div style="font-size: 13px;">Loading extensions...</div>
        </div>
    </div>
</div>

<!-- Extensions Table Template -->
<template id="extensionsTableTemplate">
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #f3f4f6; border-bottom: 2px solid #d1d5db;">
                @if(auth()->user()->role === 'admin')
                <th style="padding: 12px 15px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; width: 40px;">
                    <input type="checkbox" id="extensionsSelectAll" title="Select all" style="cursor: pointer; width: 14px; height: 14px;">
                </th>
                @endif
                <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Extension #</th>
                <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Requested By</th>
                <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">From Date</th>
                <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">To Date</th>
                <th style="padding: 12px 15px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Days Added</th>
                <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Category</th>
                <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Reason</th>
                <th style="padding: 12px 15px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Status</th>
                <th style="padding: 12px 15px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Approved By</th>
                <th style="padding: 12px 15px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Action</th>
            </tr>
        </thead>
        <tbody id="extensionsTableBody">
            <!-- Rows will be inserted here -->
        </tbody>
    </table>
</template>

<!-- Empty State Template -->
<template id="extensionsEmptyTemplate">
    <div style="padding: 40px; text-align: center; color: #9ca3af; border: 2px dashed #d1d5db; border-radius: 8px;">
        <i class="bi bi-inbox" style="font-size: 40px; display: block; margin-bottom: 12px; opacity: 0.5;"></i>
        <div style="font-size: 14px; font-weight: 500; margin-bottom: 6px;">No extensions recorded yet</div>
        <div style="font-size: 12px; color: #d1d5db;">Click "Add Extension" to extend the project deadline</div>
    </div>
</template>

@if(auth()->user()->role === 'admin')
<!-- Archived Extensions Panel (Decision 12) — admin only. Soft-deleted rows
     surfaced here for Restore or permanent Delete Forever. -->
<div id="archivedExtensionsPanel" style="display: none; margin-top: 20px; padding: 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <h5 style="margin: 0; font-size: 14px; font-weight: 700; color: #334155;">
                <i class="bi bi-archive me-1" style="color: #64748b;"></i> Archived Extensions
            </h5>
            <input type="checkbox" id="archivedSelectAll" title="Select all archived" style="cursor: pointer; width: 14px; height: 14px;">
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" id="archivedBulkRestoreBtn" onclick="restoreSelectedArchived()" style="display: none; background: #16a34a; color: white; border: none; padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer;" title="Restore selected archived extensions">
                <i class="bi bi-arrow-counterclockwise"></i> Restore Selected (<span id="archivedBulkRestoreCount">0</span>)
            </button>
            <button type="button" id="archivedBulkDeleteBtn" onclick="forceDeleteSelectedArchived()" style="display: none; background: #ef4444; color: white; border: none; padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer;" title="Permanently delete selected archived extensions">
                <i class="bi bi-trash"></i> Delete Selected (<span id="archivedBulkCount">0</span>)
            </button>
            <button type="button" onclick="toggleArchivedPanel()" style="background: transparent; border: none; color: #64748b; font-size: 12px; font-weight: 600; cursor: pointer;">
                <i class="bi bi-x-lg"></i> Close
            </button>
        </div>
    </div>
    <div id="archivedExtensionsContainer">
        <div style="text-align: center; padding: 20px; color: #94a3b8; font-size: 13px;">
            <i class="bi bi-hourglass-split" style="display: block; margin-bottom: 8px; font-size: 24px;"></i>
            Loading archived extensions...
        </div>
    </div>
</div>
@endif