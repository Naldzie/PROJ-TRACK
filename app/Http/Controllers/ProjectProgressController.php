<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectProgressHistory;
use App\Models\ProjectBaselineSchedule;
use App\Models\ActivityLog;
use App\Models\PredictionHistory;
use App\Services\ProjectDetailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

/**
 * ProjectProgressController
 * 
 * Handles all ProjectProgressHistory (monthly recorded progress) operations:
 * - Creating progress records (actual % vs planned %)
 * - Editing existing progress records
 * - Deleting progress records with audit trail
 * - Recording monthly progress with variance calculation
 * 
 * Moved from ProjectController as part of Phase 2 refactor (April 2026)
 */
class ProjectProgressController extends Controller
{
    public function storeProgress(Request $request, Project $project)
    {
        $request->validate([
            'inspection_date' => 'required|date',
            'progress_percentage' => 'required|numeric|min:0|max:100',
            'remarks' => 'nullable|string|max:1000',
            'recorded_by' => 'nullable|string|max:255',
        ]);

        $record = ProjectProgressHistory::create([
            'project_id' => $project->id,
            'inspection_date' => $request->inspection_date,
            'progress_percentage' => $request->progress_percentage,
            'remarks' => $request->remarks,
            'recorded_by' => $request->recorded_by,
        ]);

        // NOTE: storeProgress() appears to be a legacy/superseded entry point
        // (see recordMonthlyProgress() below for the main monthly inspection
        // flow). Logging is added here too in case this method is still
        // reachable from any active UI flow — if it turns out to be fully
        // dead code, this logging call is harmless and can be removed later.
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'Create Progress Record',
            'description' => auth()->user()->name . " recorded {$request->progress_percentage}% progress for project: {$project->title}",
            'ip_address' => request()->ip(),
            'visibility' => 'all',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Progress record saved successfully',
            'record' => $record
        ]);
    }

    public function editProgressRecord(Request $request, Project $project, $progressId)
    {
        $request->validate([
            'inspection_date' => 'required|date|before_or_equal:today',
            'progress_percentage' => 'required|numeric|min:0|max:100',
            'actual_start_date' => 'nullable|date',
            'actual_end_date' => 'nullable|date|after_or_equal:actual_start_date',
            'status' => 'nullable|string',
            'remarks' => 'nullable|string|max:1000',
            'activity_ids' => 'nullable|array',
            'activity_ids.*' => 'integer|exists:project_activities,id',
        ]);

        // Find the progress record
        $record = ProjectProgressHistory::where('project_id', $project->id)
            ->where('id', $progressId)
            ->firstOrFail();

        $newProgressPercent = (float) $request->progress_percentage;
        $isMonthlyRecord = !empty($record->month_label) || !empty($record->baseline_schedule_id);

        if ($isMonthlyRecord && !empty($record->month_label)) {
            $maxRecordedActual = ProjectProgressHistory::where('project_id', $project->id)
                ->where('id', '!=', $record->id)
                ->where('month_label', '<=', $record->month_label)
                ->get()
                ->map(function ($item) {
                    return (float) ($item->actual_progress_percent ?? $item->progress_percentage ?? 0);
                })
                ->max() ?? 0;

            if ($newProgressPercent < $maxRecordedActual) {
                return response()->json([
                    'success' => false,
                    'message' => "Actual progress % cannot be lower than the highest recorded progress ({$maxRecordedActual}%). You entered: {$newProgressPercent}%"
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            // Store original values before updating
            $record->recordEdit(auth()->user()->name ?? auth()->user()->email ?? 'Unknown');

            // Build update payload
            $updatePayload = [
                'inspection_date' => $request->inspection_date,
                'progress_percentage' => $request->progress_percentage,
                'actual_start_date' => $request->actual_start_date,
                'actual_end_date' => $request->actual_end_date,
                'status' => $request->status ?? $record->status,
                'remarks' => $request->remarks,
            ];

            if ($isMonthlyRecord) {
                $plannedProgressPercent = $record->planned_progress_percent;

                $baselineSchedule = null;
                if (!empty($record->baseline_schedule_id)) {
                    $baselineSchedule = ProjectBaselineSchedule::where('project_id', $project->id)
                        ->where('id', $record->baseline_schedule_id)
                        ->first();
                }

                if (!$baselineSchedule && !empty($record->month_label)) {
                    $baselineSchedule = ProjectBaselineSchedule::where('project_id', $project->id)
                        ->where('month_label', $record->month_label)
                        ->first();
                }

                if ($baselineSchedule) {
                    $plannedProgressPercent = (float) $baselineSchedule->planned_progress_percent;
                    $updatePayload['baseline_schedule_id'] = $baselineSchedule->id;
                }

                $plannedProgressPercent = (float) ($plannedProgressPercent ?? 0);
                $variancePercent = round($newProgressPercent - $plannedProgressPercent, 2);

                $updatePayload['actual_progress_percent'] = $newProgressPercent;
                $updatePayload['planned_progress_percent'] = $plannedProgressPercent;
                $updatePayload['variance_percent'] = $variancePercent;
                $updatePayload['status'] = $this->getProgressStatus($variancePercent);

                // Sync activity completion if activity_ids provided and month_label exists
                if ($request->has('activity_ids') && !empty($record->month_label)) {
                    $activityIds = $request->activity_ids ?? [];

                    // Mark submitted activities as completed
                    if (!empty($activityIds)) {
                        ProjectActivity::whereIn('id', $activityIds)
                            ->where('project_id', $project->id)
                            ->where('month_label', $record->month_label)
                            ->update(['is_completed' => true]);
                    }

                    // Mark activities NOT in list as incomplete (only for this month)
                    ProjectActivity::where('project_id', $project->id)
                        ->where('month_label', $record->month_label)
                        ->whereNotIn('id', $activityIds)
                        ->update(['is_completed' => false]);

                    // Update the record's activity_id JSON array
                    $updatePayload['activity_id'] = !empty($activityIds) ? $activityIds : null;
                }
            }

            // Update the record
            $record->update($updatePayload);

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'Edit Progress Record',
                'description' => auth()->user()->name . " edited progress record for project: {$project->title}" . (!empty($record->month_label) ? " ({$record->month_label})" : ''),
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);

            DB::commit();

            // Sync prediction history actual_next with the edited value
            if ($isMonthlyRecord && !empty($record->month_label)) {
                $this->syncPredictionHistoryActual($project, $record->month_label);
            }

            return response()->json([
                'success' => true,
                'message' => 'Progress record updated successfully',
                'record' => $record->fresh(),
                'audit_info' => $record->getEditHistory(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update progress record: ' . $e->getMessage()
            ], 422);
        }
    }

    public function destroyProgress(Project $project, $progressId)
    {
        // Admin-only deletion
        if (!auth()->user() || auth()->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Only admin users can delete progress records.'
            ], 403);
        }

        $record = ProjectProgressHistory::where('project_id', $project->id)
            ->where('id', $progressId)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            // Revert activities to incomplete if this record's activity_ids are
            // not referenced by any other remaining progress record for the same month.
            $activityIds = $record->activity_id ?? [];

            if (!empty($activityIds) && !empty($record->month_label)) {
                foreach ($activityIds as $activityId) {
                    // Check if any OTHER progress record (excluding this one) for the
                    // same month still references this activity
                    $otherRecordRefs = ProjectProgressHistory::where('project_id', $project->id)
                        ->where('id', '!=', $record->id)
                        ->where('month_label', $record->month_label)
                        ->get()
                        ->contains(function ($other) use ($activityId) {
                            $otherIds = $other->activity_id ?? [];
                            return in_array($activityId, $otherIds);
                        });

                    if (!$otherRecordRefs) {
                        // No remaining record references this activity — revert it
                        ProjectActivity::where('id', $activityId)
                            ->where('project_id', $project->id)
                            ->update(['is_completed' => false]);
                    }
                }
            }

            $record->delete();

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'Delete Progress Record',
                'description' => auth()->user()->name . " deleted a progress record for project: {$project->title}" . (!empty($record->month_label) ? " ({$record->month_label})" : ''),
                'ip_address' => request()->ip(),
                'visibility' => 'admin_only',
            ]);

            DB::commit();

            // Recompute prediction history actual_next from remaining records;
            // reverts to null when no progress record remains for the month
            // (e.g. a record that was entered under the wrong month).
            $this->syncPredictionHistoryActual($project, $record->month_label);

            // When the LAST progress record for the project is deleted, remove
            // every prediction row too (they become pending months that will
            // never be observed). Partial deletes must NOT prune — that would
            // permanently orphan the month's forecast-vs-actual accuracy link.
            $this->prunePredictionHistoryOnEmpty($project);

            return response()->json([
                'success' => true,
                'message' => 'Progress record deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete progress record: ' . $e->getMessage()
            ], 422);
        }
    }

    public function recordMonthlyProgress(Request $request, Project $project)
    {
        // Validate input
        $validated = $request->validate([
            'inspection_date' => 'required|date',
            'month_label' => 'required|string',
            'activity_ids' => 'nullable|array',
            'activity_ids.*' => 'integer|exists:project_activities,id',
            'actual_progress_percent' => 'required|numeric|between:0,100',
            'remarks' => 'nullable|string'
        ]);

        // The inspection date must fall within the selected month, otherwise a
        // record could be attributed to the wrong month (out-of-order history).
        if (\Illuminate\Support\Carbon::parse($validated['inspection_date'])->format('Y-m') !== $validated['month_label']) {
            return response()->json([
                'success' => false,
                'message' => 'Inspection date does not match the selected month.'
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Step 0: Validate actual progress is not lower than any previously recorded progress
            // Check all progress records up to and including this month
            $maxRecordedActual = ProjectProgressHistory::where('project_id', $project->id)
                ->where('month_label', '<=', $validated['month_label'])
                ->max('actual_progress_percent') ?? 0;
            
            if ($validated['actual_progress_percent'] < $maxRecordedActual) {
                throw new \Exception("Actual progress % cannot be lower than the highest recorded progress ({$maxRecordedActual}%). You entered: {$validated['actual_progress_percent']}%");
            }

            // Step 1: Update PROJECT_ACTIVITIES with completion status
            $activityIds = $validated['activity_ids'] ?? [];
            
            // Mark all submitted activities as completed (if any)
            if (!empty($activityIds)) {
                ProjectActivity::whereIn('id', $activityIds)
                    ->where('project_id', $project->id)
                    ->update(['is_completed' => true]);
            }

            // Step 2: Get BASELINE SCHEDULE target for this month
            $baselineSchedule = ProjectBaselineSchedule::where('project_id', $project->id)
                ->where('month_label', $validated['month_label'])
                ->first();

            $plannedProgressPercent = $baselineSchedule ? $baselineSchedule->planned_progress_percent : 0;

            // Step 3: Calculate variance
            $actualProgressPercent = $validated['actual_progress_percent'];
            $variancePercent = round($actualProgressPercent - $plannedProgressPercent, 2);

            // Step 4: Create PROJECT_PROGRESS_HISTORY record
            $progressRecord = $project->progressHistory()->create([
                'baseline_schedule_id' => $baselineSchedule?->id, // Link to baseline schedule for cascade delete
                'month_label' => $validated['month_label'],
                'actual_progress_percent' => $actualProgressPercent,
                'planned_progress_percent' => $plannedProgressPercent,
                'variance_percent' => $variancePercent,
                'activity_id' => !empty($activityIds) ? $activityIds : null,
                'month_number' => intval(explode('-', $validated['month_label'])[1]), // Extract month from "2026-01"
                'recorded_by' => auth()->user()->id,
                'remarks' => $validated['remarks'] ?? null,
                // Backward compatibility fields
                'progress_percentage' => $actualProgressPercent,
                'status' => $this->getProgressStatus($variancePercent),
                'inspection_date' => $validated['inspection_date']
            ]);

            DB::commit();

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'Record Monthly Progress',
                'description' => auth()->user()->name . " recorded {$actualProgressPercent}% actual progress for project: {$project->title} ({$validated['month_label']}) — " . $this->getProgressStatus($variancePercent),
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);

            // Sync prediction history actual_next with the latest recorded
            // progress for this month (non-blocking). Matches by month_label,
            // so a second inspection in the same month overrides the prior value
            // instead of corrupting the next month's prediction row.
            $this->syncPredictionHistoryActual($project, $validated['month_label']);

            // Step 5: Get updated summary data
            $service = app(ProjectDetailService::class);
            $activityStats = $service->getActivitiesStatistics($project->id);
            $monthlySummary = $service->getSCurveMonthlySummary($project->id);
            $currentProgress = $service->getCurrentProgress($project->id);

            // Step 6: Find incomplete activities for carry-over prompt
            $incompleteActivities = ProjectActivity::where('project_id', $project->id)
                ->where('month_label', $validated['month_label'])
                ->where('is_completed', false)
                ->get(['id', 'activity_name', 'description', 'month_label']);

            // Check if there are future months available for carry-over
            $hasFutureMonths = ProjectBaselineSchedule::where('project_id', $project->id)
                ->where('month_label', '>', $validated['month_label'])
                ->exists();

            return response()->json([
                'success' => true,
                'message' => 'Monthly progress recorded successfully',
                'progress_record' => $progressRecord,
                'activity_stats' => $activityStats,
                'current_progress' => $currentProgress,
                'monthly_summary' => $monthlySummary,
                'variance' => $variancePercent,
                'status' => $this->getProgressStatus($variancePercent),
                'incomplete_activities' => $incompleteActivities->isNotEmpty() && $hasFutureMonths ? $incompleteActivities->toArray() : [],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to record monthly progress: ' . $e->getMessage()
            ], 422);
        }
    }

    /**
     * Determine progress status based on variance
     * 
     * @param float $variancePercent
     * @return string (Behind|OnTrack|Ahead)
     */
    private function getProgressStatus($variancePercent)
    {
        if ($variancePercent < -5) {
            return 'Behind';
        } elseif ($variancePercent > 5) {
            return 'Ahead';
        }
        return 'OnTrack';
    }

    /**
     * Recompute prediction_history.actual_next from the source of truth
     * (project_progress_history) for a given month.
     *
     * Called by: recordMonthlyProgress(), editProgressRecord(), destroyProgress()
     * Calls: PredictionHistory::where(), project_progress_history lookup
     *
     * Behavior:
     *   - add/ edit: sets actual_next to the LATEST recorded progress for the month
     *   - delete: recomputes from remaining records; reverts to null when no
     *     progress record remains for the month
     *
     * Rev 2 (evolution history): actual_next is written to the NEWEST
     * prediction row for the month only; older rows are explicitly nulled
     * (superseded forecasts stay as history without an actual), keeping the
     * accuracy stats one-per-month.
     *
     * Non-blocking: failures are logged, never break the main operation.
     */
    private function syncPredictionHistoryActual(Project $project, string $monthLabel): void
    {
        try {
            $latestRecord = ProjectProgressHistory::where('project_id', $project->id)
                ->where('month_label', $monthLabel)
                ->orderBy('inspection_date', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            $actual = $latestRecord
                ? (float) ($latestRecord->actual_progress_percent ?? $latestRecord->progress_percentage ?? 0)
                : null;

            $latestPrediction = PredictionHistory::where('project_id', $project->id)
                ->where('month_label', $monthLabel)
                ->orderByDesc('id')
                ->first();

            if (!$latestPrediction) {
                return;
            }

            // Null any superseded rows first (self-heals rows that received
            // an actual under the pre-rev2 code), then attribute the actual
            // to the newest forecast for the month.
            PredictionHistory::where('project_id', $project->id)
                ->where('month_label', $monthLabel)
                ->where('id', '!=', $latestPrediction->id)
                ->update(['actual_next' => null]);

            $latestPrediction->update(['actual_next' => $actual]);
        } catch (\Exception $e) {
            Log::warning('Failed to sync prediction history actual_next', [
                'project_id' => $project->id,
                'month_label' => $monthLabel,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove all prediction_history rows for a project when its progress
     * history becomes completely empty (the LAST progress record is deleted).
     *
     * Never called on partial deletes — deleting rows there would permanently
     * break accuracy: syncPredictionHistoryActual only writes actuals to
     * EXISTING rows, and regeneration never re-creates a row for an
     * already-observed month.
     *
     * Re-entering progress rebuilds rows naturally: the model predicts the
     * first unobserved baseline month on the next page view.
     */
    private function prunePredictionHistoryOnEmpty(Project $project): void
    {
        try {
            if (ProjectProgressHistory::where('project_id', $project->id)->exists()) {
                return;
            }

            PredictionHistory::where('project_id', $project->id)->delete();
        } catch (\Exception $e) {
            Log::warning('Failed to prune prediction history on empty progress', [
                'project_id' => $project->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}