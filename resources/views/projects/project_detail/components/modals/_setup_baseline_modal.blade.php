{{--
    Set Up Baseline Modal
    =====================
    Shown ONLY when a project has no baseline schedule rows yet (or to admin at any time).
    Guides the user through building the full S-Curve plan — one month at a time —
    before locking the baseline as frozen/immutable.

    Saves via the EXISTING storeBaselineSchedule() endpoint (POST .../baseline-schedules).
    No new endpoint needed: when extension_count == 0, that method already writes both
    planned_progress_percent AND original_planned_progress_percent identically.

    Dependencies (set by baseline-schedule.blade.php inline script):
        window.projectId
        window.routePrefix
        window.csrfToken

    JS functions live in: baseline-schedule-setup-modal.js
--}}

<!-- ============================================================ -->
<!-- SET UP BASELINE MODAL                                         -->
<!-- ============================================================ -->
<div class="modal fade" id="setupBaselineModal" tabindex="-1" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 12px; border: none;">

            <!-- Header -->
            <div class="modal-header" style="background: linear-gradient(135deg, #0f766e 0%, #0d9488 100%); color: white; border: none; padding: 18px 24px;">
                <h5 class="modal-title" style="font-weight: 700; display: flex; align-items: center; gap: 10px; margin: 0;">
                    <i class="bi bi-flag-fill" id="setupModalTitleIcon" style="font-size: 20px;"></i>
                    <span id="setupModalTitle">Set Up Baseline Schedule</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" onclick="onSetupBaselineCancel()"></button>
            </div>

            <!-- Body -->
            <div class="modal-body" style="padding: 18px 24px;">

                <!-- ── WARNING NOTICE ─────────────────────────────────── -->
                <div id="setupWarningNotice" style="background: #fffbeb; border: 1px solid #fcd34d; border-left: 4px solid #f59e0b; border-radius: 8px; padding: 10px 16px; margin-bottom: 14px;">
                    <div style="display: flex; gap: 8px; align-items: flex-start;">
                        <i class="bi bi-exclamation-triangle-fill" style="color: #f59e0b; font-size: 16px; flex-shrink: 0; margin-top: 0;"></i>
                        <div>
                            <div style="font-weight: 700; color: #92400e; font-size: 12px; margin-bottom: 2px;">Important: Baseline will be frozen after setup</div>
                            <div style="color: #78350f; font-size: 11px; line-height: 1.4;">
                                Once you complete this setup, the baseline schedule becomes the <strong>permanent original plan</strong>
                                and cannot be edited (it serves as an audit trail). You must set targets for
                                <strong>every month up to 100%</strong> before finishing.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── ADDITIONAL WORK % (adjust mode only) ──────────── -->
                <div id="setupRemainingWorkWrapper" style="display: none; margin-bottom: 14px; padding: 12px 16px; background: #fef3c7; border: 1px solid #fbbf24; border-left: 4px solid #f59e0b; border-radius: 8px;">
                    <div style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                        <div style="flex: 0 0 auto;">
                            <label for="setupAdditionalWorkPercent" style="display: block; margin-bottom: 4px; font-weight: 600; font-size: 11px; color: #92400e;">
                                Additional Work %
                            </label>
                            <input type="number" id="setupAdditionalWorkPercent"
                                   min="0" max="100" step="0.01" value=""
                                   placeholder="e.g. 5"
                                   style="width: 100px; padding: 8px 10px; border: 1px solid #f59e0b; border-radius: 6px; font-size: 12px; font-weight: 600;"
                                   oninput="onAdditionalWorkChange()">
                        </div>
                        <div style="flex: 0 0 auto;">
                            <button type="button" onclick="rerunAutoAdjust()"
                                    style="padding: 8px 14px; background: #f59e0b; color: white; border: none; border-radius: 6px; font-weight: 600; font-size: 11px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s;"
                                    onmouseover="this.style.background='#d97706'"
                                    onmouseout="this.style.background='#f59e0b'">
                                <i class="bi bi-arrow-clockwise"></i> Re-run Auto Adjust
                            </button>
                        </div>
                        <div style="flex: 1; min-width: 200px;">
                            <small style="color: #78350f; font-size: 11px; line-height: 1.4; display: block;">
                                <i class="bi bi-info-circle"></i>
                                Estimated % of work to be done in the <strong>extension period</strong>.
                                The original deadline month will adjust to (100 - this value)%, and non-locked months scale proportionally.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- ── AUTO-GENERATE S-CURVE (shown when empty + dates available) ── -->
                <div id="setupAutoGenSection" style="display: none; margin-bottom: 14px;">
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #3b82f6; border-radius: 8px; padding: 12px 16px;">
                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 200px;">
                                <div style="font-weight: 700; color: #1e40af; font-size: 12px; margin-bottom: 2px;">
                                    <i class="bi bi-magic"></i> Quick Start: Auto-Generate S-Curve
                                </div>
                                <div style="color: #1e3a5f; font-size: 11px; line-height: 1.4;">
                                    Automatically generate monthly targets from your project's planned start and end dates.
                                    You can review and edit each month after generation.
                                </div>
                            </div>
                            <select id="setupAutoGenCurveType"
                                    style="padding: 6px 10px; border: 1px solid #93c5fd; border-radius: 6px; font-size: 11px; font-weight: 600; color: #1e40af; background: white;">
                                <option value="symmetric">Standard S-Curve</option>
                                <option value="front">Front-Loaded (Early Peak)</option>
                                <option value="back">Back-Loaded (Late Peak)</option>
                            </select>
                            <button type="button" onclick="autoGenerateSCurve()" id="setupAutoGenBtn"
                                    style="padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                                <i class="bi bi-lightning-charge-fill"></i> Generate
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── S-CURVE PREVIEW (full-width top) ──────────────── -->
                <div style="margin-bottom: 14px;">
                    @include('components._s_curve_chart', [
                        'chartId' => 'setupSCurve',
                        'height' => '230px',
                        'placeholder' => 'Add months to see the S-Curve preview',
                    ])
                </div>

                <!-- ── BOTTOM SECTION: 2-col Form | Progress + List ──── -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px;">

                    <!-- LEFT: Add a month form (2-column fields) -->
                    <div>
                        <h6 style="font-weight: 700; color: #111827; margin-bottom: 12px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 6px;">
                            <i class="bi bi-plus-circle" style="color: #0d9488;"></i> Add a Monthly Target
                        </h6>

                        <!-- Error container -->
                        <div id="setupErrorContainer" style="display: none; margin-bottom: 10px; padding: 8px 12px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px;">
                            <div style="display: flex; gap: 6px; align-items: flex-start;">
                                <i class="bi bi-exclamation-circle" style="color: #dc2626; font-size: 14px; flex-shrink: 0;"></i>
                                <ul id="setupErrorList" style="margin: 0; padding-left: 14px; color: #7f1d1d; font-size: 11px;"></ul>
                            </div>
                        </div>

                        <div style="background: #f9fafb; padding: 14px; border-radius: 10px; border: 1px solid #e5e7eb;">

                            <!-- Row 1: Month + Date side by side -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                <div>
                                    <label for="setupMonthLabel" style="display: block; margin-bottom: 4px; font-weight: 600; font-size: 11px; color: #374151;">
                                        Month <span style="color: #ef4444;">*</span>
                                    </label>
                                    <input type="month" id="setupMonthLabel"
                                           style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px;">
                                </div>
                                <div>
                                    <label for="setupPlannedDate" style="display: block; margin-bottom: 4px; font-weight: 600; font-size: 11px; color: #374151;">
                                        Target Date <span style="color: #ef4444;">*</span>
                                    </label>
                                    <input type="date" id="setupPlannedDate"
                                           style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px;">
                                </div>
                            </div>
                            <small style="color: #6b7280; font-size: 10px; display: block; margin-top: -6px; margin-bottom: 10px;">Month and date auto-sync.</small>

                            <!-- Row 2: Progress % + Description side by side -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                <div>
                                    <label for="setupProgressPercent" style="display: block; margin-bottom: 4px; font-weight: 600; font-size: 11px; color: #374151;">
                                        Progress % <span style="color: #ef4444;">*</span>
                                    </label>
                                    <input type="number" id="setupProgressPercent" min="0" max="100" step="0.01" value=""
                                           placeholder="e.g. 25"
                                           style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px;">
                                </div>
                                <div>
                                    <label for="setupDescription" style="display: block; margin-bottom: 4px; font-weight: 600; font-size: 11px; color: #374151;">
                                        Description <span style="color: #9ca3af;">(Opt)</span>
                                    </label>
                                    <input type="text" id="setupDescription"
                                           placeholder="e.g. Foundation work"
                                           style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px;">
                                </div>
                            </div>
                            <small style="color: #6b7280; font-size: 10px; display: block; margin-top: -6px; margin-bottom: 10px;">% must be higher than previous month. Last month must reach 100%.</small>

                            <!-- Manage Activities -->
                            <div style="margin-bottom: 10px;">
                                <button type="button" onclick="openSetupActivitiesModal()"
                                        id="setupManageActivitiesBtn"
                                        style="width: 100%; padding: 7px 10px; background: white; color: #0d9488;
                                               border: 1px solid #99f6e4; border-radius: 6px; cursor: pointer;
                                               font-weight: 600; font-size: 11px; display: flex; align-items: center;
                                               justify-content: center; gap: 5px; transition: all 0.2s;"
                                        onmouseover="this.style.background='#f0fdfa'; this.style.borderColor='#2dd4bf';"
                                        onmouseout="this.style.background='white'; this.style.borderColor='#99f6e4';">
                                    <i class="bi bi-list-check"></i>
                                    <span id="setupManageActivitiesBtnText">Manage Activities (Optional)</span>
                                </button>
                            </div>

                            <!-- Add Month / Update Month button -->
                            <div style="display: flex; gap: 8px;">
                                <button type="button" onclick="addSetupBaselineMonth()"
                                        id="addSetupMonthBtn"
                                        style="flex: 1; padding: 8px; background: #0d9488; color: white; border: none; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; transition: background 0.2s;"
                                        onmouseover="this.style.background='#0f766e'"
                                        onmouseout="this.style.background='#0d9488'">
                                    <i class="bi bi-plus-circle"></i> Add Month
                                </button>
                                <button type="button" id="setupEditCancel" onclick="cancelEdit()"
                                        style="display: none; padding: 8px 14px; background: white; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; align-items: center; gap: 5px; transition: all 0.2s;"
                                        onmouseover="this.style.background='#f3f4f6'"
                                        onmouseout="this.style.background='white'">
                                    <i class="bi bi-x-circle"></i> Cancel Edit
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: Progress bar + Month list -->
                    <div>
                        <h6 style="font-weight: 700; color: #111827; margin-bottom: 12px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 6px;">
                            <i class="bi bi-list-check" style="color: #0d9488;"></i>
                            Months Added
                            <span id="setupMonthCount" style="background: #ccfbf1; color: #0f766e; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700;">0</span>
                            <button type="button" id="setupClearAllBtn" onclick="clearAllSetupMonths()"
                                    style="display: none; margin-left: auto; padding: 3px 10px; background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; border-radius: 6px; font-weight: 600; font-size: 10px; cursor: pointer; transition: all 0.2s;"
                                    onmouseover="this.style.background='#fee2e2'; this.style.borderColor='#f87171'"
                                    onmouseout="this.style.background='#fef2f2'; this.style.borderColor='#fca5a5'">
                                <i class="bi bi-trash3"></i> Clear All
                            </button>
                            <button type="button" id="setupRestoreBtn" onclick="restoreToOriginalBaseline()"
                                    style="display: none; margin-left: auto; padding: 3px 10px; background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; border-radius: 6px; font-weight: 600; font-size: 10px; cursor: pointer; transition: all 0.2s;"
                                    onmouseover="this.style.background='#fef3c7'; this.style.borderColor='#fbbf24'"
                                    onmouseout="this.style.background='#fffbeb'; this.style.borderColor='#fcd34d'">
                                <i class="bi bi-arrow-counterclockwise"></i> Restore to Original
                            </button>
                        </h6>

                        <!-- Progress toward 100% indicator -->
                        <div style="margin-bottom: 10px; padding: 10px 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                <small style="font-weight: 600; color: #166534; font-size: 11px;">Plan Progress</small>
                                <span id="setupCurrentMax" style="font-weight: 700; color: #166534; font-size: 12px;">0%</span>
                            </div>
                            <div style="background: #dcfce7; border-radius: 4px; height: 5px; overflow: hidden;">
                                <div id="setupProgressBar" style="height: 100%; background: #16a34a; border-radius: 4px; width: 0%; transition: width 0.3s;"></div>
                            </div>
                            <div id="setupCompleteNotice" style="display: none; margin-top: 6px; color: #166534; font-size: 11px; font-weight: 600;">
                                <i class="bi bi-check-circle-fill"></i> Baseline plan is complete and ready to save!
                            </div>
                        </div>

                        <!-- List of months -->
                        <div id="setupMonthList"
                             style="max-height: 280px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; background: white;">
                            <div id="setupMonthListEmpty"
                                 style="padding: 24px; text-align: center; color: #9ca3af; font-size: 12px;">
                                <i class="bi bi-calendar-x" style="font-size: 24px; display: block; margin-bottom: 6px; opacity: 0.4;"></i>
                                No months added yet. Add your first month on the left.
                            </div>
                        </div>
                    </div>

                </div>
                <!-- end bottom two-column section -->

            </div>
            <!-- end modal-body -->

            <!-- Footer -->
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-outline-secondary"
                        onclick="onSetupBaselineCancel()"
                        style="border-color: #d1d5db; font-size: 13px;">
                    Cancel / Finish Later
                </button>
                {{-- "Done" button: only enabled once 100% reached --}}
                <button type="button" id="setupDoneBtn"
                        onclick="onSetupBaselineDone()"
                        disabled
                        style="padding: 8px 20px; background: #16a34a; color: white; border: none; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: not-allowed; opacity: 0.5; transition: all 0.2s;">
                    <i class="bi bi-check-lg" style="margin-right: 6px;"></i>Done — Lock Baseline
                </button>
            </div>

        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- SET UP BASELINE — MANAGE ACTIVITIES MODAL (stacked)           -->
<!-- Opens ON TOP of setupBaselineModal for the month currently    -->
<!-- being filled in on the left-hand form. Closing this modal     -->
<!-- returns to setupBaselineModal without submitting anything —   -->
<!-- activities only attach to a month when "Add Month" is clicked -->
<!-- on the main modal.                                            -->
<!-- ============================================================ -->
<div class="modal fade" id="setupActivitiesModal" tabindex="-1" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 12px; border: none;">

            <!-- Header -->
            <div class="modal-header" style="background: linear-gradient(135deg, #0f766e 0%, #0d9488 100%); color: white; border: none; padding: 16px 22px;">
                <h5 class="modal-title" style="font-weight: 700; display: flex; align-items: center; gap: 10px; margin: 0; font-size: 15px;">
                    <i class="bi bi-list-check" style="font-size: 18px;"></i>
                    Manage Activities
                    <span id="setupActivitiesModalMonthLabel" style="opacity: 0.85; font-weight: 500; font-size: 12px;"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" onclick="closeSetupActivitiesModal()"></button>
            </div>

            <!-- Body -->
            <div class="modal-body" style="padding: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <small style="color: #6b7280; font-size: 12px;">Optional — activities to complete for this month's target.</small>
                    <button type="button" onclick="addSetupActivityRow()"
                            style="background: white; color: #0d9488; border: 1px solid #99f6e4; padding: 5px 12px;
                                   border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 11px;
                                   display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s;"
                            onmouseover="this.style.background='#f0fdfa'; this.style.borderColor='#2dd4bf';"
                            onmouseout="this.style.background='white'; this.style.borderColor='#99f6e4';">
                        <i class="bi bi-plus-circle" style="font-size: 13px;"></i> Add Row
                    </button>
                </div>

                <!-- Activity rows injected here by addSetupActivityRow() —
                     same container/placeholder IDs as before, just relocated
                     into this stacked modal instead of the main form -->
                <div id="setupActivitiesContainer"
                     style="display: flex; flex-direction: column; gap: 8px; min-height: 60px; max-height: 320px; overflow-y: auto;">
                    <div id="setupActivitiesPlaceholder"
                         style="text-align: center; color: #9ca3af; font-size: 11px; padding: 16px;
                                background: #f9fafb; border-radius: 6px; border: 1px dashed #d1d5db;">
                        Click "Add Row" to add activities for this month
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="modal-footer" style="padding: 14px 22px; border-top: 1px solid #e5e7eb; background: #f9fafb;">
                <button type="button" onclick="closeSetupActivitiesModal()"
                        style="padding: 8px 20px; background: #0d9488; color: white; border: none;
                               border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer;">
                    <i class="bi bi-check-lg" style="margin-right: 6px;"></i>Done
                </button>
            </div>

        </div>
    </div>
</div>
{{-- JS loaded by baseline-schedule.blade.php:
     <script src="{{ asset('js/admin/projects/baseline-schedule-setup-modal.js') }}"></script>
--}}
