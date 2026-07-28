<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BaselineScheduleController;
use App\Http\Controllers\ProjectActivityController;
use App\Http\Controllers\DocumentTypeController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth')->group(function () {
    
    // === DOCUMENT TYPES: API ENDPOINTS ===
    
    /**
     * Get all active document types
     * GET /api/document-types
     * Response: { success, document_types: [{id, name, description, icon}, ...] }
     */
    Route::get('/document-types', [DocumentTypeController::class, 'getActive']);
    
    // === ACTIVITY CHECKLIST: API ENDPOINTS ===
    
    /**
     * Get Baseline Schedule months for a project
     * GET /api/projects/{id}/baseline-schedule
     * Response: { success, months: [{month_label, planned_date, planned_progress_percent}, ...] }
     */
    Route::get('/projects/{project}/baseline-schedule', [BaselineScheduleController::class, 'getBaselineScheduleMonths']);
    
    /**
     * Get activities for a specific month
     * GET /api/projects/{id}/activities?month_label=YYYY-MM
     * Response: { success, activities: [{id, activity_name, description, is_completed, month_label}, ...], planned_progress_percent, month_date }
     */
    Route::get('/projects/{project}/activities', [ProjectActivityController::class, 'getActivitiesByMonth']);

    /**
     * Get all activities for a project with month filter
     * GET /api/project-activities?project_id={id}&month_label={label}
     */
    Route::get('/project-activities', [ProjectActivityController::class, 'getProjectActivities']);

    /**
     * Toggle activity completion status
     * PATCH /api/project-activities/{id}/toggle
     */
    Route::patch('/project-activities/{activity}/toggle', [ProjectActivityController::class, 'toggleActivityCompletion']);
    
});
