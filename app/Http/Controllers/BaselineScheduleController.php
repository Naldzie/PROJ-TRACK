<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectActivityCarryover;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BaselineScheduleController
 *
 * Handles all ProjectBaselineSchedule (monthly progress targets) operations:
 * - Retrieving baseline schedules for a project
 * - Creating new monthly targets with associated activities
 * - Deleting baseline schedules with cascading cleanup
 *
 * Moved from ProjectController as part of Phase 2 refactor (April 2026)
 */
class BaselineScheduleController extends Controller
{
    public function getBaselineScheduleMonths(Project $project)
    {
        try {
            $months = ProjectBaselineSchedule::where('project_id', $project->id)
                ->orderBy('planned_date', 'asc')
                ->get()
                ->map(function ($schedule) use ($project) {
                    $activitiesCount = $project->activities()->where('month_label', $schedule->month_label)->count();
                    $activitiesCompleted = $project->activities()->where('month_label', $schedule->month_label)->where('is_completed', true)->count();

                    // NEW: does this row already have actual progress recorded against it?
                    // Used by the frontend to decide if a post-extension row can still be edited.
                    $hasActualProgress = $schedule->progressHistory()->exists();

                    return [
                        'id' => $schedule->id,
                        'month_label' => $schedule->month_label,
                        'planned_date' => $schedule->planned_date->format('Y-m-d'),
                        'planned_progress_percent' => $schedule->planned_progress_percent,
                        // NEW: frozen original plan value (null = added/edited post-extension)
                        'original_planned_progress_percent' => $schedule->original_planned_progress_percent,
                        'description' => $schedule->description,
                        'activities_count' => $activitiesCount,
                        'activities_completed' => $activitiesCompleted,
                        // NEW: edit-eligibility flag consumed by the blade/JS
                        'has_actual_progress' => $hasActualProgress,
                    ];
                });

            return response()->json([
                'success' => true,
                'months' => $months,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching baseline schedule: '.$e->getMessage(),
            ], 500);
        }
    }

    public function storeBaselineSchedule(Request $request, Project $project)
    {
        $this->authorizeProjectAccess(auth()->user(), $project);

        try {
            $validated = $request->validate([
                'planned_date' => 'required|date',
                'month_label' => 'required|string|regex:/^\d{4}-\d{2}(-\d{2})?/',
                'planned_progress_percent' => 'required|numeric|between:0,100',
                'description' => 'nullable|string',
                'activities' => 'nullable|array',
                'activities.*.activity_name' => 'required_with:activities|string|max:255',
                'activities.*.description' => 'nullable|string',
            ]);

            // Check if month target already exists
            $existing = ProjectBaselineSchedule::where('project_id', $project->id)
                ->where('month_label', $validated['month_label'])
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Baseline schedule target for this month already exists',
                ], 422);
            }

            // Rule A: target date must fall within the selected calendar month
            $dateMonth = substr($validated['planned_date'], 0, 7);
            if ($dateMonth !== $validated['month_label']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target date must fall within '.$validated['month_label'],
                ], 422);
            }

            // Duplicate check: same planned_date not allowed
            $existingDate = ProjectBaselineSchedule::where('project_id', $project->id)
                ->where('planned_date', $validated['planned_date'])
                ->exists();
            if ($existingDate) {
                return response()->json([
                    'success' => false,
                    'message' => 'A target with this date already exists for this project.',
                ], 422);
            }

            // Rule BOUNDS: target must fall within the project timeline.
            // The baseline IS the plan, so targets may not precede the planned
            // start date nor extend past the CURRENT (post-extension) planned
            // end date.
            $projectStart = $project->planned_start_date;
            $projectEnd = $project->planned_end_date;

            if (! $projectStart || ! $projectEnd) {
                return response()->json([
                    'success' => false,
                    'message' => 'Set the project planned start and end dates before adding a month target.',
                ], 422);
            }

            $newDate = \Carbon\Carbon::parse($validated['planned_date']);

            if ($newDate->lt($projectStart)) {
                return response()->json([
                    'success' => false,
                    'message' => "Target date must not be before the project's planned start date ({$projectStart->format('Y-m-d')}).",
                ], 422);
            }

            if ($newDate->gt($projectEnd)) {
                return response()->json([
                    'success' => false,
                    'message' => "Target date must not be after the project's planned end date ({$projectEnd->format('Y-m-d')}).",
                ], 422);
            }

            // Rules B & C: chronological ordering and before-start guard
            $dateRange = ProjectBaselineSchedule::where('project_id', $project->id)
                ->selectRaw('MIN(planned_date) as earliest, MAX(planned_date) as latest')
                ->first();

            // Rule B: new target must be chronologically after all existing targets
            if ($dateRange && $dateRange->latest && $validated['planned_date'] < $dateRange->latest) {
                return response()->json([
                    'success' => false,
                    'message' => 'New target must be chronologically after the latest existing target ('.$dateRange->latest.').',
                ], 422);
            }

            // Rule C: new target must not be before the project's first baseline date
            if ($dateRange && $dateRange->earliest && $validated['planned_date'] < $dateRange->earliest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target date cannot be before the project\'s first baseline target ('.$dateRange->earliest.').',
                ], 422);
            }

            // NEW: extension awareness flag — drives both the validation branch below
            // and whether original_planned_progress_percent gets written
            $isExtended = $project->extension_count > 0;

            $previousMax = ProjectBaselineSchedule::where('project_id', $project->id)
                ->max('planned_progress_percent') ?? 0;

            if (! $isExtended) {
                // ORIGINAL behavior: strict 100%-cap, used before any extension exists
                if ($previousMax < 100 && $validated['planned_progress_percent'] <= $previousMax) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Target progress % must be higher than the previous month ('.$previousMax.'%). You entered: '.$validated['planned_progress_percent'].'%',
                    ], 422);
                } elseif ($previousMax === 100) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Previous month has already reached 100%. No additional targets can be added without an approved extension.',
                    ], 422);
                }
            } else {
                // NEW: EXTENSION-AWARE behavior — the 100%-cap from the original plan no
                // longer blocks new rows. Instead, strictly-increasing is enforced only
                // against OTHER post-extension rows (original_planned_progress_percent IS NULL),
                // so the extension period gets its own increasing sequence.
                $previousMaxExtensionOnly = ProjectBaselineSchedule::where('project_id', $project->id)
                    ->whereNull('original_planned_progress_percent')
                    ->max('planned_progress_percent') ?? 0;

                if ($validated['planned_progress_percent'] <= $previousMaxExtensionOnly) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Target progress % must be higher than the previous extension-period month ('.$previousMaxExtensionOnly.'%). You entered: '.$validated['planned_progress_percent'].'%',
                    ], 422);
                }
            }

            // Use transaction to ensure data consistency
            DB::beginTransaction();

            try {
                // NEW: conditionally write original_planned_progress_percent.
                // Initial-setup rows (no extension yet) freeze both columns identically.
                // Post-extension rows write ONLY the live planned column; original stays
                // NULL since these months were never part of the originally approved plan.
                $scheduleData = [
                    'project_id' => $project->id,
                    'planned_date' => $validated['planned_date'],
                    'month_label' => $validated['month_label'],
                    'planned_progress_percent' => $validated['planned_progress_percent'],
                    'description' => $validated['description'],
                ];

                if (! $isExtended) {
                    $scheduleData['original_planned_progress_percent'] = $validated['planned_progress_percent'];
                    $scheduleData['original_planned_date'] = $validated['planned_date'];
                }

                // Create new Baseline Schedule entry
                $schedule = ProjectBaselineSchedule::create($scheduleData);

                // Create associated activities if provided
                if (! empty($validated['activities'])) {
                    $activitiesToCreate = [];
                    foreach ($validated['activities'] as $activity) {
                        $activitiesToCreate[] = [
                            'project_id' => $project->id,
                            'baseline_schedule_id' => $schedule->id,
                            'month_label' => $validated['month_label'],
                            'activity_name' => $activity['activity_name'],
                            'description' => $activity['description'] ?? '',
                            'is_completed' => false,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    if (! empty($activitiesToCreate)) {
                        ProjectActivity::insert($activitiesToCreate);
                    }
                }

                ActivityLog::create([
                    'user_id' => auth()->id(),
                    'project_id' => $project->id,
                    'action' => 'Create Baseline Schedule',
                    'description' => auth()->user()->name." set {$validated['month_label']} target ({$validated['planned_progress_percent']}%) for project: {$project->title} (baseline snapshot updated)",
                    'ip_address' => request()->ip(),
                    'visibility' => 'all',
                ]);

                DB::commit();

                // Extension-management Phase 2.5: keep the approved extension's
                // baseline snapshot in sync with any manual store so a later
                // revert restores the exact state the user built (Add Month Target).
                \App\Http\Controllers\Admin\ProjectExtensionController::captureBaselineSnapshot($project);

                Cache::forget('ai_prediction:' . $project->id);

                return response()->json([
                    'success' => true,
                    'message' => 'S-Curve plan and activities added successfully',
                    'plan' => $schedule,
                    'activities_count' => count($validated['activities'] ?? []),
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error saving S-Curve plan: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store the complete initial baseline schedule in one atomic operation.
     *
     * Called ONLY from the "Set Up Baseline" modal via POST .../baseline-schedules/setup.
     * Accepts the full plan (all months + activities) built in the modal's local memory,
     * validates it as a whole, then saves everything in a single DB transaction.
     *
     * DESIGN: nothing is written to the DB until this endpoint is called —
     * the modal holds months in browser memory until "Done — Lock Baseline" is clicked.
     * This prevents a partial (< 100%) baseline from ever landing in the database.
     */
    public function storeInitialBaselineSetup(Request $request, Project $project)
    {
        $this->authorizeProjectAccess(auth()->user(), $project);

        try {
            // ── Guard: first-time setup only ──────────────────────────────────────
            // If baseline rows already exist, this endpoint must not run again.
            // (A future "reset baseline" flow could handle that case separately.)
            if (ProjectBaselineSchedule::where('project_id', $project->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This project already has a baseline schedule. Use "Add Month Target" instead.',
                ], 422);
            }

            // ── Validate incoming payload ─────────────────────────────────────────
            $validated = $request->validate([
                'months' => 'required|array|min:1',
                'months.*.month_label' => 'required|string|regex:/^\d{4}-\d{2}(-\d{2})?/',
                'months.*.planned_date' => 'required|date',
                'months.*.planned_progress_percent' => 'required|numeric|between:0,100',
                'months.*.description' => 'nullable|string',
                // Activities per month are optional; if provided, name is required per row
                'months.*.activities' => 'nullable|array',
                'months.*.activities.*.activity_name' => 'required_with:months.*.activities|string|max:255',
                'months.*.activities.*.description' => 'nullable|string',
            ]);

            $months = $validated['months'];

            // ── Sort chronologically ──────────────────────────────────────────────
            // Ensures the increasing-% check and insert order follow the actual
            // timeline regardless of the order months were added in the modal.
            usort($months, fn ($a, $b) => strcmp($a['planned_date'], $b['planned_date']));

            // ── Reject duplicate month_labels ─────────────────────────────────────
            $monthLabels = array_column($months, 'month_label');
            if (count($monthLabels) !== count(array_unique($monthLabels))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate months detected in the submitted plan.',
                ], 422);
            }

            // ── Bound months to the project timeline ──────────────────────────────
            // The baseline IS the plan, so every target must fall within the
            // project's planned start/end dates. Uses the CURRENT (post-extension)
            // planned_end_date so extension-generated months stay valid.
            $projectStart = $project->planned_start_date;
            $projectEnd = $project->planned_end_date;

            if (! $projectStart || ! $projectEnd) {
                return response()->json([
                    'success' => false,
                    'message' => 'Set the project planned start and end dates before locking a baseline.',
                ], 422);
            }

            foreach ($months as $month) {
                $plannedDate = \Carbon\Carbon::parse($month['planned_date']);
                if ($plannedDate->lt($projectStart)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Target date {$month['planned_date']} is before the project's planned start date ({$projectStart->format('Y-m-d')}).",
                    ], 422);
                }
                if ($plannedDate->gt($projectEnd)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Target date {$month['planned_date']} is after the project's planned end date ({$projectEnd->format('Y-m-d')}).",
                    ], 422);
                }
            }

            // ── Enforce strictly increasing progress ──────────────────────────────
            $previous = 0;
            foreach ($months as $month) {
                if ($month['planned_progress_percent'] <= $previous) {
                    return response()->json([
                        'success' => false,
                        'message' => "Progress % must strictly increase each month. \"{$month['month_label']}\" ({$month['planned_progress_percent']}%) is not higher than the previous month ({$previous}%).",
                    ], 422);
                }
                $previous = $month['planned_progress_percent'];
            }

            // ── Plan must reach 100% ──────────────────────────────────────────────
            // The final month must be 100% — this is what makes the plan complete
            // and lockable as the frozen original baseline.
            $lastMonth = end($months);
            if ((float) $lastMonth['planned_progress_percent'] < 100.0) {
                return response()->json([
                    'success' => false,
                    'message' => 'The final month must reach 100% before the baseline can be locked.',
                ], 422);
            }

            // ── Save everything atomically ────────────────────────────────────────
            // All months + activities save together or none do.
            // Uses create() per row (not bulk insert()) so each schedule row's
            // generated ID is available for attaching its activities.
            DB::beginTransaction();

            try {
                $now = now();
                $totalActivities = 0;

                foreach ($months as $month) {
                    // Create the baseline schedule row — writes BOTH progress columns:
                    //   planned_progress_percent          → the live/adjustable value
                    //   original_planned_progress_percent → frozen forever as the original plan
                    $schedule = ProjectBaselineSchedule::create([
                        'project_id' => $project->id,
                        'planned_date' => $month['planned_date'],
                        'month_label' => $month['month_label'],
                        'planned_progress_percent' => $month['planned_progress_percent'],
                        'original_planned_progress_percent' => $month['planned_progress_percent'],
                        'original_planned_date' => $month['planned_date'],
                        'description' => $month['description'] ?? null,
                        'created_at' => $now,
                    ]);

                    // Insert this month's activities if any were provided
                    if (! empty($month['activities'])) {
                        $activitiesToCreate = [];

                        foreach ($month['activities'] as $activity) {
                            $activitiesToCreate[] = [
                                'project_id' => $project->id,
                                'baseline_schedule_id' => $schedule->id,  // ID from the create() above
                                'month_label' => $month['month_label'],
                                'activity_name' => $activity['activity_name'],
                                'description' => $activity['description'] ?? '',
                                'is_completed' => false,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }

                        ProjectActivity::insert($activitiesToCreate);
                        $totalActivities += count($activitiesToCreate);
                    }
                }

                // ── Activity log ──────────────────────────────────────────────────
                ActivityLog::create([
                    'user_id' => auth()->id(),
                    'project_id' => $project->id,
                    'action' => 'Set Up Baseline Schedule',
                    'description' => auth()->user()->name
                        .' set up the initial baseline schedule ('
                        .count($months).' months, '
                        .$totalActivities.' activities) for project: '
                        .$project->title,
                    'ip_address' => request()->ip(),
                    'visibility' => 'all',
                ]);

                DB::commit();

                Cache::forget('ai_prediction:' . $project->id);

                return response()->json([
                    'success' => true,
                    'message' => 'Baseline schedule locked successfully',
                    'months_created' => count($months),
                    'activities_created' => $totalActivities,
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error in storeInitialBaselineSetup', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error saving baseline: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * NEW: Update (edit) an existing baseline schedule row.
     * Only allowed for post-extension rows that have no actual progress recorded yet.
     * Never writes original_planned_progress_percent — it stays frozen/NULL.
     */
    public function updateBaselineSchedule(Request $request, Project $project, $scheduleId)
    {
        $this->authorizeProjectAccess(auth()->user(), $project);

        try {
            $validated = $request->validate([
                'planned_date' => 'required|date',
                'planned_progress_percent' => 'required|numeric|between:0,100',
                'description' => 'nullable|string',
            ]);

            $schedule = ProjectBaselineSchedule::where('project_id', $project->id)
                ->where('id', $scheduleId)
                ->firstOrFail();

            // GUARD 1: editing only allowed once the project has an approved extension
            if ($project->extension_count <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Editing baseline targets is only allowed after a project extension has been approved',
                ], 422);
            }

            // GUARD 2: editing blocked once actual progress has been recorded for this month
            if ($schedule->progressHistory()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This month already has recorded progress and can no longer be edited',
                ], 422);
            }

            // Rule A (edit): edited date must still fall within the schedule's locked month
            $dateMonth = substr($validated['planned_date'], 0, 7);
            if ($dateMonth !== $schedule->month_label) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target date must fall within '.$schedule->month_label,
                ], 422);
            }

            // Ordering lock: the edited target must stay strictly between its
            // chronological neighbors across the WHOLE timeline (original AND
            // extension rows), using the live planned_progress_percent values.
            // max()/min() return null when there is no neighbor, so the first
            // month only has an upper bound and the last month only a lower one.
            $previousMax = ProjectBaselineSchedule::where('project_id', $project->id)
                ->where('id', '!=', $schedule->id)
                ->where('planned_date', '<', $validated['planned_date'])
                ->max('planned_progress_percent');
            $nextMin = ProjectBaselineSchedule::where('project_id', $project->id)
                ->where('id', '!=', $schedule->id)
                ->where('planned_date', '>', $validated['planned_date'])
                ->min('planned_progress_percent');

            $newPercent = (float) $validated['planned_progress_percent'];

            if ($previousMax !== null && $newPercent <= (float) $previousMax) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target progress % must be higher than the previous month ('.$previousMax.'%). You entered: '.$newPercent.'%',
                ], 422);
            }

            if ($nextMin !== null && $newPercent >= (float) $nextMin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target progress % must be lower than the next month ('.$nextMin.'%). You entered: '.$newPercent.'%',
                ], 422);
            }

            // NOTE: original_planned_progress_percent is intentionally never touched here
            $schedule->update([
                'planned_date' => $validated['planned_date'],
                'planned_progress_percent' => $validated['planned_progress_percent'],
                'description' => $validated['description'] ?? $schedule->description,
            ]);

            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => 'Update Baseline Schedule',
                'description' => auth()->user()->name." updated {$schedule->month_label} target to {$validated['planned_progress_percent']}% for project: {$project->title} (baseline snapshot updated)",
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);

            // Extension-management Phase 2.5: keep the approved extension's
            // baseline snapshot in sync with manual edits (Manage Month modal)
            // so a later revert restores the exact state the user built.
            \App\Http\Controllers\Admin\ProjectExtensionController::captureBaselineSnapshot($project);

            Cache::forget('ai_prediction:' . $project->id);

            return response()->json([
                'success' => true,
                'message' => 'Baseline schedule updated successfully',
                'plan' => $schedule->fresh(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error: '.collect($e->errors())->flatten()->first(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating baseline schedule: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Carry an incomplete activity over to a future baseline schedule month.
     *
     * Creates an immutable ProjectActivityCarryover log record and updates the
     * activity's baseline_schedule_id and month_label to the new target.
     * The carryover_number chain tracks how many times this activity has been
     * carried over (1st, 2nd, 3rd...).
     *
     * Called by: POST /{role}/projects/{project}/activities/{activity}/carry-over
     * Calls: ProjectActivityCarryover::create(), ProjectActivity::update()
     */
    public function carryOverActivity(Request $request, Project $project, ProjectActivity $activity)
    {
        $this->authorizeProjectAccess(auth()->user(), $project);

        try {
            // Validate the activity belongs to this project
            if ($activity->project_id !== $project->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Activity does not belong to this project',
                ], 422);
            }

            // Guard: cannot carry over a completed activity
            if ($activity->is_completed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot carry over a completed activity',
                ], 422);
            }

            $validated = $request->validate([
                'to_baseline_schedule_id' => 'required|integer|exists:project_baseline_schedules,id',
            ]);

            // Find the target baseline schedule
            $targetSchedule = ProjectBaselineSchedule::where('id', $validated['to_baseline_schedule_id'])
                ->where('project_id', $project->id)
                ->first();

            if (! $targetSchedule) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target baseline schedule not found for this project',
                ], 422);
            }

            // Guard: target month must be chronologically AFTER the current month
            if ($targetSchedule->planned_date <= $activity->baselineSchedule->planned_date) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target month must be after the activity\'s current month',
                ], 422);
            }

            // Guard: prevent duplicate carryover to the same target month
            $alreadyCarriedHere = $activity->carryovers()
                ->where('to_baseline_schedule_id', $targetSchedule->id)
                ->exists();

            if ($alreadyCarriedHere) {
                return response()->json([
                    'success' => false,
                    'message' => 'This activity has already been carried over to '.$targetSchedule->month_label,
                ], 422);
            }

            // Execute the carryover atomically
            DB::beginTransaction();

            try {
                // Calculate the next carryover number in the chain
                $nextNumber = $activity->carryovers()->count() + 1;

                // Create the immutable carryover log record
                ProjectActivityCarryover::create([
                    'activity_id' => $activity->id,
                    'project_id' => $project->id,
                    'from_baseline_schedule_id' => $activity->baseline_schedule_id,
                    'from_month_label' => $activity->month_label,
                    'to_baseline_schedule_id' => $targetSchedule->id,
                    'to_month_label' => $targetSchedule->month_label,
                    'carryover_number' => $nextNumber,
                    'carried_over_by' => auth()->id(),
                ]);

                // Update the activity to point to the new month
                $activity->update([
                    'baseline_schedule_id' => $targetSchedule->id,
                    'month_label' => $targetSchedule->month_label,
                ]);

                ActivityLog::create([
                    'user_id' => auth()->id(),
                    'project_id' => $project->id,
                    'action' => 'Carry Over Activity',
                    'description' => auth()->user()->name
                        ." carried over activity \"{$activity->activity_name}\" from {$activity->month_label} to {$targetSchedule->month_label} (carryover #{$nextNumber}) for project: {$project->title}",
                    'ip_address' => request()->ip(),
                    'visibility' => 'all',
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => "Activity \"{$activity->activity_name}\" carried over to {$targetSchedule->month_label}",
                    'activity' => $activity->fresh()->load('carryovers'),
                    'carryover' => [
                        'from' => $activity->month_label,
                        'to' => $targetSchedule->month_label,
                        'number' => $nextNumber,
                    ],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error: '.collect($e->errors())->flatten()->first(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error in carryOverActivity', [
                'project_id' => $project->id,
                'activity_id' => $activity->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error carrying over activity: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Return all baseline months for the adjustment modal.
     * Includes has_actual_progress flag, original_planned_progress_percent,
     * and the project's planned_end_date so the frontend knows where to
     * generate extension months up to.
     */
    public function getBaselineMonthsForAdjustment(Project $project)
    {
        try {
            $months = ProjectBaselineSchedule::where('project_id', $project->id)
                ->orderBy('planned_date', 'asc')
                ->get()
                ->map(function ($schedule) {
                    return [
                        'id' => $schedule->id,
                        'month_label' => $schedule->month_label,
                        'planned_date' => $schedule->planned_date->format('Y-m-d'),
                        'planned_progress_percent' => $schedule->planned_progress_percent,
                        'original_planned_progress_percent' => $schedule->original_planned_progress_percent,
                        'description' => $schedule->description ?? '',
                        'has_actual_progress' => $schedule->progressHistory()->exists(),
                        'activities' => $schedule->activities()
                            ->orderBy('id', 'asc')
                            ->get(['id', 'activity_name', 'description'])
                            ->map(fn ($activity) => [
                                'activity_name' => $activity->activity_name,
                                'description' => $activity->description ?? '',
                            ])
                            ->values(),
                    ];
                });

            return response()->json([
                'success' => true,
                'months' => $months,
                'planned_end_date' => $project->planned_end_date?->format('Y-m-d'),
                'extension_count' => $project->extension_count,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching baseline months: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk-save the adjusted baseline after the user reviews and edits
     * the auto-distributed months in the modal.
     *
     * In one DB transaction:
     *   • UPDATE existing months (planned_progress_percent, planned_date,
     *     description only — never original_planned_progress_percent).
     *   • CREATE new extension months (original_planned_progress_percent = NULL).
     *   • DELETE months the user removed (only if no actual progress recorded).
     *
     * Months with actual progress are IMMUTABLE — the server ignores any
     * submitted changes to them (defense-in-depth beyond the UI lock).
     */
    public function saveAdjustedBaseline(Request $request, Project $project)
    {
        $this->authorizeProjectAccess(auth()->user(), $project);

        try {
            $validated = $request->validate([
                'months' => 'required|array',
                'months.*.id' => 'nullable|integer',
                'months.*.month_label' => 'required|string|regex:/^\d{4}-\d{2}$/',
                'months.*.planned_date' => 'required|date',
                'months.*.planned_progress_percent' => 'required|numeric|between:0,100',
                'months.*.description' => 'nullable|string',
                'months.*.activities' => 'nullable|array',
                'months.*.activities.*.id' => 'nullable|integer',
                'months.*.activities.*.activity_name' => 'required_with:months.*.activities|string|max:255',
                'months.*.activities.*.description' => 'nullable|string',
                'removed_ids' => 'nullable|array',
                'removed_ids.*' => 'integer|exists:project_baseline_schedules,id',
            ]);

            DB::beginTransaction();

            // ── 1. DELETE months the user removed (only if no actual progress) ──
            if (! empty($validated['removed_ids'])) {
                foreach ($validated['removed_ids'] as $removeId) {
                    $schedule = ProjectBaselineSchedule::where('project_id', $project->id)
                        ->where('id', $removeId)
                        ->first();

                    if (! $schedule) {
                        continue;
                    }

                    // Guard: cannot delete a month with actual progress
                    if ($schedule->progressHistory()->exists()) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'Cannot remove "'.$schedule->month_label.'" — it already has recorded progress.',
                        ], 422);
                    }

                    // Cascade delete (same order as deleteBaselineSchedule)
                    ProjectProgressHistory::where('project_id', $project->id)
                        ->where('baseline_schedule_id', $schedule->id)
                        ->delete();
                    ProjectActivity::where('project_id', $project->id)
                        ->where('baseline_schedule_id', $schedule->id)
                        ->delete();
                    ProjectActivityCarryover::where('project_id', $project->id)
                        ->where(function ($q) use ($schedule) {
                            $q->where('from_baseline_schedule_id', $schedule->id)
                                ->orWhere('to_baseline_schedule_id', $schedule->id);
                        })
                        ->delete();
                    $schedule->delete();
                }
            }

            // ── 2. UPSERT each month ────────────────────────────────────────────
            $submittedIds = [];

            foreach ($validated['months'] as $monthData) {
                $scheduleId = $monthData['id'] ?? null;

                if ($scheduleId) {
                    // ── EXISTING month — UPDATE ──────────────────────────────────
                    $schedule = ProjectBaselineSchedule::where('project_id', $project->id)
                        ->where('id', $scheduleId)
                        ->first();

                    if (! $schedule) {
                        continue;
                    }

                    // Guard: months with actual progress are immutable
                    if ($schedule->progressHistory()->exists()) {
                        // Track the ID but skip update
                        $submittedIds[] = $schedule->id;

                        continue;
                    }

                    // Rule A: date must fall within the selected month
                    $dateMonth = substr($monthData['planned_date'], 0, 7);
                    if ($dateMonth !== $schedule->month_label) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'Target date for "'.$schedule->month_label.'" must fall within that month.',
                        ], 422);
                    }

                    $schedule->update([
                        'planned_date' => $monthData['planned_date'],
                        'planned_progress_percent' => $monthData['planned_progress_percent'],
                        'description' => $monthData['description'] ?? '',
                        // NOTE: original_planned_progress_percent is INTENTIONALLY
                        // never updated here — it stays frozen as the original plan.
                    ]);

                    // Sync activities — diff-based upsert that PRESERVES activity
                    // ids and is_completed. Matched rows are updated in place;
                    // only rows the user actually removed are deleted (their
                    // carryover logs cascade away, matching remove-month semantics).
                    if (! empty($monthData['activities'])) {
                        $existingActivities = ProjectActivity::where('project_id', $project->id)
                            ->where('baseline_schedule_id', $schedule->id)
                            ->get();

                        $keptActivityIds = [];

                        foreach ($monthData['activities'] as $act) {
                            if (empty($act['activity_name'])) {
                                continue;
                            }

                            // Prefer an explicit id round-tripped from the modal;
                            // fall back to a name match for legacy/new rows.
                            $match = null;
                            if (! empty($act['id'])) {
                                $match = $existingActivities->firstWhere('id', (int) $act['id']);
                            }
                            if (! $match) {
                                $match = $existingActivities->firstWhere('activity_name', $act['activity_name']);
                            }

                            if ($match) {
                                $match->update([
                                    'activity_name' => $act['activity_name'],
                                    'description' => $act['description'] ?? '',
                                    'planned_date' => $monthData['planned_date'],
                                ]);
                                $keptActivityIds[] = $match->id;
                            } else {
                                $newActivity = ProjectActivity::create([
                                    'project_id' => $project->id,
                                    'baseline_schedule_id' => $schedule->id,
                                    'month_label' => $schedule->month_label,
                                    'planned_date' => $monthData['planned_date'],
                                    'activity_name' => $act['activity_name'],
                                    'description' => $act['description'] ?? '',
                                    'is_completed' => false,
                                ]);
                                $keptActivityIds[] = $newActivity->id;
                            }
                        }

                        // Delete existing rows the user removed from the month.
                        ProjectActivity::where('project_id', $project->id)
                            ->where('baseline_schedule_id', $schedule->id)
                            ->whereNotIn('id', $keptActivityIds)
                            ->delete();
                    }

                    $submittedIds[] = $schedule->id;

                } else {
                    // ── NEW extension month — CREATE ────────────────────────────
                    // Duplicate check
                    $exists = ProjectBaselineSchedule::where('project_id', $project->id)
                        ->where('planned_date', $monthData['planned_date'])
                        ->exists();
                    if ($exists) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'A target with date '.$monthData['planned_date'].' already exists.',
                        ], 422);
                    }

                    // Rule A: date must fall within selected month
                    $dateMonth = substr($monthData['planned_date'], 0, 7);
                    if ($dateMonth !== $monthData['month_label']) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'Target date must fall within '.$monthData['month_label'].'.',
                        ], 422);
                    }

                    $schedule = ProjectBaselineSchedule::create([
                        'project_id' => $project->id,
                        'month_label' => $monthData['month_label'],
                        'planned_date' => $monthData['planned_date'],
                        'planned_progress_percent' => $monthData['planned_progress_percent'],
                        'original_planned_progress_percent' => null,
                        'description' => $monthData['description'] ?? '',
                    ]);

                    // Create activities
                    if (! empty($monthData['activities'])) {
                        foreach ($monthData['activities'] as $act) {
                            if (! empty($act['activity_name'])) {
                                ProjectActivity::create([
                                    'project_id' => $project->id,
                                    'baseline_schedule_id' => $schedule->id,
                                    'month_label' => $schedule->month_label,
                                    'planned_date' => $monthData['planned_date'],
                                    'activity_name' => $act['activity_name'],
                                    'description' => $act['description'] ?? '',
                                    'is_completed' => false,
                                ]);
                            }
                        }
                    }

                    $submittedIds[] = $schedule->id;
                }
            }

            // ── 3. Validate chronological ordering + strictly increasing % ──────
            $allMonths = ProjectBaselineSchedule::where('project_id', $project->id)
                ->whereIn('id', $submittedIds)
                ->orderBy('planned_date', 'asc')
                ->get();

            $prevPercent = -1;
            foreach ($allMonths as $m) {
                if ($m->planned_progress_percent <= $prevPercent) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Progress % must be strictly increasing. "'.$m->month_label.'" ('.$m->planned_progress_percent.'%) is not higher than the previous month ('.$prevPercent.'%).',
                    ], 422);
                }
                $prevPercent = $m->planned_progress_percent;
            }

            // Final month must reach 100%
            $lastMonth = $allMonths->last();
            if ($lastMonth && $lastMonth->planned_progress_percent < 100) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'The last month "'.$lastMonth->month_label.'" must reach 100%. Currently at '.$lastMonth->planned_progress_percent.'%.',
                ], 422);
            }

            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => 'Adjust Baseline',
                'description' => auth()->user()->name
                    ." adjusted the baseline schedule for project: {$project->title}",
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);

            DB::commit();

            // Extension-management Phase 2.5: after a successful adjusted-
            // baseline save, snapshot the whole baseline state onto the latest
            // approved extension as a restore point (used when reverting that
            // extension). See ProjectExtensionController::captureBaselineSnapshot.
            \App\Http\Controllers\Admin\ProjectExtensionController::captureBaselineSnapshot($project);

            return response()->json([
                'success' => true,
                'message' => 'Adjusted baseline saved successfully!',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error: '.collect($e->errors())->flatten()->first(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saving adjusted baseline', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error saving adjusted baseline: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if the user can edit the original baseline of the project.
     * Admins always have access; staff only when assigned to the project.
     */
    private function canEditOriginalBaseline(User $user, Project $project): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $project->isAssignedTo($user);
    }

    /**
     * Require admin or assigned staff for write operations on a project.
     * Aborts with 403 if the user is not authorized.
     */
    private function authorizeProjectAccess(User $user, Project $project): void
    {
        if ($user->role === 'admin') return;

        if (!$project->isAssignedTo($user)) {
            abort(403, 'You are not assigned to this project.');
        }
    }

    /**
     * Return ONLY original-baseline months for the admin "Edit Original
     * Baseline" mode of the Set Up Baseline modal.
     *
     * Original-baseline months are rows where BOTH frozen columns are set
     * (original_planned_progress_percent AND original_planned_date NOT NULL) —
     * they were part of the originally locked plan. Extension-only rows are
     * excluded: they are managed via the Generate Adjusted Baseline flow.
     */
    public function getOriginalBaselineMonths(Project $project)
    {
        if (! $this->canEditOriginalBaseline(auth()->user(), $project)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins or assigned staff can edit the original baseline.',
            ], 403);
        }

        try {
            $months = ProjectBaselineSchedule::where('project_id', $project->id)
                ->whereNotNull('original_planned_progress_percent')
                ->whereNotNull('original_planned_date')
                ->orderBy('planned_date', 'asc')
                ->get()
                ->map(function ($schedule) {
                    return [
                        'id'                            => $schedule->id,
                        'month_label'                   => $schedule->month_label,
                        'planned_date'                  => $schedule->planned_date->format('Y-m-d'),
                        'planned_progress_percent'      => $schedule->planned_progress_percent,
                        'original_planned_progress_percent' => $schedule->original_planned_progress_percent,
                        'original_planned_date'         => $schedule->original_planned_date?->format('Y-m-d'),
                        'description'                   => $schedule->description ?? '',
                        'has_actual_progress'           => $schedule->progressHistory()->exists(),
                        'activities'                    => $schedule->activities()
                            ->orderBy('id', 'asc')
                            ->get(['id', 'activity_name', 'description'])
                            ->map(fn ($activity) => [
                                'id'            => $activity->id,
                                'activity_name' => $activity->activity_name,
                                'description'   => $activity->description ?? '',
                            ])
                            ->values(),
                    ];
                });

            return response()->json([
                'success' => true,
                'months' => $months,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching original baseline months: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin/assigned-staff: bulk-save an edited ORIGINAL baseline.
     *
     * This is the correction path for the locked original plan. Unlike the
     * adjust-baseline flow it:
     *   • operates ONLY on original-baseline months (both frozen columns
     *     NOT NULL) and their activities — extension months are untouched;
     *   • updates BOTH the live (planned_date / planned_progress_percent) AND
     *     the frozen (original_planned_date / original_planned_progress_percent)
     *     columns, so a corrected original plan stays internally consistent;
     *   • locks months that already have recorded actual progress (their
     *     actuals feed the S-curve / AI trend and must not be rewritten).
     *
     * In one DB transaction: DELETE removed original months (guarded against
     * progress), diff-based UPSERT the submitted original months + activities
     * (preserving activity identity / is_completed), then re-validate the
     * whole original sequence (strictly increasing %, final original month = 100%).
     */
    public function saveEditedOriginalBaseline(Request $request, Project $project)
    {
        if (! $this->canEditOriginalBaseline(auth()->user(), $project)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins or assigned staff can edit the original baseline.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'months' => 'required|array',
                'months.*.id' => 'nullable|integer',
                'months.*.month_label' => 'required|string|regex:/^\d{4}-\d{2}$/',
                'months.*.planned_date' => 'required|date',
                'months.*.planned_progress_percent' => 'required|numeric|between:0,100',
                'months.*.description' => 'nullable|string',
                'months.*.activities' => 'nullable|array',
                'months.*.activities.*.id' => 'nullable|integer',
                'months.*.activities.*.activity_name' => 'required_with:months.*.activities|string|max:255',
                'months.*.activities.*.description' => 'nullable|string',
                'removed_ids' => 'nullable|array',
                'removed_ids.*' => 'integer|exists:project_baseline_schedules,id',
            ]);

            $projectStart = $project->planned_start_date;
            $projectEnd = $project->planned_end_date;

            if (! $projectStart || ! $projectEnd) {
                return response()->json([
                    'success' => false,
                    'message' => 'Set the project planned start and end dates before editing the baseline.',
                ], 422);
            }

            // Capture before-snapshot for diff logging (admin audit trail)
            $beforeMonths = ProjectBaselineSchedule::where('project_id', $project->id)
                ->whereNotNull('original_planned_progress_percent')
                ->orderBy('original_planned_date', 'asc')
                ->get(['id', 'month_label', 'planned_date', 'planned_progress_percent',
                    'original_planned_date', 'original_planned_progress_percent', 'description'])
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'month_label' => $s->month_label,
                    'planned_date' => $s->planned_date?->format('Y-m-d'),
                    'planned_progress_percent' => $s->planned_progress_percent,
                    'original_planned_date' => $s->original_planned_date?->format('Y-m-d'),
                    'original_planned_progress_percent' => $s->original_planned_progress_percent,
                    'description' => $s->description,
                ])
                ->values()
                ->toArray();

            DB::beginTransaction();

            // ── 1. DELETE months the admin removed (original rows only, no progress) ──
            if (! empty($validated['removed_ids'])) {
                foreach ($validated['removed_ids'] as $removeId) {
                    $schedule = ProjectBaselineSchedule::where('project_id', $project->id)
                        ->where('id', $removeId)
                        ->first();

                    if (! $schedule) {
                        continue;
                    }

                    // Only original-baseline months are in scope for this endpoint
                    if ($schedule->original_planned_progress_percent === null || $schedule->original_planned_date === null) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'Only original baseline months can be removed here. Extension months are managed via Generate Adjusted Baseline.',
                        ], 422);
                    }

                    // Guard: cannot delete a month with actual progress
                    if ($schedule->progressHistory()->exists()) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'Cannot remove "'.$schedule->month_label.'" — it already has recorded progress.',
                        ], 422);
                    }

                    // Cascade delete (same order as deleteBaselineSchedule)
                    ProjectProgressHistory::where('project_id', $project->id)
                        ->where('baseline_schedule_id', $schedule->id)
                        ->delete();
                    ProjectActivity::where('project_id', $project->id)
                        ->where('baseline_schedule_id', $schedule->id)
                        ->delete();
                    ProjectActivityCarryover::where('project_id', $project->id)
                        ->where(function ($q) use ($schedule) {
                            $q->where('from_baseline_schedule_id', $schedule->id)
                                ->orWhere('to_baseline_schedule_id', $schedule->id);
                        })
                        ->delete();
                    $schedule->delete();
                    $changed = true;
                }
            }

            // ── 2. UPSERT each submitted original month ────────────────────────────
            $submittedIds = [];

            // Tracks whether ANY row was actually changed. When the admin opens
            // the modal and saves without editing anything, we short-circuit so
            // no DB writes, ActivityLog, or extension snapshot are produced.
            $changed = false;

            foreach ($validated['months'] as $monthData) {
                $scheduleId = $monthData['id'] ?? null;

                // Timeline bounds: the baseline IS the plan
                $plannedDate = \Carbon\Carbon::parse($monthData['planned_date']);
                if ($plannedDate->lt($projectStart)) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => "Target date {$monthData['planned_date']} is before the project's planned start date ({$projectStart->format('Y-m-d')}).",
                    ], 422);
                }
                if ($plannedDate->gt($projectEnd)) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => "Target date {$monthData['planned_date']} is after the project's planned end date ({$projectEnd->format('Y-m-d')}).",
                    ], 422);
                }

                // Rule A: date must fall within the selected month
                $dateMonth = substr($monthData['planned_date'], 0, 7);
                if ($dateMonth !== $monthData['month_label']) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Target date must fall within '.$monthData['month_label'].'.',
                    ], 422);
                }

                if ($scheduleId) {
                    // ── EXISTING original month — UPDATE ────────────────────────────
                    $schedule = ProjectBaselineSchedule::where('project_id', $project->id)
                        ->where('id', $scheduleId)
                        ->first();

                    if (! $schedule) {
                        continue;
                    }

                    // Only original-baseline months are in scope
                    if ($schedule->original_planned_progress_percent === null || $schedule->original_planned_date === null) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => '"'.$schedule->month_label.'" is not an original baseline month and cannot be edited here.',
                        ], 422);
                    }

                    // Guard: months with actual progress are immutable
                    if ($schedule->progressHistory()->exists()) {
                        $submittedIds[] = $schedule->id;
                        continue;
                    }

                    // Compute submitted vs. DB state. In this mode the payload's
                    // planned_* fields carry the FROZEN original values (the modal
                    // seeds from original_planned_*), so diff them against the DB's
                    // original_* columns.
                    $submittedDate = \Carbon\Carbon::parse($monthData['planned_date'])->format('Y-m-d');
                    $submittedPct = (float) $monthData['planned_progress_percent'];
                    $submittedDesc = trim((string) ($monthData['description'] ?? ''));

                    $currentOriginalDate = $schedule->original_planned_date?->format('Y-m-d');
                    $currentOriginalPct = (float) $schedule->original_planned_progress_percent;
                    $currentLiveDate = $schedule->planned_date?->format('Y-m-d');
                    $currentLivePct = (float) $schedule->planned_progress_percent;

                    // A row is "diverged" when the adjusted baseline redistributed
                    // the live curve away from the frozen original (date or percent).
                    // For diverged rows we ONLY correct the frozen original and leave
                    // the live/adjusted curve untouched, so the adjusted baseline
                    // doesn't have to be regenerated after this edit.
                    $diverged = $currentLivePct !== $currentOriginalPct || $currentLiveDate !== $currentOriginalDate;

                    // Diff: skip the row entirely when the frozen original is unchanged.
                    if ($submittedPct === $currentOriginalPct
                        && $submittedDate === $currentOriginalDate
                        && $submittedDesc === trim((string) $schedule->description)) {
                        $submittedIds[] = $schedule->id;
                        continue;
                    }

                    // Correct the frozen original columns — and, only when the live
                    // columns still mirror the original plan (not diverged), update
                    // them together so the plan stays in lockstep.
                    $updateData = [
                        'original_planned_date' => $submittedDate,
                        'original_planned_progress_percent' => $submittedPct,
                        'description' => $submittedDesc,
                    ];

                    if (! $diverged) {
                        $updateData['planned_date'] = $submittedDate;
                        $updateData['planned_progress_percent'] = $submittedPct;
                    }

                    $schedule->update($updateData);
                    $changed = true;

                    // Sync activities — diff-based upsert preserving identity and
                    // is_completed (same strategy as saveAdjustedBaseline). Activity
                    // dates follow the schedule's LIVE date so diverged rows keep
                    // their adjusted placement.
                    if (! empty($monthData['activities'])) {
                        $existingActivities = ProjectActivity::where('project_id', $project->id)
                            ->where('baseline_schedule_id', $schedule->id)
                            ->get();

                        $keptActivityIds = [];
                        $liveDate = $schedule->planned_date->format('Y-m-d');

                        foreach ($monthData['activities'] as $act) {
                            if (empty($act['activity_name'])) {
                                continue;
                            }

                            $match = null;
                            if (! empty($act['id'])) {
                                $match = $existingActivities->firstWhere('id', (int) $act['id']);
                            }
                            if (! $match) {
                                $match = $existingActivities->firstWhere('activity_name', $act['activity_name']);
                            }

                            if ($match) {
                                $match->update([
                                    'activity_name' => $act['activity_name'],
                                    'description' => $act['description'] ?? '',
                                    'planned_date' => $liveDate,
                                ]);
                                $keptActivityIds[] = $match->id;
                            } else {
                                $newActivity = ProjectActivity::create([
                                    'project_id' => $project->id,
                                    'baseline_schedule_id' => $schedule->id,
                                    'month_label' => $schedule->month_label,
                                    'planned_date' => $liveDate,
                                    'activity_name' => $act['activity_name'],
                                    'description' => $act['description'] ?? '',
                                    'is_completed' => false,
                                ]);
                                $keptActivityIds[] = $newActivity->id;
                            }
                        }

                        ProjectActivity::where('project_id', $project->id)
                            ->where('baseline_schedule_id', $schedule->id)
                            ->whereNotIn('id', $keptActivityIds)
                            ->delete();
                    }

                    $submittedIds[] = $schedule->id;

                } else {
                    // ── NEW original month — CREATE (both columns written) ─────────
                    $exists = ProjectBaselineSchedule::where('project_id', $project->id)
                        ->where(function ($q) use ($monthData) {
                            $q->where('planned_date', $monthData['planned_date'])
                                ->orWhere('month_label', $monthData['month_label']);
                        })
                        ->exists();
                    if ($exists) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'A target for '.$monthData['planned_date'].' / '.$monthData['month_label'].' already exists.',
                        ], 422);
                    }

                    $schedule = ProjectBaselineSchedule::create([
                        'project_id' => $project->id,
                        'month_label' => $monthData['month_label'],
                        'planned_date' => $monthData['planned_date'],
                        'planned_progress_percent' => $monthData['planned_progress_percent'],
                        'original_planned_progress_percent' => $monthData['planned_progress_percent'],
                        'original_planned_date' => $monthData['planned_date'],
                        'description' => $monthData['description'] ?? '',
                    ]);

                    $changed = true;

                    if (! empty($monthData['activities'])) {
                        foreach ($monthData['activities'] as $act) {
                            if (! empty($act['activity_name'])) {
                                ProjectActivity::create([
                                    'project_id' => $project->id,
                                    'baseline_schedule_id' => $schedule->id,
                                    'month_label' => $schedule->month_label,
                                    'planned_date' => $monthData['planned_date'],
                                    'activity_name' => $act['activity_name'],
                                    'description' => $act['description'] ?? '',
                                    'is_completed' => false,
                                ]);
                            }
                        }
                    }

                    $submittedIds[] = $schedule->id;
                }
            }

            // ── 3. Validate the edited ORIGINAL sequence ─────────────────────────
            // The admin is correcting the frozen original plan, so validate the
            // ORIGINAL curve (not the live one — a redistributed adjusted baseline
            // may legitimately differ while its original plan stays consistent).
            $allMonths = ProjectBaselineSchedule::where('project_id', $project->id)
                ->whereIn('id', $submittedIds)
                ->orderBy('original_planned_date', 'asc')
                ->get();

            $prevPercent = -1;
            foreach ($allMonths as $m) {
                $originalPct = (float) $m->original_planned_progress_percent;
                if ($originalPct <= $prevPercent) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Original progress % must be strictly increasing. "'.$m->month_label.'" ('.$originalPct.'%) is not higher than the previous month ('.$prevPercent.'%).',
                    ], 422);
                }
                $prevPercent = $originalPct;
            }

            // The ORIGINAL plan is a self-contained 0→100 curve, so its final
            // month must always reach 100% (same rule as initial setup).
            $lastMonth = $allMonths->last();
            if ($lastMonth && (float) $lastMonth->original_planned_progress_percent < 100) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'The last original month "'.$lastMonth->month_label.'" must reach 100%. Currently at '.$lastMonth->original_planned_progress_percent.'%.',
                ], 422);
            }

            // ── 4. No-op short-circuit ────────────────────────────────────────────
            // Nothing changed (no removals, no original edits, no new months):
            // skip the ActivityLog and the extension snapshot entirely.
            if (! $changed) {
                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'No changes detected. Nothing was saved.',
                ]);
            }

            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => 'Edit Original Baseline',
                'description' => auth()->user()->name
                    ." edited the locked original baseline for project: {$project->title}",
                'ip_address' => request()->ip(),
                'visibility' => 'all',
                'metadata' => [
                    'before' => $beforeMonths,
                    'after' => ProjectBaselineSchedule::where('project_id', $project->id)
                        ->whereNotNull('original_planned_progress_percent')
                        ->orderBy('original_planned_date', 'asc')
                        ->get(['id', 'month_label', 'planned_date', 'planned_progress_percent',
                            'original_planned_date', 'original_planned_progress_percent', 'description'])
                        ->map(fn ($s) => [
                            'id' => $s->id,
                            'month_label' => $s->month_label,
                            'planned_date' => $s->planned_date?->format('Y-m-d'),
                            'planned_progress_percent' => $s->planned_progress_percent,
                            'original_planned_date' => $s->original_planned_date?->format('Y-m-d'),
                            'original_planned_progress_percent' => $s->original_planned_progress_percent,
                            'description' => $s->description,
                        ])
                        ->values()
                        ->toArray(),
                    'removed' => $validated['removed_ids'] ?? [],
                ],
            ]);

            DB::commit();

            // Extension-management Phase 2.5: snapshot the corrected baseline so a
            // later revert restores the state the admin built.
            \App\Http\Controllers\Admin\ProjectExtensionController::captureBaselineSnapshot($project);

            return response()->json([
                'success' => true,
                'message' => 'Original baseline updated successfully!',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error: '.collect($e->errors())->flatten()->first(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saving edited original baseline', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error saving edited original baseline: '.$e->getMessage(),
            ], 500);
        }
    }

    public function deleteBaselineSchedule(Project $project, $scheduleId)
    {
        $this->authorizeProjectAccess(auth()->user(), $project);

        try {
            $schedule = ProjectBaselineSchedule::where('project_id', $project->id)
                ->where('id', $scheduleId)
                ->firstOrFail();

            $monthLabel = $schedule->month_label;

            // Non-admin (staff) may only delete extension-only month targets
            // that have no recorded progress. Original-baseline months are
            // frozen, and progressed months hold actuals/trend data that must
            // not be silently erased.
            if (auth()->user() && auth()->user()->role !== 'admin') {
                $isOriginalBaseline = $schedule->original_planned_progress_percent !== null;
                $hasProgress = $schedule->progressHistory()->exists();

                if ($isOriginalBaseline) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Original baseline months cannot be deleted. Only extension months may be removed.',
                    ], 403);
                }

                if ($hasProgress) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This month already has recorded progress and cannot be deleted.',
                    ], 403);
                }
            }

            DB::beginTransaction();

            // Delete in correct order to respect foreign key constraints
            // 1. Delete progress history records (will also cascade via FK if implemented)
            ProjectProgressHistory::where('project_id', $project->id)
                ->where('baseline_schedule_id', $schedule->id)
                ->delete();

            // 2. Delete associated activities
            ProjectActivity::where('project_id', $project->id)
                ->where('baseline_schedule_id', $schedule->id)
                ->delete();

            // 3. Delete carryover records referencing this schedule (both from and to)
            ProjectActivityCarryover::where('project_id', $project->id)
                ->where(function ($q) use ($schedule) {
                    $q->where('from_baseline_schedule_id', $schedule->id)
                        ->orWhere('to_baseline_schedule_id', $schedule->id);
                })
                ->delete();

            // 4. Delete the schedule itself
            $schedule->delete();

            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => 'Delete Baseline Schedule',
                'description' => auth()->user()->name." deleted {$monthLabel} baseline target for project: {$project->title} (baseline snapshot updated)",
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);

            DB::commit();

            // Extension-management Phase 2.5: keep the approved extension's
            // baseline snapshot in sync with deletions so a later revert
            // restores the exact state the user built.
            \App\Http\Controllers\Admin\ProjectExtensionController::captureBaselineSnapshot($project);

            return response()->json([
                'success' => true,
                'message' => 'Baseline schedule, activities, and progress history deleted',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting baseline schedule', [
                'project_id' => $project->id,
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error deleting baseline schedule: '.$e->getMessage(),
            ], 500);
        }
    }
}
