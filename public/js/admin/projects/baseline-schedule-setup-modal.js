/**
 * baseline-schedule-setup-modal.js
 * Handles the "Set Up Baseline" modal for initial baseline creation
 * and the "Adjusted Baseline" modal for post-extension redistribution.
 *
 * THREE MODES:
 *   (1) "save-immediately" — default. Used on the project detail page.
 *       "Done" POSTs the entire plan to storeInitialBaselineSetup() and
 *       reloads the page.
 *   (2) "return-data" — used by the create-project modal (B3.1).
 *       "Done" passes the completed months array to a caller-provided
 *       callback (window.__setupModalContext.onDone) and closes without
 *       contacting the server. The caller holds the data in memory until
 *       the project form is submitted.
 *   (3) "adjust-baseline" — used by the Generate Adjusted Baseline modal.
 *       Pre-populates from existing DB months, auto-generates extension
 *       months by stretching the original curve, lets user review/edit,
 *       then bulk-saves via a dedicated endpoint.
 *
 * IMPORTANT DESIGN: months are held in LOCAL BROWSER MEMORY ONLY while the
 * user builds the plan. NOTHING is saved to the database until "Done" is
 * clicked, at which point the ENTIRE plan is either submitted atomically
 * (mode 1), returned to the caller (mode 2), or bulk-saved to the server
 * via the adjust endpoint (mode 3).
 * TRADEOFF: closing the browser or refreshing mid-setup loses progress —
 * there is no server-side draft/resume.
 *
 * Dependencies (set by baseline-schedule.blade.php inline <script>):
 *   window.projectId      — current project ID
 *   window.routePrefix    — 'admin.projects' | 'staff.projects'
 *   window.csrfToken      — CSRF token string
 *
 * Shared utilities from baseline-schedule-utils.js:
 *   buildBaselineScheduleEndpoint()   — role-aware base endpoint
 *   showToast()                       — global toast notification
 *
 * Context (set by caller):
 *   window.__setupModalContext = {
 *     mode: 'return-data',                    // 'save-immediately' | 'return-data' | 'adjust-baseline'
 *     existingMonths: [...],                  // pre-populate on re-open
 *     onDone: function(months) {},            // called on Done
 *     onCancel: function() {}                 // called on Cancel with no data
 *   }
 */

// ─── STATE ────────────────────────────────────────────────────────────────────

/**
 * NEW: local in-memory list of months added so far this session.
 * Each entry: { month_label, planned_date, planned_progress_percent, description }
 * This array is what gets submitted as a whole to storeInitialBaselineSetup().
 */
let setupLocalMonths = [];

/** Tracks the highest planned_progress_percent among months added so far. */
let setupCurrentMaxPercent = 0;

/** Bootstrap Modal instance, created lazily on first open. */
let setupModalInstance = null;

/** NEW: Bootstrap Modal instance for the stacked "Manage Activities" modal, created lazily. */
let setupActivitiesModalInstance = null

/** Chart.js instance for the S-Curve preview inside this modal. Separate from the
 *  progress tab's scurveChart to avoid conflicts when both are on the same page. */
let setupSCurveChart = null;

/** Index in setupLocalMonths currently being edited, or null when adding new. */
var setupEditingIndex = null;

/** True when the modal is in adjust-baseline mode (post-extension redistribution). */
var setupAdjustMode = false;

/** True when the modal is in edit-original mode (admin correcting the locked original plan). */
var setupEditOriginalMode = false;

/** Tracks IDs of months removed by the user in adjust mode, sent as removed_ids. */
var setupRemovedIds = [];

/** The original planned_end_date from the project, used to generate extension months up to. */
var setupProjectEndDate = null;

/** The month_label of the original deadline (last month BEFORE extension months), used for remaining work %. */
var setupOriginalDeadlineLabel = null;

/** Original months from server (before extension generation), used to reset when remaining work % changes. */
var setupOriginalMonthsForAdjust = [];

/** Raw original months from server (before auto-distribution), used by Restore to Original. */
var setupRawOriginalMonths = [];

// ─── DATE BOUNDS (project timeline) ──────────────────────────────────────────

/**
 * Resolve the project timeline bounds for month target dates.
 *
 * The baseline IS the plan, so every target date must fall within the
 * project's planned start/end dates. The bounds are read from:
 *   - __setupModalContext.startDate / .endDate  (create-project / return-data flow)
 *   - #s-curve-planning data attributes          (project detail / save-immediately flow)
 *
 * Returns { min, max } as 'YYYY-MM-DD' strings (both possibly null when the
 * project has no planned dates yet).
 */
function getSetupDateBounds() {
    var ctx = window.__setupModalContext || null;
    var minDate = null;
    var maxDate = null;

    if (ctx && (ctx.startDate || ctx.endDate)) {
        if (ctx.startDate) minDate = ctx.startDate;
        if (ctx.endDate) maxDate = ctx.endDate;
    } else {
        var planning = document.getElementById('s-curve-planning');
        if (planning) {
            if (planning.getAttribute('data-project-start-date')) {
                minDate = planning.getAttribute('data-project-start-date');
            }
            if (planning.getAttribute('data-project-end-date')) {
                maxDate = planning.getAttribute('data-project-end-date');
            }
        }
    }

    return { min: minDate, max: maxDate };
}

// ─── OPEN / CLOSE ─────────────────────────────────────────────────────────────

/**
 * Open the Set Up Baseline modal.
 *
 * Mode 1 (default — save-immediately): starts a FRESH local session.
 * Mode 2 (return-data): pre-populates from __setupModalContext.existingMonths
 * if available, so the user can re-open and continue editing an in-memory
 * baseline that hasn't been saved to the server yet.
 */
function openSetupBaselineModal() {
    // Lazy-create the Bootstrap Modal instance
    if (!setupModalInstance) {
        setupModalInstance = new bootstrap.Modal(
            document.getElementById('setupBaselineModal')
        );
    }

    // Read caller context to determine mode and existing data
    var ctx = window.__setupModalContext || null;
    var existingData = (ctx && ctx.existingMonths) ? ctx.existingMonths : [];

    if (existingData.length > 0) {
        // Pre-existing in-memory months — restore them (used by return-data
        // mode AND by the S-curve import feature in save-immediately mode)
        setupLocalMonths = existingData.slice(); // copy to avoid mutation
        setupCurrentMaxPercent = setupLocalMonths.reduce(function (max, m) {
            return Math.max(max, parseFloat(m.planned_progress_percent) || 0);
        }, 0);
        setupEditingIndex = null;
        resetSetupForm();
        renderSetupMonthList(setupLocalMonths);
        updateSetupProgress(setupLocalMonths);
        updateSetupSCurvePreview();
    } else {
        // Standard fresh session
        setupLocalMonths = [];
        setupCurrentMaxPercent = 0;
        setupEditingIndex = null;
        resetSetupForm();
        renderSetupMonthList(setupLocalMonths);
        updateSetupProgress(setupLocalMonths);
        updateSetupSCurvePreview();
    }

    // Hide remaining work wrapper (only shown in adjust mode)
    var rwWrapper = document.getElementById('setupRemainingWorkWrapper');
    if (rwWrapper) rwWrapper.style.display = 'none';

    updateAutoGenVisibility();

    setupModalInstance.show();
}

/**
 * Open the Set Up Baseline modal in ADJUST-BASELINE mode.
 *
 * Fetches existing months from the server, auto-generates extension months
 * by stretching the original curve pattern, and pre-populates the modal
 * for user review and editing.
 */
function openAdjustBaselineModal() {
    var token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) {
        if (typeof showToast === 'function') {
            showToast('Session expired. Please refresh the page.', 'error');
        }
        return;
    }

    // Fetch existing baseline months from the server
    fetch(buildBaselineScheduleEndpoint() + '/adjust', {
        method: 'GET',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token }
    })
    .then(function (response) {
        if (!response.ok) return response.json().then(function (d) { throw new Error(d.message || 'HTTP ' + response.status); });
        return response.json();
    })
    .then(function (data) {
        if (!data.success) {
            if (typeof showToast === 'function') showToast(data.message || 'Failed to load baseline data.', 'error');
            return;
        }

        // Store project end date for extension month generation
        setupProjectEndDate = data.planned_end_date || null;

        // Populate local months from server data
        setupAdjustMode = true;
        setupEditOriginalMode = false;
        setupRemovedIds = [];
        setupLocalMonths = data.months.map(function (m) {
            return {
                scheduleId:                   m.id,
                month_label:                  m.month_label,
                planned_date:                 m.planned_date,
                planned_progress_percent:     parseFloat(m.planned_progress_percent) || 0,
                original_planned_progress_percent: m.original_planned_progress_percent !== null
                                                  ? parseFloat(m.original_planned_progress_percent)
                                                  : null,
                description:                  m.description || '',
                has_actual_progress:          m.has_actual_progress || false,
                activities:                   (m.activities || []).filter(function (a) { return a.activity_name; }).map(function (a) {
                    return { id: a.id || null, activity_name: a.activity_name, description: a.description || '' };
                }),
                isNew:                        false
            };
        });

        // Save raw original months (before auto-distribution) for Restore to Original
        setupRawOriginalMonths = JSON.parse(JSON.stringify(setupLocalMonths));

        // Auto-generate extension months up to planned_end_date
        generateAutoDistribution();

        // After stretching, find the deadline for additional work %.
        // Scan backwards on the STRETCHED planned_progress_percent values
        // for the last month below 100% — that's the deadline.
        // Extension months (at 100%) are automatically skipped.
        setupOriginalDeadlineLabel = null;
        if (setupLocalMonths.length > 0) {
            for (var di = setupLocalMonths.length - 1; di >= 0; di--) {
                var dv = parseFloat(setupLocalMonths[di].planned_progress_percent) || 0;
                if (dv < 100) {
                    setupOriginalDeadlineLabel = setupLocalMonths[di].month_label;
                    break;
                }
            }
            if (!setupOriginalDeadlineLabel) {
                setupOriginalDeadlineLabel = setupLocalMonths[setupLocalMonths.length - 1].month_label;
            }
        }
        // Store a deep copy of stretched months for resetting when additional work % changes
        setupOriginalMonthsForAdjust = JSON.parse(JSON.stringify(setupLocalMonths));

        setupCurrentMaxPercent = setupLocalMonths.reduce(function (max, m) {
            return Math.max(max, parseFloat(m.planned_progress_percent) || 0);
        }, 0);

        // Open the modal pre-populated
        if (!setupModalInstance) {
            setupModalInstance = new bootstrap.Modal(document.getElementById('setupBaselineModal'));
        }

        // Update modal title and button text for adjust mode
        var titleEl = document.getElementById('setupModalTitle');
        if (titleEl) titleEl.textContent = 'Adjust Baseline Schedule';
        var iconEl = document.getElementById('setupModalTitleIcon');
        if (iconEl) iconEl.className = 'bi bi-arrow-repeat';
        // Change Done button text
        var doneBtn = document.getElementById('setupDoneBtn');
        if (doneBtn) {
            doneBtn.innerHTML = '<i class="bi bi-check-lg" style="margin-right: 6px;"></i>Save Adjusted Baseline';
        }
        // Change warning notice for adjust mode
        var warningEl = document.getElementById('setupWarningNotice');
        if (warningEl) {
            warningEl.innerHTML =
                '<i class="bi bi-info-circle-fill" style="color: #0d9488; font-size: 16px; flex-shrink: 0; margin-top: 0;"></i>' +
                '<div>' +
                '<div style="font-weight: 700; color: #0f766e; font-size: 12px; margin-bottom: 2px;">Adjusting Baseline Schedule</div>' +
                '<div style="color: #065f46; font-size: 11px; line-height: 1.4;">' +
                'This will redistribute the original curve across the extended timeline. Months with recorded progress are <strong>locked</strong> (yellow background). ' +
                'You can edit any unlocked month before saving.</div></div>';
            warningEl.style.background = '#f0fdfa';
            warningEl.style.border = '1px solid #99f6e4';
            warningEl.style.borderLeft = '4px solid #0d9488';
        }

        // Show remaining work wrapper and reset input
        var rwWrapper = document.getElementById('setupRemainingWorkWrapper');
        var rwInput = document.getElementById('setupAdditionalWorkPercent');
        if (rwWrapper) rwWrapper.style.display = 'block';
        if (rwInput) rwInput.value = '';

        setupEditingIndex = null;
        resetSetupForm();
        renderSetupMonthList(setupLocalMonths);
        updateSetupProgress(setupLocalMonths);
        updateSetupSCurvePreview();
        setupModalInstance.show();
    })
    .catch(function (err) {
        if (typeof showToast === 'function') {
            showToast('Error loading baseline: ' + err.message, 'error');
        }
    });
}

/**
 * Open the Set Up Baseline modal in EDIT-ORIGINAL mode (admin only).
 *
 * Pre-populates ONLY the original-baseline months (rows that were part of
 * the locked original plan — original_planned_progress_percent AND
 * original_planned_date both set) plus their activities, so an admin can
 * correct the frozen plan. Extension months are NOT shown here — they are
 * managed via the Generate Adjusted Baseline flow. Months with recorded
 * actual progress are locked.
 */
function openEditOriginalBaselineModal() {
    var token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) {
        if (typeof showToast === 'function') {
            showToast('Session expired. Please refresh the page.', 'error');
        }
        return;
    }

    // Fetch the original baseline months from the server
    fetch(buildBaselineScheduleEndpoint() + '/original', {
        method: 'GET',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token }
    })
    .then(function (response) {
        if (!response.ok) return response.json().then(function (d) { throw new Error(d.message || 'HTTP ' + response.status); });
        return response.json();
    })
    .then(function (data) {
        if (!data.success) {
            if (typeof showToast === 'function') showToast(data.message || 'Failed to load original baseline data.', 'error');
            return;
        }

        // Populate local months from the returned ORIGINAL months only
        setupAdjustMode = false;
        setupEditOriginalMode = true;
        setupRemovedIds = [];
        setupLocalMonths = data.months.map(function (m) {
            return {
                scheduleId:                   m.id,
                month_label:                  m.month_label,
                planned_date:                 m.original_planned_date || m.planned_date,
                planned_progress_percent:     m.original_planned_progress_percent !== null
                                                  ? parseFloat(m.original_planned_progress_percent)
                                                  : (parseFloat(m.planned_progress_percent) || 0),
                original_planned_progress_percent: m.original_planned_progress_percent !== null
                                                  ? parseFloat(m.original_planned_progress_percent)
                                                  : null,
                original_planned_date:        m.original_planned_date || null,
                description:                  m.description || '',
                has_actual_progress:          m.has_actual_progress || false,
                activities:                   (m.activities || []).filter(function (a) { return a.activity_name; }).map(function (a) {
                    return { id: a.id || null, activity_name: a.activity_name, description: a.description || '' };
                }),
                isNew:                        false
            };
        });

        setupCurrentMaxPercent = setupLocalMonths.reduce(function (max, m) {
            return Math.max(max, parseFloat(m.planned_progress_percent) || 0);
        }, 0);

        // Open the modal pre-populated
        if (!setupModalInstance) {
            setupModalInstance = new bootstrap.Modal(document.getElementById('setupBaselineModal'));
        }

        // Update modal title and button text for edit-original mode
        var titleEl = document.getElementById('setupModalTitle');
        if (titleEl) titleEl.textContent = 'Edit Original Baseline';
        var iconEl = document.getElementById('setupModalTitleIcon');
        if (iconEl) iconEl.className = 'bi bi-pencil-square';
        var doneBtn = document.getElementById('setupDoneBtn');
        if (doneBtn) {
            doneBtn.innerHTML = '<i class="bi bi-check-lg" style="margin-right: 6px;"></i>Save Original Baseline';
        }
        var warningEl = document.getElementById('setupWarningNotice');
        if (warningEl) {
            warningEl.innerHTML =
                '<i class="bi bi-info-circle-fill" style="color: #0369a1; font-size: 16px; flex-shrink: 0; margin-top: 0;"></i>' +
                '<div>' +
                '<div style="font-weight: 700; color: #075985; font-size: 12px; margin-bottom: 2px;">Editing the Locked Original Baseline</div>' +
                '<div style="color: #075985; font-size: 11px; line-height: 1.4;">' +
                'This corrects the frozen original plan — the live AND the frozen original values update together. ' +
                'Months with recorded progress are <strong>locked</strong> (yellow background). ' +
                'Extension months are not shown here; manage them with Generate Adjusted Baseline.</div></div>';
            warningEl.style.background = '#f0f9ff';
            warningEl.style.border = '1px solid #bae6fd';
            warningEl.style.borderLeft = '4px solid #0ea5e9';
        }

        setupEditingIndex = null;
        resetSetupForm();
        renderSetupMonthList(setupLocalMonths);
        updateSetupProgress(setupLocalMonths);
        updateSetupSCurvePreview();

        // Hide remaining work wrapper (only shown in adjust mode)
        var rwWrapper = document.getElementById('setupRemainingWorkWrapper');
        if (rwWrapper) rwWrapper.style.display = 'none';

        setupModalInstance.show();
    })
    .catch(function (err) {
        if (typeof showToast === 'function') {
            showToast('Error loading original baseline: ' + err.message, 'error');
        }
    });
}

/**
 * Auto-generate extension months by stretching the original curve pattern.
 *
 * Collects months WITHOUT actual progress (the adjustable range), then
 * interpolates their values proportionally across the combined adjustable
 * + extension timeline. Months WITH actual progress are kept frozen.
 */
function generateAutoDistribution() {
    if (!setupProjectEndDate) return;

    // Find the last existing month's date
    if (setupLocalMonths.length === 0) return;
    var lastMonth = setupLocalMonths[setupLocalMonths.length - 1];
    var lastDate = new Date(lastMonth.planned_date + (lastMonth.planned_date.indexOf('T') === -1 ? 'T00:00:00' : ''));

    // Calculate end date for extension months
    var endDate = new Date(setupProjectEndDate + 'T00:00:00');

    // If last existing month already covers the end date, no extension needed
    if (lastDate >= endDate) return;

    // Generate month labels from the month after last to the end date.
    // Normalize the cursor to the 1st of the next month so day-of-month
    // rollover (e.g. Mar 31 + 1 month -> May 1, skipping April) can't skip
    // an entire month when the last baseline month ends on the 31st.
    var extensionMonths = [];
    var cursor = new Date(lastDate.getFullYear(), lastDate.getMonth() + 1, 1);

    while (cursor <= endDate) {
        var year = cursor.getFullYear();
        var month = String(cursor.getMonth() + 1).padStart(2, '0');
        var label = year + '-' + month;

        // Check if this month already exists
        var exists = setupLocalMonths.some(function (m) { return m.month_label === label; });
        if (!exists) {
            // Use the 15th of the month as default planned_date
            var day = Math.min(15, new Date(year, cursor.getMonth() + 1, 0).getDate());
            var dateStr = year + '-' + month + '-' + String(day).padStart(2, '0');

            extensionMonths.push({
                scheduleId:                   null,
                month_label:                  label,
                planned_date:                 dateStr,
                planned_progress_percent:     0,
                original_planned_progress_percent: null,
                description:                  '',
                has_actual_progress:          false,
                activities:                   [],
                isNew:                        true
            });
        }

        cursor.setMonth(cursor.getMonth() + 1);
    }

    if (extensionMonths.length === 0) return;

    // Collect adjustable months (no actual progress) — these are the ones we can redistribute
    var adjustableMonths = setupLocalMonths.filter(function (m) { return !m.has_actual_progress; });
    var totalAdjustable = adjustableMonths.length + extensionMonths.length;

    if (adjustableMonths.length === 0) {
        // No adjustable months — just append extension months with linear interpolation
        var lastPercent = setupLocalMonths[setupLocalMonths.length - 1]
            ? parseFloat(setupLocalMonths[setupLocalMonths.length - 1].planned_progress_percent) || 0
            : 0;
        var step = (100 - lastPercent) / extensionMonths.length;

        extensionMonths.forEach(function (m, idx) {
            m.planned_progress_percent = Math.round((lastPercent + (step * (idx + 1))) * 100) / 100;
        });

        setupLocalMonths = setupLocalMonths.concat(extensionMonths);
        return;
    }

    // Find the last locked month's value to anchor the interpolation start.
    // This creates a smooth transition from the last known actual progress
    // rather than starting at the first adjustable month's original value.
    var lastLockedValue = null;
    for (var li = setupLocalMonths.length - 1; li >= 0; li--) {
        if (setupLocalMonths[li].has_actual_progress) {
            lastLockedValue = parseFloat(setupLocalMonths[li].planned_progress_percent);
            break;
        }
    }

    // Build the reference curve:
    //   — prepend the last locked value (if found) so interpolation starts there
    //   — then all adjustable months' original values
    var origValues = adjustableMonths.map(function (m) {
        return parseFloat(m.planned_progress_percent) || 0;
    });
    var hasLockedAnchor = lastLockedValue !== null && lastLockedValue !== undefined
        && lastLockedValue < (origValues[0] || 100);
    if (hasLockedAnchor) {
        origValues = [lastLockedValue].concat(origValues);
    }

    var redistributed = [];

    for (var pos = 0; pos < totalAdjustable; pos++) {
        // Map new position to a fractional position in the reference curve.
        // When anchored to a locked value, use (pos+1)/(totalAdjustable+1) so
        // the first adjustable position lands strictly above the locked value.
        // Otherwise use the standard pos/(totalAdjustable-1).
        var f;
        if (hasLockedAnchor) {
            f = (pos + 1) / (totalAdjustable + 1);
        } else {
            f = (totalAdjustable > 1) ? pos / (totalAdjustable - 1) : 0;
        }
        var origFraction = (origValues.length > 1)
            ? f * (origValues.length - 1)
            : 0;

        var lowerIdx = Math.min(Math.floor(origFraction), origValues.length - 1);
        var upperIdx = Math.min(Math.ceil(origFraction), origValues.length - 1);
        var frac     = origFraction - lowerIdx;

        var value;
        if (lowerIdx === upperIdx) {
            value = origValues[lowerIdx];
        } else {
            value = origValues[lowerIdx] + frac * (origValues[upperIdx] - origValues[lowerIdx]);
        }
        value = Math.round(value * 100) / 100;

        if (pos < adjustableMonths.length) {
            var existingAdj = adjustableMonths[pos];
            existingAdj.planned_progress_percent = value;
            redistributed.push(existingAdj);
        } else {
            var newMonth = extensionMonths[pos - adjustableMonths.length];
            newMonth.planned_progress_percent = value;
            redistributed.push(newMonth);
        }
    }

    // Ensure the final month reaches 100%
    if (redistributed.length > 0) {
        redistributed[redistributed.length - 1].planned_progress_percent = 100;
    }

    // Rebuild setupLocalMonths: locked months (with progress) + redistributed adjustable months
    var lockedMonths = setupLocalMonths.filter(function (m) { return m.has_actual_progress; });
    setupLocalMonths = lockedMonths.concat(redistributed);

    // Sort by date
    setupLocalMonths.sort(function (a, b) {
        return a.month_label.localeCompare(b.month_label);
    });
}

/**
 * Called when the Additional Work % input changes.
 * Resets months to original stretched values, then applies the additional work
 * adjustment to the original deadline month and redistributes extension months.
 *
 * The input value represents the % of work done in the EXTENSION period.
 * So the original deadline month becomes (100 - additionalWork)%.
 * Example: additional work = 5% → deadline month = 95% → extension reaches 100%.
 *
 * Non-locked months between locked months and the deadline are also scaled
 * proportionally to maintain the S-curve pattern.
 */
function onAdditionalWorkChange() {
    if (!setupAdjustMode || !setupOriginalDeadlineLabel) return;

    // Reset to original months (deep copy)
    setupLocalMonths = JSON.parse(JSON.stringify(setupOriginalMonthsForAdjust));

    // Re-run the base stretching algorithm
    generateAutoDistribution();

    // Read additional work %
    var rwInput = document.getElementById('setupAdditionalWorkPercent');
    var additionalWork = parseFloat(rwInput ? rwInput.value : 0) || 0;
    if (additionalWork <= 0) {
        // No additional work — just update UI with base stretched values
        setupCurrentMaxPercent = setupLocalMonths.reduce(function (max, m) {
            return Math.max(max, parseFloat(m.planned_progress_percent) || 0);
        }, 0);
        renderSetupMonthList(setupLocalMonths);
        updateSetupProgress(setupLocalMonths);
        updateSetupSCurvePreview();
        return;
    }

    // Clamp additional work to max 100%
    if (additionalWork > 100) additionalWork = 100;

    // Target value at the original deadline = 100% - additional work
    var targetAtDeadline = Math.round((100 - additionalWork) * 100) / 100;

    // Find the original deadline month
    var deadlineIdx = -1;
    for (var i = 0; i < setupLocalMonths.length; i++) {
        if (setupLocalMonths[i].month_label === setupOriginalDeadlineLabel) {
            deadlineIdx = i;
            break;
        }
    }
    if (deadlineIdx === -1) return;

    // Get the stretched deadline value (before additional work adjustment)
    var stretchedDeadlineValue = parseFloat(setupLocalMonths[deadlineIdx].planned_progress_percent) || 0;

    // Scale all non-locked months proportionally to reach the new target
    // This preserves the S-curve pattern while adjusting to the new deadline
    if (stretchedDeadlineValue > 0) {
        var scaleFactor = targetAtDeadline / stretchedDeadlineValue;
        for (var si = 0; si < setupLocalMonths.length; si++) {
            var m = setupLocalMonths[si];
            if (!m.has_actual_progress) {
                // Don't scale extension months — they get redistributed separately
                if (m.month_label > setupOriginalDeadlineLabel) continue;
                m.planned_progress_percent = Math.round(m.planned_progress_percent * scaleFactor * 100) / 100;
            }
        }
    }

    // Set the deadline month to the target value directly
    setupLocalMonths[deadlineIdx].planned_progress_percent = targetAtDeadline;

    // Find extension months (months after the original deadline)
    var extMonths = [];
    for (var j = deadlineIdx + 1; j < setupLocalMonths.length; j++) {
        if (!setupLocalMonths[j].has_actual_progress) {
            extMonths.push(setupLocalMonths[j]);
        }
    }

    // Redistribute extension months evenly from the target to 100%
    if (extMonths.length > 0) {
        var step = (100 - targetAtDeadline) / extMonths.length;
        for (var k = 0; k < extMonths.length; k++) {
            extMonths[k].planned_progress_percent = Math.round((targetAtDeadline + step * (k + 1)) * 100) / 100;
        }
    }

    // Update UI
    setupCurrentMaxPercent = setupLocalMonths.reduce(function (max, m) {
        return Math.max(max, parseFloat(m.planned_progress_percent) || 0);
    }, 0);
    renderSetupMonthList(setupLocalMonths);
    updateSetupProgress(setupLocalMonths);
    updateSetupSCurvePreview();
}

/**
 * Re-run the auto-adjust baseline distribution without closing/reopening the modal.
 * Resets to the original months, re-runs auto-distribution, and reapplies the
 * current additional work % if any.
 */
function rerunAutoAdjust() {
    if (!setupAdjustMode) return;

    showWarningConfirm(
        'Re-run Auto Adjust',
        '<p>This will <strong>regenerate the adjusted baseline</strong> using the current Additional Work %.</p>' +
        '<p style="margin-top: 6px; color: #6b7280; font-size: 13px;">Any manual edits to month targets will be overwritten.</p>',
        function () {
            // Reset to original months (deep copy)
            setupLocalMonths = JSON.parse(JSON.stringify(setupOriginalMonthsForAdjust));

            // Re-run the base stretching algorithm
            generateAutoDistribution();

            // Read additional work %
            var rwInput = document.getElementById('setupAdditionalWorkPercent');
            var additionalWork = parseFloat(rwInput ? rwInput.value : 0) || 0;

            if (additionalWork > 0) {
                // Re-apply additional work % — mirrors onAdditionalWorkChange()
                var clamped = Math.min(additionalWork, 100);
                var targetAtDeadline = Math.round((100 - clamped) * 100) / 100;

                var deadlineIdx = -1;
                for (var i = 0; i < setupLocalMonths.length; i++) {
                    if (setupLocalMonths[i].month_label === setupOriginalDeadlineLabel) {
                        deadlineIdx = i;
                        break;
                    }
                }

                if (deadlineIdx !== -1) {
                    // Adjust the deadline month
                    var origDeadline = parseFloat(setupOriginalMonthsForAdjust[deadlineIdx].planned_progress_percent) || 0;
                    setupLocalMonths[deadlineIdx].planned_progress_percent = Math.min(origDeadline, targetAtDeadline);

                    // Scale extension months (after deadline)
                    var extMonths = setupLocalMonths.slice(deadlineIdx + 1);
                    if (extMonths.length > 0 && origDeadline > targetAtDeadline) {
                        var step = (origDeadline - targetAtDeadline) / extMonths.length;
                        for (var k = 0; k < extMonths.length; k++) {
                            extMonths[k].planned_progress_percent = Math.round((targetAtDeadline + step * (k + 1)) * 100) / 100;
                        }
                    }
                }
            }

            setupEditingIndex = null;
            renderSetupMonthList(setupLocalMonths);
            updateSetupProgress(setupLocalMonths);
            updateSetupSCurvePreview();
            resetSetupForm();
            hideSetupErrors();
        }
    );
}

/**
 * Called when the user clicks "Cancel / Finish Later" or the X button.
 *
 * In 'return-data' mode (B3.1 — create-project flow):
 *   Calls the caller's onCancel callback if provided, then closes.
 *   If months exist, confirms before discarding (same as default mode).
 *
 * In 'save-immediately' mode (default — project detail page):
 *   No months → closes immediately. Has months → shows confirmation.
 */
function onSetupBaselineCancel() {
    var ctx = window.__setupModalContext || null;

    // No months added — just close (both modes)
    if (setupLocalMonths.length === 0) {
        if (ctx && ctx.mode === 'return-data' && typeof ctx.onCancel === 'function') {
            ctx.onCancel();
        }
        if (setupModalInstance) setupModalInstance.hide();
        return;
    }

    // Has months — confirm before discarding
    var count = setupLocalMonths.length;
    var title = setupEditOriginalMode
        ? 'Discard Original Baseline Edits?'
        : (setupAdjustMode ? 'Discard Adjusted Baseline?' : 'Discard Unsaved Baseline?');
    var msg = setupEditOriginalMode
        ? 'You have <strong>' + count + '</strong> original month target' + (count > 1 ? 's' : '') + ' with unsaved edits. Changes will be lost if you continue.'
        : (setupAdjustMode
            ? 'You have <strong>' + count + '</strong> month target' + (count > 1 ? 's' : '') + ' in the adjusted plan. Changes will be lost if you continue.'
            : 'You have added <strong>' + count + '</strong> month target' + (count > 1 ? 's' : '') + ' that ' + (count > 1 ? 'have' : 'has') + ' not been saved yet. If you continue, this data will be lost.');
    showWarningConfirm(
        title,
        msg,
        function () {
            setupLocalMonths = [];
            setupCurrentMaxPercent = 0;
            setupEditingIndex = null;
            setupAdjustMode = false;
            setupEditOriginalMode = false;
            setupRemovedIds = [];
            setupProjectEndDate = null;
            setupOriginalDeadlineLabel = null;
            setupOriginalMonthsForAdjust = [];
            // Hide remaining work wrapper
            var rwWrapper = document.getElementById('setupRemainingWorkWrapper');
            if (rwWrapper) rwWrapper.style.display = 'none';
            resetModalUIToDefaults();
            if (ctx && ctx.mode === 'return-data' && typeof ctx.onCancel === 'function') {
                ctx.onCancel();
            }
            if (setupModalInstance) setupModalInstance.hide();
        }
    );
}

/**
 * Build the bulk-save payload (months + removed_ids) shared by the
 * adjust-baseline and edit-original modes.
 */
function buildSetupUpsertPayload() {
    return {
        months: setupLocalMonths.map(function (m) {
            return {
                id:                           m.scheduleId || null,
                month_label:                  m.month_label,
                planned_date:                 m.planned_date,
                planned_progress_percent:     m.planned_progress_percent,
                description:                  m.description || '',
                activities:                   (m.activities || []).filter(function (a) { return a.activity_name; }).map(function (a) {
                    return {
                        id:             a.id || null,
                        activity_name:  a.activity_name,
                        description:    a.description || ''
                    };
                })
            };
        }),
        removed_ids: setupRemovedIds
    };
}

/**
 * Called when the user clicks "Done — Lock Baseline" (or "Save" in return-data mode).
 *
 * In 'return-data' mode (B3.1 — create-project flow):
 *   Passes the completed months array to the caller's callback and closes
 *   the modal. No server call, no page reload.
 *
 * In 'save-immediately' mode (default — project detail page):
 *   POSTs the entire local months array to storeInitialBaselineSetup() in
 *   one atomic request, then reloads the page.
 */
function onSetupBaselineDone() {
    if (setupCurrentMaxPercent < 100) {
        // Guard: should not be reachable (button is disabled), but belt-and-suspenders.
        return;
    }

    // ── Check if this is a return-data call (create-project flow) ──────────
    var ctx = window.__setupModalContext || null;
    if (ctx && ctx.mode === 'return-data') {
        // Caller provided callback — return the completed months array
        if (typeof ctx.onDone === 'function') {
            ctx.onDone(setupLocalMonths.slice()); // pass a copy
        }
        // Clean up context (prevents stale state on re-open)
        window.__setupModalContext = null;
        if (setupModalInstance) setupModalInstance.hide();
        return; // no server call, no page reload
    }

    // ── Warning check: first/last month vs project start/end month ────────
    var warnings = getBaselineMonthWarnings();
    if (warnings.length > 0) {
        showWarningConfirm(
            'Baseline Month Mismatch',
            '<p style="margin-bottom: 8px; color: #92400e;">The following issues were detected:</p>' +
            '<ul style="margin: 0; padding-left: 18px; color: #78350f;">' +
            warnings.map(function(w) { return '<li style="margin-bottom: 4px;">' + w + '</li>'; }).join('') +
            '</ul>' +
            '<p style="margin-top: 10px; font-weight: 600; color: #92400e;">Do you want to proceed anyway?</p>',
            function() {
                // User confirmed — proceed with the actual save
                performBaselineSave();
            }
        );
        return; // pause until user responds
    }

    // No warnings — proceed directly
    performBaselineSave();
}

/**
 * Check if first/last monthly target month matches project start/end month.
 * @returns {string[]} Array of warning messages (empty if all match)
 */
function getBaselineMonthWarnings() {
    var warnings = [];
    var bounds = getSetupDateBounds();

    if (setupLocalMonths.length === 0) return warnings;

    // Check first month vs project start month
    if (bounds.min) {
        var startMonth = bounds.min.substring(0, 7); // YYYY-MM
        if (setupLocalMonths[0].month_label !== startMonth) {
            warnings.push(
                'First monthly target (' + formatMonthLabel(setupLocalMonths[0].month_label) +
                ') does not match the project\'s planned start month (' + formatMonthLabel(startMonth) + ').'
            );
        }
    }

    // Check last month vs project end month
    if (bounds.max) {
        var endMonth = bounds.max.substring(0, 7); // YYYY-MM
        var lastLabel = setupLocalMonths[setupLocalMonths.length - 1].month_label;
        if (lastLabel !== endMonth) {
            warnings.push(
                'Last monthly target (' + formatMonthLabel(lastLabel) +
                ') does not match the project\'s planned end month (' + formatMonthLabel(endMonth) + ').'
            );
        }
    }

    return warnings;
}

/**
 * Perform the actual save after warning check passes (or no warnings).
 * Handles both adjust-baseline and default save-immediately modes.
 */
function performBaselineSave() {
    // ── Adjust-baseline / edit-original modes: POST to dedicated endpoint ──
    if (setupAdjustMode || setupEditOriginalMode) {
        var token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
        var doneBtn = document.getElementById('setupDoneBtn');
        var doneLabel = setupEditOriginalMode ? 'Save Original Baseline' : 'Save Adjusted Baseline';
        var endpoint = setupEditOriginalMode
            ? buildBaselineScheduleEndpoint() + '/original/save'
            : buildBaselineScheduleEndpoint() + '/adjust/save';
        doneBtn.disabled = true;
        doneBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

        // Build payload: months + removed_ids
        var payload = buildSetupUpsertPayload();

        // Dismiss modal and show full-screen preloader during save
        try { if (setupModalInstance) setupModalInstance.hide(); } catch (e) {}
        if (window.ProjTrackPreloader && window.ProjTrackPreloader.show) {
            window.ProjTrackPreloader.show('Saving baseline schedule&hellip;');
        }

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(function (response) {
            if (!response.ok) {
                return response.json().then(function (d) {
                    throw new Error(d.message || 'HTTP ' + response.status);
                });
            }
            return response.json();
        })
        .then(function (data) {
            if (data.success) {
                if (typeof queueToastAfterReload === 'function') {
                    queueToastAfterReload(data.message || 'Baseline updated successfully!', 'success');
                }
                setupAdjustMode = false;
                setupEditOriginalMode = false;
                setupRemovedIds = [];
                setupProjectEndDate = null;
                if (setupModalInstance) setupModalInstance.hide();
                sessionStorage.setItem('activeTab', 's-curve-planning');
                setTimeout(function () { location.reload(); }, 600);
            } else {
                if (window.ProjTrackPreloader && window.ProjTrackPreloader.hide) window.ProjTrackPreloader.hide();
                showSetupErrors([data.message || 'Failed to save baseline.']);
                doneBtn.disabled = false;
                doneBtn.innerHTML = '<i class="bi bi-check-lg" style="margin-right: 6px;"></i>' + doneLabel;
            }
        })
        .catch(function (err) {
            if (window.ProjTrackPreloader && window.ProjTrackPreloader.hide) window.ProjTrackPreloader.hide();
            showSetupErrors(['Error saving: ' + err.message]);
            doneBtn.disabled = false;
            doneBtn.innerHTML = '<i class="bi bi-check-lg" style="margin-right: 6px;"></i>' + doneLabel;
        });

        return;
    }

    // ── Default mode: save-immediately — POST to server ───────────────────
    var token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    var doneBtn = document.getElementById('setupDoneBtn');

    // Disable button while saving to prevent double-submit
    doneBtn.disabled = true;
    doneBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

    // Dismiss modal and show full-screen preloader during save
    try { if (setupModalInstance) setupModalInstance.hide(); } catch (e) {}
    if (window.ProjTrackPreloader && window.ProjTrackPreloader.show) {
        window.ProjTrackPreloader.show('Saving baseline schedule&hellip;');
    }

    // Submit the whole local array to the atomic bulk-create endpoint
    fetch(buildBaselineScheduleEndpoint() + '/setup', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'Accept': 'application/json',
        },
        body: JSON.stringify({ months: setupLocalMonths }),
    })
    .then(function (response) {
        if (!response.ok) {
            return response.json().then(function (data) {
                throw new Error(data.message || 'HTTP ' + response.status);
            });
        }
        return response.json();
    })
    .then(function (data) {
        if (data.success) {
            if (typeof queueToastAfterReload === 'function') {
                queueToastAfterReload('Baseline schedule locked successfully!', 'success');
            }
            if (setupModalInstance) {
                setupModalInstance.hide();
            }
            // Remember which tab was active so the post-reload page reopens
            // on the Baseline tab instead of falling back to the default Overview.
            sessionStorage.setItem('activeTab', 's-curve-planning');
            // Reload page so the tab re-renders with the new frozen baseline
            setTimeout(function () { location.reload(); }, 600);
        } else {
            if (window.ProjTrackPreloader && window.ProjTrackPreloader.hide) window.ProjTrackPreloader.hide();
            showSetupErrors([data.message || 'Failed to save baseline. Please try again.']);
            doneBtn.disabled = false;
            doneBtn.innerHTML = '<i class="bi bi-check-lg" style="margin-right: 6px;"></i>Done \u2014 Lock Baseline';
        }
    })
    .catch(function (err) {
        if (window.ProjTrackPreloader && window.ProjTrackPreloader.hide) window.ProjTrackPreloader.hide();
        showSetupErrors(['Error saving baseline: ' + err.message]);
        doneBtn.disabled = false;
        doneBtn.innerHTML = '<i class="bi bi-check-lg" style="margin-right: 6px;"></i>Done \u2014 Lock Baseline';
    });
}

// ─── ADD A MONTH (LOCAL ONLY — NO SERVER CALL) ─────────────────────────────────

/**
 * Validate inputs and push a month (with any activities) into the LOCAL
 * array only. No network request happens here — nothing is sent to the
 * server until "Done — Lock Baseline" is clicked.
 */
function addSetupBaselineMonth() {
    // Clear any previous validation errors
    hideSetupErrors();

    // ── Collect field values ──────────────────────────────────────────────────
    const monthLabel      = document.getElementById('setupMonthLabel').value.trim();
    const plannedDate     = document.getElementById('setupPlannedDate').value.trim();
    const progressPercent = parseFloat(document.getElementById('setupProgressPercent').value);
    const description     = document.getElementById('setupDescription').value.trim();

    // ── Collect activities from the activities container ──────────────────────
    // Only rows with at least an activity name are included; empty rows ignored.
    const activities = [];
    const activityRows = document.querySelectorAll(
        '#setupActivitiesContainer > div:not(#setupActivitiesPlaceholder)'
    );
    activityRows.forEach(row => {
        const nameInput = row.querySelector('.setup-activity-name');
        const descInput = row.querySelector('.setup-activity-desc');
        const idInput   = row.querySelector('.setup-activity-id');
        const name      = nameInput ? nameInput.value.trim() : '';
        if (name) {
            const origId = idInput && idInput.value ? parseInt(idInput.value, 10) : NaN;
            activities.push({
                id:             Number.isInteger(origId) ? origId : null,
                activity_name:  name,
                description:    descInput ? descInput.value.trim() : '',
            });
        }
    });

    // ── Client-side validation ────────────────────────────────────────────────
    const errors = [];

    if (!monthLabel) {
        errors.push('Month is required.');
    }
    if (!plannedDate) {
        errors.push('Target date is required.');
    }
    if (isNaN(progressPercent) || progressPercent < 0 || progressPercent > 100) {
        errors.push('Progress % must be a number between 0 and 100.');
    }

    // Target date must fall within the selected month
    if (monthLabel && plannedDate) {
        var dateMonth = plannedDate.substring(0, 7);
        if (dateMonth !== monthLabel) {
            errors.push('Target date must fall within ' + formatMonthLabel(monthLabel) + '.');
        }
    }

    // Rule BOUNDS: target date must fall within the project timeline.
    // The baseline IS the plan, so a target may not precede the planned start
    // date nor extend past the CURRENT (post-extension) planned end date.
    if (plannedDate) {
        var bounds = getSetupDateBounds();
        if (bounds.min && plannedDate < bounds.min) {
            errors.push('Target date must not be before the project\'s planned start date (' + bounds.min + ').');
        }
        if (bounds.max && plannedDate > bounds.max) {
            errors.push('Target date must not be after the project\'s planned end date (' + bounds.max + ').');
        }
    }

    // Reject duplicate month within the local plan (skip when editing same month)
    if (monthLabel && !(setupEditingIndex !== null && setupLocalMonths[setupEditingIndex] && setupLocalMonths[setupEditingIndex].month_label === monthLabel)) {
        if (setupLocalMonths.some(m => m.month_label === monthLabel)) {
            errors.push('"' + formatMonthLabel(monthLabel) + '" has already been added to this plan.');
        }
    }

    if (setupEditingIndex !== null) {
        // ── EDIT MODE: validate against neighbors ──────────────────────────────
        var prevMonth = setupEditingIndex > 0 ? setupLocalMonths[setupEditingIndex - 1] : null;
        var nextMonth = setupEditingIndex < setupLocalMonths.length - 1 ? setupLocalMonths[setupEditingIndex + 1] : null;

        if (!isNaN(progressPercent)) {
            if (prevMonth && progressPercent <= parseFloat(prevMonth.planned_progress_percent)) {
                errors.push(
                    'Progress % must be higher than the previous month (' +
                    parseFloat(prevMonth.planned_progress_percent).toFixed(0) + '%).'
                );
            }
            if (nextMonth && progressPercent >= parseFloat(nextMonth.planned_progress_percent)) {
                errors.push(
                    'Progress % must be lower than the next month (' +
                    parseFloat(nextMonth.planned_progress_percent).toFixed(0) + '%).'
                );
            }
        }
    } else {
        // ── NEW ENTRY MODE: gap-aware validation ──────────────────────────────
        // The month picker is constrained to the first missing month by resetSetupForm().
        // Validate that the selected month falls within the project timeline.
        if (monthLabel) {
            var bounds = getSetupDateBounds();
            if (bounds.min) {
                var startMonth = bounds.min.substring(0, 7);
                if (monthLabel < startMonth) {
                    errors.push('"' + formatMonthLabel(monthLabel) + '" is before the project start month (' + formatMonthLabel(startMonth) + ').');
                }
            }
            if (bounds.max) {
                var endMo = bounds.max.substring(0, 7);
                if (monthLabel > endMo) {
                    errors.push('"' + formatMonthLabel(monthLabel) + '" is after the project end month (' + formatMonthLabel(endMo) + ').');
                }
            }
        }

        // Progress must fall between the previous and next existing months' progress
        if (monthLabel && !isNaN(progressPercent) && setupLocalMonths.length > 0) {
            var sortedMonths = setupLocalMonths.slice().sort(function(a, b) {
                return a.month_label.localeCompare(b.month_label);
            });
            // Find the neighbors for this month
            var prevProg = 0;
            var nextProg = 100;
            for (var i = 0; i < sortedMonths.length; i++) {
                if (sortedMonths[i].month_label < monthLabel) {
                    prevProg = parseFloat(sortedMonths[i].planned_progress_percent);
                }
                if (sortedMonths[i].month_label > monthLabel && nextProg === 100) {
                    nextProg = parseFloat(sortedMonths[i].planned_progress_percent);
                    break;
                }
            }
            if (progressPercent <= prevProg) {
                errors.push(
                    'Progress % must be higher than the previous month (' + prevProg.toFixed(0) + '%).'
                );
            }
            if (progressPercent >= nextProg) {
                errors.push(
                    'Progress % must be lower than the next month (' + nextProg.toFixed(0) + '%).'
                );
            }
        }
    }

    if (errors.length > 0) {
        showSetupErrors(errors);
        return;
    }

    // ── Persist to local array (no server call) ──────────────────────────────
    var entry = {
        month_label:              monthLabel,
        planned_date:             plannedDate,
        planned_progress_percent: progressPercent,
        description:              description || null,
        activities:               activities,       // [] if none were added
    };

    if (setupEditingIndex !== null) {
        // Update existing entry in-place — preserve fields that are NOT
        // part of the form but are critical for the save payload
        // (scheduleId so the server knows to UPDATE, not CREATE).
        var existing = setupLocalMonths[setupEditingIndex];
        entry.scheduleId                   = existing.scheduleId;
        entry.original_planned_progress_percent = existing.original_planned_progress_percent;
        entry.has_actual_progress          = existing.has_actual_progress;
        entry.isNew                        = existing.isNew;
        setupLocalMonths[setupEditingIndex] = entry;
        setupEditingIndex = null;
    } else {
        // Insert at correct chronological position by month_label
        var insertIdx = setupLocalMonths.length;
        for (var i = 0; i < setupLocalMonths.length; i++) {
            if (setupLocalMonths[i].month_label > monthLabel) {
                insertIdx = i;
                break;
            }
        }
        setupLocalMonths.splice(insertIdx, 0, entry);
    }

    // Reset form (including activities container) for the next month entry
    resetSetupForm();

    // Re-render from local state — instant, no network round-trip
    renderSetupMonthList(setupLocalMonths);
    updateSetupProgress(setupLocalMonths);

    // Update S-Curve preview chart
    updateSetupSCurvePreview();
}

/**
 * NEW: Remove a month from the local plan before final submission.
 * Needed because months no longer save independently — a mistaken entry
 * must be removable before "Done" is clicked.
 * @param {number} index — position in setupLocalMonths to remove
 */
function removeSetupBaselineMonth(index) {
    // In adjust / edit-original modes, track removed DB records for server deletion
    if (setupAdjustMode || setupEditOriginalMode) {
        var removedMonth = setupLocalMonths[index];
        if (removedMonth && removedMonth.scheduleId) {
            if (setupRemovedIds.indexOf(removedMonth.scheduleId) === -1) {
                setupRemovedIds.push(removedMonth.scheduleId);
            }
        }
    }

    // If the month being edited is removed, exit edit mode
    if (setupEditingIndex !== null) {
        if (index === setupEditingIndex) {
            // The edited month was removed — cancel edit entirely
            setupEditingIndex = null;
        } else if (index < setupEditingIndex) {
            // A month before the edited one was removed — shift index down
            setupEditingIndex--;
        }
    }
    setupLocalMonths.splice(index, 1);
    renderSetupMonthList(setupLocalMonths);
    updateSetupProgress(setupLocalMonths);

    // Re-enter edit mode if editing index is still valid
    if (setupEditingIndex !== null && setupEditingIndex < setupLocalMonths.length) {
        // Re-populate form from the month that shifted into this index
        editSetupBaselineMonth(setupEditingIndex);
    } else if (setupEditingIndex !== null) {
        // Edited month was removed or index now out of range — clean up
        setupEditingIndex = null;
        cancelEdit();
    } else {
        // Not in edit mode — re-run gap-aware reset so the Add Month button
        // re-enables if there are still missing months in the timeline
        resetSetupForm();
    }

    // Update S-Curve preview chart
    updateSetupSCurvePreview();
}

/**
 * Clear all monthly targets from the setup modal after confirmation.
 * Shows a warning-confirm modal; on confirm, empties setupLocalMonths,
 * re-renders the list, resets the form, and hides the Clear All button.
 */
function clearAllSetupMonths() {
    if (setupLocalMonths.length === 0) return;

    showWarningConfirm(
        'Clear All Monthly Targets',
        '<p>This will <strong>remove all ' + setupLocalMonths.length + ' monthly target(s)</strong> from the baseline plan.</p>' +
        '<p style="margin-top:8px; color:#6b7280; font-size:13px;">You will need to re-add them manually or use Auto-Generate.</p>',
        function () {
            // Track removed DB records for deletion on save (adjust / edit-original modes)
            if (setupAdjustMode || setupEditOriginalMode) {
                setupLocalMonths.forEach(function (m) {
                    if (m.scheduleId && setupRemovedIds.indexOf(m.scheduleId) === -1) {
                        setupRemovedIds.push(m.scheduleId);
                    }
                });
            }

            setupLocalMonths = [];
            setupEditingIndex = null;
            renderSetupMonthList(setupLocalMonths);
            updateSetupProgress(setupLocalMonths);
            updateSetupSCurvePreview();
            resetSetupForm();
            hideSetupErrors();
        }
    );
}

// ─── EDIT A MONTH ──────────────────────────────────────────────────────────────

/**
 * Populate the form with an existing month's data for editing.
 * Called when the user clicks the edit (pencil) icon on a month row.
 * @param {number} index — position in setupLocalMonths to edit
 */
function editSetupBaselineMonth(index) {
    var month = setupLocalMonths[index];
    if (!month) return;

    setupEditingIndex = index;

    // Populate form fields with existing data
    var monthInput = document.getElementById('setupMonthLabel');
    var dateInput = document.getElementById('setupPlannedDate');
    monthInput.value = month.month_label;
    dateInput.value = month.planned_date;
    document.getElementById('setupProgressPercent').value = month.planned_progress_percent;
    document.getElementById('setupDescription').value = month.description || '';

    // Lock the month input — user cannot change which month they're editing
    monthInput.setAttribute('readonly', true);

    // Constrain planned_date to this month's date range, intersected with the
    // project timeline (start .. current end) so edit mode stays within bounds.
    var parts = month.month_label.split('-');
    var year = parseInt(parts[0], 10);
    var mon = parseInt(parts[1], 10);
    var lastDay = new Date(year, mon, 0).getDate();
    var bounds = getSetupDateBounds();
    var monthMin = year + '-' + String(mon).padStart(2, '0') + '-01';
    var monthMax = year + '-' + String(mon).padStart(2, '0') + '-' + String(lastDay).padStart(2, '0');
    dateInput.setAttribute('min', bounds.min && bounds.min > monthMin ? bounds.min : monthMin);
    dateInput.setAttribute('max', bounds.max && bounds.max < monthMax ? bounds.max : monthMax);

    // Populate activities
    var container = document.getElementById('setupActivitiesContainer');
    container.innerHTML = '';
    if (month.activities && month.activities.length > 0) {
        month.activities.forEach(function (act) {
            var rowId = 'setup-activity-row-' + Date.now() + Math.floor(Math.random() * 1000);
            var rowHTML =
                '<div id="' + rowId + '"' +
                ' style="display: grid; grid-template-columns: 1fr 1fr 36px; gap: 8px;' +
                ' padding: 10px; background: white; border-radius: 8px;' +
                ' border: 1px solid #d1fae5; align-items: start;">' +
                '<input type="hidden" class="setup-activity-id" value="' + (act.id || '') + '"' +
                '/>' +
                '<input type="text" class="setup-activity-name" value="' + escapeHtml(act.activity_name || '') + '"' +
                ' placeholder="Activity name (e.g. Safety inspection)"' +
                ' style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db;' +
                ' border-radius: 6px; font-size: 12px;">' +
                '<input type="text" class="setup-activity-desc" value="' + escapeHtml(act.description || '') + '"' +
                ' placeholder="Description (optional)"' +
                ' style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db;' +
                ' border-radius: 6px; font-size: 12px;">' +
                '<button type="button" onclick="removeSetupActivityRow(\'' + rowId + '\')"' +
                ' style="background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5;' +
                ' padding: 7px; border-radius: 6px; cursor: pointer; height: 34px;' +
                ' display: inline-flex; align-items: center; justify-content: center;"' +
                ' onmouseover="this.style.background=\'#fecaca\';"' +
                ' onmouseout="this.style.background=\'#fee2e2\';">' +
                '<i class="bi bi-trash" style="font-size: 13px;"></i>' +
                '</button></div>';
            container.insertAdjacentHTML('beforeend', rowHTML);
        });
    } else {
        container.innerHTML =
            '<div id="setupActivitiesPlaceholder"' +
            ' style="text-align: center; color: #9ca3af; font-size: 11px; padding: 12px;' +
            ' background: #f9fafb; border-radius: 6px; border: 1px dashed #d1d5db;">' +
            'Click "Add Row" to add activities for this month</div>';
    }

    updateSetupActivitiesButtonLabel();

    // Switch button to "Update Month" mode
    var addBtn = document.getElementById('addSetupMonthBtn');
    addBtn.disabled = false;
    addBtn.style.opacity = '1';
    addBtn.style.cursor = 'pointer';
    addBtn.innerHTML = '<i class="bi bi-check-lg"></i> Update Month';
    addBtn.style.background = '#ca8a04';

    // Show cancel edit button
    var cancelBtn = document.getElementById('setupEditCancel');
    if (cancelBtn) cancelBtn.style.display = 'inline-flex';

    hideSetupErrors();
}

/**
 * Cancel the current edit and reset the form to add-new-month state.
 */
function cancelEdit() {
    setupEditingIndex = null;
    resetSetupForm();
    hideSetupErrors();
}

/** Minimal HTML-escape for activity values in edit mode. */
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// ─── ACTIVITY ROW MANAGEMENT (LOCAL FORM ONLY) ────────────────────────────────
// Mirrors addActivityRow() / removeActivityRow() in baseline-schedule-add-month-modal.js
// but uses 'setupActivitiesContainer' to avoid ID conflicts with the other modal.

/**
 * Add a blank activity input row to the setup modal's activities container.
 * Removes the placeholder text on first call.
 */
function addSetupActivityRow() {
    const container   = document.getElementById('setupActivitiesContainer');
    const placeholder = document.getElementById('setupActivitiesPlaceholder');

    // Remove placeholder once the first real row is added
    if (placeholder) placeholder.remove();

    const rowId  = 'setup-activity-row-' + Date.now();
    const rowHTML = `
        <div id="${rowId}"
             style="display: grid; grid-template-columns: 1fr 1fr 36px; gap: 8px;
                    padding: 10px; background: white; border-radius: 8px;
                    border: 1px solid #d1fae5; align-items: start;">
            <!-- Original activity id (empty for newly-added rows) -->
            <input type="hidden" class="setup-activity-id" value="">
            <!-- Activity name -->
            <input type="text" class="setup-activity-name"
                   placeholder="Activity name (e.g. Safety inspection)"
                   style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db;
                          border-radius: 6px; font-size: 12px;">
            <!-- Activity description (optional) -->
            <input type="text" class="setup-activity-desc"
                   placeholder="Description (optional)"
                   style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db;
                          border-radius: 6px; font-size: 12px;">
            <!-- Remove row button -->
            <button type="button" onclick="removeSetupActivityRow('${rowId}')"
                    style="background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5;
                           padding: 7px; border-radius: 6px; cursor: pointer; height: 34px;
                           display: inline-flex; align-items: center; justify-content: center;"
                    onmouseover="this.style.background='#fecaca';"
                    onmouseout="this.style.background='#fee2e2';">
                <i class="bi bi-trash" style="font-size: 13px;"></i>
            </button>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', rowHTML);

    // NEW: keep the main modal's "Manage Activities" button count in sync live,
    // not just when the stacked modal closes
    updateSetupActivitiesButtonLabel();
}

/**
 * Remove a single activity row from the setup modal's activities container.
 * Restores the placeholder text if no rows remain.
 * @param {string} rowId — the id attribute of the row div to remove
 */
function removeSetupActivityRow(rowId) {
    const row = document.getElementById(rowId);
    if (row) row.remove();

    // Restore placeholder if the container is now empty
    const container = document.getElementById('setupActivitiesContainer');
    if (container.children.length === 0) {
        container.innerHTML = `
            <div id="setupActivitiesPlaceholder"
                 style="text-align: center; color: #9ca3af; font-size: 11px; padding: 12px;
                        background: #f9fafb; border-radius: 6px; border: 1px dashed #d1d5db;">
                Click "Add Row" to add activities for this month
            </div>`;
    }

    // NEW: keep the main modal's "Manage Activities" button count in sync live
    updateSetupActivitiesButtonLabel();
}

/**
 * NEW: Open the "Manage Activities" modal, stacked on top of the main Set Up
 * Baseline modal. Activities entered here belong to whichever month is
 * currently being filled in on the left-hand form — nothing is submitted
 * anywhere until "Add Month" is clicked back on the main modal.
 *
 * NOTE: Bootstrap does not officially document stacked modals, but showing a
 * second modal while the first is already open works in practice — each new
 * modal + its backdrop gets appended later in the DOM, so it naturally paints
 * on top of the first. If any backdrop/scroll glitches surface later, this is
 * the place to look first.
 */
function openSetupActivitiesModal() {
    if (!setupActivitiesModalInstance) {
        setupActivitiesModalInstance = new bootstrap.Modal(
            document.getElementById('setupActivitiesModal')
        );
    }

    // Show which month these activities belong to, for clarity while the
    // modal is stacked on top and the main form isn't visible
    const monthValue = document.getElementById('setupMonthLabel').value;
    document.getElementById('setupActivitiesModalMonthLabel').textContent =
        monthValue ? '— ' + formatMonthLabel(monthValue) : '';

    setupActivitiesModalInstance.show();
}

/**
 * NEW: Close the Manage Activities modal and return to the main Set Up
 * Baseline modal underneath. Whatever rows currently exist in
 * #setupActivitiesContainer are left exactly as-is — they get read by
 * addSetupBaselineMonth() when "Add Month" is eventually clicked.
 */
function closeSetupActivitiesModal() {
    if (setupActivitiesModalInstance) {
        setupActivitiesModalInstance.hide();
    }
    // Refresh the "Manage Activities" button label on the main modal with the
    // current row count so the user can see at a glance how many were added
    updateSetupActivitiesButtonLabel();
}

/**
 * Update the "Manage Activities" button text on the main modal to show
 * how many activity rows are currently staged for the month being added.
 *
 * FIX: only counts rows that actually have a non-empty activity name typed
 * in, matching the exact same filter addSetupBaselineMonth() uses when it
 * builds the real activities array for saving. Previously this counted
 * ALL rows in the container (including a row added via "Add Row" but left
 * blank), so the button could show "1 added" for a row that would never
 * actually be saved — mismatching what "Add Month" would really submit.
 */
function updateSetupActivitiesButtonLabel() {
    const btnText = document.getElementById('setupManageActivitiesBtnText');
    if (!btnText) return;

    // Same query + same "has a name" filter as addSetupBaselineMonth() uses
    const rows = document.querySelectorAll(
        '#setupActivitiesContainer > div:not(#setupActivitiesPlaceholder)'
    );
    let filledCount = 0;
    rows.forEach(row => {
        const nameInput = row.querySelector('.setup-activity-name');
        if (nameInput && nameInput.value.trim()) {
            filledCount++;
        }
    });

    btnText.textContent = filledCount > 0
        ? `Manage Activities (${filledCount} added)`
        : 'Manage Activities (Optional)';
}

// ─── RENDER (FROM LOCAL STATE) ──────────────────────────────────────────────────

/**
 * Render the running list of months in the right-hand panel from the local
 * setupLocalMonths array. Nothing is fetched from the server here.
 * @param {Array} months — local array of month entries
 */
function renderSetupMonthList(months) {
    const listEl     = document.getElementById('setupMonthList');
    const countBadge = document.getElementById('setupMonthCount');

    countBadge.textContent = months.length;

    // ── Empty state ───────────────────────────────────────────────────────────
    if (months.length === 0) {
        var emptyMsg = setupEditOriginalMode
            ? 'No original baseline months found.'
            : (setupAdjustMode
                ? 'No months loaded. Try refreshing the page.'
                : 'No months added yet. Add your first month on the left.');
        listEl.innerHTML = `
            <div id="setupMonthListEmpty"
                 style="padding: 30px; text-align: center; color: #9ca3af; font-size: 12px;">
                <i class="bi bi-calendar-x"
                   style="font-size: 28px; display: block; margin-bottom: 8px; opacity: 0.4;"></i>
                ${emptyMsg}
            </div>
        `;
        updateAutoGenVisibility();
        return;
    }

    // ── Build list rows ───────────────────────────────────────────────────────
    let html = '';
    months.forEach((month, index) => {
        const pct          = parseFloat(month.planned_progress_percent) || 0;
        const origPct      = month.original_planned_progress_percent !== null && month.original_planned_progress_percent !== undefined
                             ? parseFloat(month.original_planned_progress_percent) : null;
        const is100        = pct >= 100;
        const isLocked     = (setupAdjustMode || setupEditOriginalMode) && month.has_actual_progress;
        const rowBg        = isLocked ? '#fefce8' : (is100 ? '#f0fdf4' : (index % 2 === 0 ? '#f9fafb' : 'white'));
        const percentColor = is100 ? '#16a34a' : '#667eea';
        const activityCount = month.activities ? month.activities.length : 0;
        var showDualPct    = setupAdjustMode && origPct !== null && origPct !== pct;

        html += `
            <div style="display: flex; align-items: center; justify-content: space-between;
                        padding: 10px 14px; border-bottom: 1px solid #e5e7eb; background: ${rowBg};">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <!-- Month number badge -->
                    <span style="background: #dbeafe; color: #1d4ed8; padding: 2px 7px;
                                 border-radius: 10px; font-size: 11px; font-weight: 700;">
                        #${index + 1}
                    </span>
                    <div>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <!-- Month label -->
                            <span style="font-weight: 600; color: #111827; font-size: 13px;">
                                ${formatMonthLabel(month.month_label)}
                            </span>
                            <!-- Lock icon for months with actual progress -->
                            ${isLocked
                                ? '<i class="bi bi-lock-fill" style="color: #d97706; font-size: 12px;" title="Has recorded progress — locked"></i>'
                                : ''}
                            ${month.isNew
                                ? '<span style="background: #dbeafe; color: #1d4ed8; font-size: 10px; padding: 1px 5px; border-radius: 4px; font-weight: 600;">New</span>'
                                : ''}
                        </div>
                        <!-- Optional description -->
                        ${month.description
                            ? `<div style="color: #6b7280; font-size: 11px;">${month.description}</div>`
                            : ''}
                        <!-- Activity count badge -->
                        ${activityCount > 0
                            ? `<div style="color: #0d9488; font-size: 11px; margin-top: 2px;">
                                   <i class="bi bi-list-check"></i>
                                   ${activityCount} activit${activityCount === 1 ? 'y' : 'ies'}
                               </div>`
                            : ''}
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 10px; flex-shrink: 0;">
                    <!-- Dual % display in adjust mode -->
                    ${showDualPct
                        ? `<span style="font-size: 11px; color: #9ca3af; text-decoration: line-through; margin-right: 2px;">
                               ${origPct.toFixed(2)}%
                           </span>`
                        : ''}
                    <!-- Progress % -->
                    <span style="font-weight: 700; color: ${percentColor}; font-size: 14px;">
                        ${pct.toFixed(2)}%
                    </span>
                    <!-- 100% checkmark -->
                    ${is100
                        ? '<i class="bi bi-check-circle-fill" style="color: #16a34a; font-size: 16px;"></i>'
                        : ''}
                    <!-- Edit / Locked indicator -->
                    ${isLocked
                        ? '' // Lock icon already shown next to name
                        : `<span onclick="editSetupBaselineMonth(${index})"
                                title="Edit this month"
                                style="cursor: pointer; color: #9ca3af; font-size: 15px; padding: 2px 4px;
                                       border-radius: 4px; transition: all 0.15s;"
                                onmouseover="this.style.color='#0d9488'; this.style.background='#ccfbf1';"
                                onmouseout="this.style.color='#9ca3af'; this.style.background='transparent';">
                              <i class="bi bi-pencil"></i>
                          </span>`}
                    <!-- Remove button — hidden for locked months in adjust mode -->
                    ${isLocked
                        ? ''
                        : `<span onclick="removeSetupBaselineMonth(${index})"
                                title="${setupAdjustMode ? 'Remove this month from the adjusted plan' : 'Remove this month'}"
                                style="cursor: pointer; color: #9ca3af; font-size: 15px; padding: 2px 4px;
                                       border-radius: 4px; transition: all 0.15s;"
                                onmouseover="this.style.color='#dc2626'; this.style.background='#fee2e2';"
                                onmouseout="this.style.color='#9ca3af'; this.style.background='transparent';">
                              <i class="bi bi-x-lg"></i>
                          </span>`}
                </div>
            </div>
        `;
    });

    listEl.innerHTML = html;
    updateAutoGenVisibility();
}

/**
 * Update the progress bar and "Done" button state based on the current
 * highest planned_progress_percent across all LOCAL months.
 * @param {Array} months — local array
 */
function updateSetupProgress(months) {
    const maxPercent = months.reduce((max, m) => {
        return Math.max(max, parseFloat(m.planned_progress_percent) || 0);
    }, 0);

    setupCurrentMaxPercent = maxPercent;

    // Update progress bar
    document.getElementById('setupCurrentMax').textContent = maxPercent.toFixed(0) + '%';
    document.getElementById('setupProgressBar').style.width = Math.min(maxPercent, 100) + '%';

    const isComplete = maxPercent >= 100;

    // Show/hide "Setup complete" notice inside the progress bar area
    document.getElementById('setupCompleteNotice').style.display = isComplete ? 'block' : 'none';

    // Enable or disable the "Done — Lock Baseline" button
    const doneBtn = document.getElementById('setupDoneBtn');
    doneBtn.disabled      = !isComplete;
    doneBtn.style.opacity = isComplete ? '1'    : '0.5';
    doneBtn.style.cursor  = isComplete ? 'pointer' : 'not-allowed';

    // Show/hide the Clear All button — hidden in adjust mode, visible in setup/edit modes
    var clearBtn = document.getElementById('setupClearAllBtn');
    if (clearBtn) {
        clearBtn.style.display = (months.length > 0 && !setupAdjustMode && !setupEditOriginalMode) ? 'inline-flex' : 'none';
    }
}

// ─── AUTO-GENERATE S-CURVE ──────────────────────────────────────────────────────

/**
 * Show/hide the auto-generate section based on whether the modal is empty
 * and the project has both start and end dates.
 */
function updateAutoGenVisibility() {
    var section = document.getElementById('setupAutoGenSection');
    if (!section) return;
    var bounds = getSetupDateBounds();
    var hasDates = bounds.min && bounds.max;
    var isEmpty = setupLocalMonths.length === 0;
    section.style.display = (hasDates && isEmpty) ? 'block' : 'none';
}

/**
 * Auto-generate monthly S-curve targets from project start/end dates.
 * Uses the Hermite smoothstep formula (industry-standard DHSS/Kenley-Wilson).
 */
function autoGenerateSCurve() {
    var bounds = getSetupDateBounds();
    if (!bounds.min || !bounds.max) {
        if (typeof showToast === 'function') showToast('Please set project start and end dates first.', 'error');
        return;
    }

    var curveType = (document.getElementById('setupAutoGenCurveType') || {}).value || 'symmetric';
    var core = window.scurveImportCore;
    if (!core || typeof core.generateAutoSCurve !== 'function') {
        if (typeof showToast === 'function') showToast('S-curve generator not loaded.', 'error');
        return;
    }

    var months = core.generateAutoSCurve(bounds.min, bounds.max, { loading: curveType });
    if (!months || months.length === 0) {
        if (typeof showToast === 'function') showToast('Could not generate S-curve. Check that start date is before end date.', 'error');
        return;
    }

    setupLocalMonths = months;
    setupCurrentMaxPercent = months.reduce(function (max, m) {
        return Math.max(max, parseFloat(m.planned_progress_percent) || 0);
    }, 0);
    setupEditingIndex = null;

    resetSetupForm();
    renderSetupMonthList(setupLocalMonths);
    updateSetupProgress(setupLocalMonths);
    updateSetupSCurvePreview();
    updateAutoGenVisibility();

    var label = curveType === 'front' ? 'front-loaded' : curveType === 'back' ? 'back-loaded' : 'standard';
    if (typeof showToast === 'function') {
        showToast('Generated ' + months.length + ' monthly targets using ' + label + ' S-curve.', 'success');
    }
}

// ─── S-CURVE PREVIEW CHART (Phase 2) ──────────────────────────────────────────

/**
 * Create or update the S-Curve preview chart from the local months array.
 * Called after each add/remove month to keep the preview in sync.
 *
 * Uses the shared createSCurveChart() from s-curve-chart.js for chart creation,
 * and the Chart.js data update API (chart.update()) for subsequent changes —
 * avoids destroying/recreating the DOM on every add/remove.
 *
 * Called by: addSetupBaselineMonth(), removeSetupBaselineMonth(), openSetupBaselineModal()
 * Calls: buildSetupPreviewChartData(), createSCurveChart()
 */
function updateSetupSCurvePreview() {
    var container = document.getElementById('setupSCurveContainer');
    if (!container) return;

    // ── Empty state: destroy chart and show placeholder ─────────────────────
    if (setupLocalMonths.length === 0) {
        if (setupSCurveChart) {
            setupSCurveChart.destroy();
            setupSCurveChart = null;
        }
        container.innerHTML =
            '<div style="display:flex; align-items:center; justify-content:center; ' +
            'height:100%; color:#9ca3af; font-size:13px; gap:8px; ' +
            'background: #f9fafb; border-radius: 8px;">' +
            '<i class="bi bi-graph-up" style="font-size:20px;"></i>' +
            '<span>Add months to see the S-Curve preview</span>' +
            '</div>';
        return;
    }

    // ── Build chart data from local months ──────────────────────────────────
    var chartData = buildSetupPreviewChartData(setupLocalMonths);

    if (setupSCurveChart) {
        // Update existing chart via Chart.js data API — no destroy/recreate
        // Update dataset 0 (Original Plan / Plan fallback)
        setupSCurveChart.data.datasets[0].data = chartData.frozen_planned_data;

        // Find index of existing Adjusted Plan dataset (if any)
        var adjIdx = -1;
        for (var di = 0; di < setupSCurveChart.data.datasets.length; di++) {
            if (setupSCurveChart.data.datasets[di].label === 'Adjusted Plan') {
                adjIdx = di;
                break;
            }
        }

        if (chartData.has_extension) {
            // Show Adjusted Plan — update or add dataset
            if (adjIdx >= 0) {
                setupSCurveChart.data.datasets[adjIdx].data = chartData.adjusted_planned_data;
            } else {
                setupSCurveChart.data.datasets.push({
                    label: 'Adjusted Plan',
                    data: chartData.adjusted_planned_data,
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245,158,11,0.05)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: false,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    pointBackgroundColor: '#f59e0b',
                    pointBorderColor: '#d97706',
                    pointBorderWidth: 1,
                    borderDash: [5, 3],
                    order: 3,
                    spanGaps: true
                });
            }
        } else if (adjIdx >= 0) {
            // No extension needed — remove the Adjusted Plan dataset
            setupSCurveChart.data.datasets.splice(adjIdx, 1);
        }
        setupSCurveChart.update();
    } else {
        // Create chart for the first time: replace container with canvas
        container.innerHTML = '<canvas id="setupSCurveCanvas"></canvas>';
        setupSCurveChart = createSCurveChart('setupSCurveCanvas', chartData);
    }
}

// ─── HELPERS ──────────────────────────────────────────────────────────────────

/**
 * Reset the left-hand input form to empty/default state.
 * Called after a month is added locally and on modal open.
 */
function resetSetupForm() {
    // Clear basic fields
    var monthInput = document.getElementById('setupMonthLabel');
    var dateInput = document.getElementById('setupPlannedDate');
    monthInput.value = '';
    monthInput.removeAttribute('readonly');
    dateInput.value = '';

    // ── Dynamic month/date constraints ─────────────────────────────────────
    // First target:
    //   - If current < planned start → min = current (plan from today)
    //   - If current ≥ planned start AND current ≤ planned end → min = planned start
    //   - If current > planned end → min = planned end (overdue project)
    // Subsequent targets: locked to next month only (min=max=next month)
    var bounds = getSetupDateBounds();
    var now = new Date();
    var todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    var currentMonth = todayStr.substring(0, 7);

    // Compute effective min date for first target
    var effectiveMinDate;
    if (bounds.max && todayStr > bounds.max) {
        // Current date is past planned end — overdue project, min = planned end
        effectiveMinDate = bounds.max;
    } else if (bounds.min && todayStr < bounds.min) {
        // Current date is before planned start — plan from today
        effectiveMinDate = todayStr;
    } else {
        // Current date is within project timeline — plan from planned start
        effectiveMinDate = bounds.min || todayStr;
    }
    var effectiveMinMonth = effectiveMinDate ? effectiveMinDate.substring(0, 7) : currentMonth;

    // Effective max month from planned end date
    var endMonth = bounds.max ? bounds.max.substring(0, 7) : null;

    // Re-enable the Add Month button (may have been disabled previously)
    var addBtn = document.getElementById('addSetupMonthBtn');
    addBtn.disabled = false;
    addBtn.style.opacity = '1';
    addBtn.style.cursor = 'pointer';

    if (setupLocalMonths.length === 0) {
        // FIRST TARGET: month picker min = effectiveMinMonth, no upper cap
        monthInput.setAttribute('min', effectiveMinMonth);
        monthInput.removeAttribute('max');

        // Date picker min = effectiveMinDate
        dateInput.setAttribute('min', effectiveMinDate);

        // Date picker max = planned end date
        if (bounds.max) {
            dateInput.setAttribute('max', bounds.max);
        } else {
            dateInput.removeAttribute('max');
        }
    } else {
        // ── SUBSEQUARY TARGETS: find first missing month in timeline ────────
        // Calculate all months from planned start to planned end
        var startMonth = bounds.min ? bounds.min.substring(0, 7) : null;
        var existingMonths = setupLocalMonths.map(function(m) { return m.month_label; });

        if (startMonth && endMonth) {
            // Build array of all expected months from start to end
            var allExpected = [];
            var cursorParts = startMonth.split('-').map(Number);
            var endParts = endMonth.split('-').map(Number);
            while (cursorParts[0] < endParts[0] || (cursorParts[0] === endParts[0] && cursorParts[1] <= endParts[1])) {
                allExpected.push(cursorParts[0] + '-' + String(cursorParts[1]).padStart(2, '0'));
                cursorParts[1]++;
                if (cursorParts[1] > 12) { cursorParts[1] = 1; cursorParts[0]++; }
            }

            // Find the first missing month
            var firstMissing = null;
            for (var i = 0; i < allExpected.length; i++) {
                if (existingMonths.indexOf(allExpected[i]) === -1) {
                    firstMissing = allExpected[i];
                    break;
                }
            }

            if (firstMissing) {
                // There are gaps — allow adding the first missing month
                monthInput.setAttribute('min', firstMissing);
                monthInput.setAttribute('max', firstMissing);

                // Constrain date picker to the missing month's range
                var mParts = firstMissing.split('-').map(Number);
                var lastDay = new Date(mParts[0], mParts[1], 0).getDate();
                var monthMinDate = firstMissing + '-01';
                var monthMaxDate = firstMissing + '-' + String(lastDay).padStart(2, '0');
                dateInput.setAttribute('min', monthMinDate);
                dateInput.setAttribute('max', monthMaxDate);
            } else {
                // All months filled — disable Add Month button
                addBtn.disabled = true;
                addBtn.style.opacity = '0.5';
                addBtn.style.cursor = 'not-allowed';
                showSetupErrors(['All months up to the planned end date have been added. Click "Done \u2014 Lock Baseline" to save.']);
                monthInput.removeAttribute('min');
                monthInput.removeAttribute('max');
                dateInput.removeAttribute('min');
                dateInput.removeAttribute('max');
                document.getElementById('setupProgressPercent').value = '';
                document.getElementById('setupDescription').value = '';
                resetActivitiesContainer();
                updateSetupActivitiesButtonLabel();
                setupEditingIndex = null;
                addBtn.innerHTML = '<i class="bi bi-plus-circle"></i> Add Month';
                addBtn.style.background = '#0d9488';
                var cancelBtn = document.getElementById('setupEditCancel');
                if (cancelBtn) cancelBtn.style.display = 'none';
                return;
            }
        } else {
            // No bounds available — fallback: allow next month after latest
            var latestLabel = setupLocalMonths[setupLocalMonths.length - 1].month_label;
            var parts = latestLabel.split('-').map(Number);
            var nextYear = parts[0];
            var nextMon = parts[1] + 1;
            if (nextMon > 12) { nextMon = 1; nextYear++; }
            var nextMonth = nextYear + '-' + String(nextMon).padStart(2, '0');
            monthInput.setAttribute('min', nextMonth);
            monthInput.setAttribute('max', nextMonth);

            var nextLastDay = new Date(nextYear, nextMon, 0).getDate();
            dateInput.setAttribute('min', nextMonth + '-01');
            dateInput.setAttribute('max', nextMonth + '-' + String(nextLastDay).padStart(2, '0'));
        }
    }

    document.getElementById('setupProgressPercent').value = '';
    document.getElementById('setupDescription').value     = '';

    // Reset activities container back to placeholder state
    resetActivitiesContainer();

    // NEW: reset the "Manage Activities" button label back to its default text
    updateSetupActivitiesButtonLabel();

    // Exit edit mode if active
    setupEditingIndex = null;
    addBtn.innerHTML = '<i class="bi bi-plus-circle"></i> Add Month';
    addBtn.style.background = '#0d9488';
    var cancelBtn = document.getElementById('setupEditCancel');
    if (cancelBtn) cancelBtn.style.display = 'none';

    hideSetupErrors();
}

/**
 * Reset activities container to placeholder state.
 */
function resetActivitiesContainer() {
    const activitiesContainer = document.getElementById('setupActivitiesContainer');
    if (activitiesContainer) {
        activitiesContainer.innerHTML = `
            <div id="setupActivitiesPlaceholder"
                 style="text-align: center; color: #9ca3af; font-size: 11px; padding: 12px;
                        background: #f9fafb; border-radius: 6px; border: 1px dashed #d1d5db;">
                Click "Add Row" to add activities for this month
            </div>`;
    }
}

/**
 * Show inline error messages inside the modal.
 * @param {string[]} errors
 */
function showSetupErrors(errors) {
    const container = document.getElementById('setupErrorContainer');
    const list      = document.getElementById('setupErrorList');
    list.innerHTML  = errors.map(e => `<li>${e}</li>`).join('');
    container.style.display = 'block';
}

/**
 * Convert YYYY-MM to "MonthName YYYY" for display, e.g. "2026-01" → "January 2026".
 * Internal data stays YYYY-MM for comparison and server submission.
 */
function formatMonthLabel(val) {
    if (!val) return '';
    var parts = val.split('-');
    if (parts.length !== 2) return val;
    var monthIdx = parseInt(parts[1], 10) - 1;
    var months = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    return months[monthIdx] + ' ' + parts[0];
}

/** Hide the inline error container. */
function hideSetupErrors() {
    document.getElementById('setupErrorContainer').style.display = 'none';
    document.getElementById('setupErrorList').innerHTML = '';
}

// ─── RESET MODAL UI TO DEFAULTS ───────────────────────────────────────────────
// Called when the modal is hidden or when the user cancels in adjust mode, so
// the next time the modal opens it shows the original (initial-setup) text.
function resetModalUIToDefaults() {
    var titleEl = document.getElementById('setupModalTitle');
    if (titleEl) titleEl.textContent = 'Set Up Baseline Schedule';
    var iconEl = document.getElementById('setupModalTitleIcon');
    if (iconEl) iconEl.className = 'bi bi-flag-fill';
    var doneBtn = document.getElementById('setupDoneBtn');
    if (doneBtn) {
        doneBtn.innerHTML = '<i class="bi bi-check-lg" style="margin-right: 6px;"></i>Done \u2014 Lock Baseline';
    }
    var warningEl = document.getElementById('setupWarningNotice');
    if (warningEl) {
        warningEl.innerHTML =
            '<i class="bi bi-exclamation-triangle-fill" style="color: #f59e0b; font-size: 16px; flex-shrink: 0; margin-top: 0;"></i>' +
            '<div>' +
            '<div style="font-weight: 700; color: #92400e; font-size: 12px; margin-bottom: 2px;">Important: Baseline will be frozen after setup</div>' +
            '<div style="color: #78350f; font-size: 11px; line-height: 1.4;">' +
            'Once you complete this setup, the baseline schedule becomes the <strong>permanent original plan</strong> ' +
            'and cannot be edited (it serves as an audit trail). You must set targets for ' +
            '<strong>every month up to 100%</strong> before finishing.</div></div>';
        warningEl.style.background = '#fffbeb';
        warningEl.style.border = '1px solid #fcd34d';
        warningEl.style.borderLeft = '4px solid #f59e0b';
    }
    // Reset the month add form button text
    setupEditingIndex = null;
    var addBtn = document.getElementById('addSetupMonthBtn');
    if (addBtn) {
        addBtn.innerHTML = '<i class="bi bi-plus-circle"></i> Add Month';
        addBtn.style.background = '#0d9488';
    }
    // Reset remaining work input
    var rwInput = document.getElementById('setupAdditionalWorkPercent');
    if (rwInput) rwInput.value = '';
}

// ─── MONTH ↔ DATE AUTO-SYNC ───────────────────────────────────────────────────
// Mirrors the sync behaviour in baseline-schedule-add-month-modal.js:
// Changing the month picker auto-updates the date picker (to last day of that
// month) and vice versa.

document.addEventListener('DOMContentLoaded', function () {
    const monthInput = document.getElementById('setupMonthLabel');
    const dateInput  = document.getElementById('setupPlannedDate');

    if (!monthInput || !dateInput) return;

    // Month → Date: set date to last day of selected month
    monthInput.addEventListener('change', function () {
        if (!this.value) return;
        const [year, month] = this.value.split('-').map(Number);
        // Last day: day 0 of the NEXT month
        const lastDay = new Date(year, month, 0).getDate();
        dateInput.value = `${year}-${String(month).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
    });

    // Date → Month: derive yyyy-MM from the chosen date (skip during edit mode)
    dateInput.addEventListener('change', function () {
        if (!this.value) return;
        if (setupEditingIndex !== null) return; // month is locked during edit
        const [year, month] = this.value.split('-');
        monthInput.value = `${year}-${month}`;
    });

    // Destroy chart instance when modal is hidden — prevents memory leaks
    // if the user re-opens the modal multiple times in the same page session.
    // Also clean up context and edit state.
    var setupModalEl = document.getElementById('setupBaselineModal');
    if (setupModalEl) {
        setupModalEl.addEventListener('hidden.bs.modal', function () {
            if (setupSCurveChart) {
                setupSCurveChart.destroy();
                setupSCurveChart = null;
            }
            setupEditingIndex = null;
            setupAdjustMode = false;
            setupEditOriginalMode = false;
            setupRemovedIds = [];
            setupProjectEndDate = null;
            // Clear caller context to prevent stale state on re-open
            window.__setupModalContext = null;
            resetModalUIToDefaults();
        });
    }

    // NEW: live-update the "Manage Activities" button count while typing.
    // Uses EVENT DELEGATION — one listener on the container (which always
    // exists in the page) instead of attaching a listener to each activity
    // row individually. This way, rows added later by addSetupActivityRow()
    // are automatically covered with no extra wiring needed per row.
    // Fixes the flagged gap: previously the button count only refreshed on
    // add/remove/close, so typing a name (or deleting one) without doing
    // one of those actions left the button showing a stale count.
    const setupActivitiesContainer = document.getElementById('setupActivitiesContainer');
    if (setupActivitiesContainer) {
        setupActivitiesContainer.addEventListener('input', function (event) {
            // Only react to typing in an activity NAME field — description
            // typing doesn't affect the count (a row only "counts" once it
            // has a name, same rule used everywhere else for this feature)
            if (event.target.classList.contains('setup-activity-name')) {
                updateSetupActivitiesButtonLabel();
            }
        });
    }
});
