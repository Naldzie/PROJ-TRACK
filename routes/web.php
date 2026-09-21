<?php

use App\Http\Controllers\Staff\ProfileStaffController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Contractor\DashboardController as ContractorDashboardController;
use App\Http\Controllers\Contractor\ProfileContractorController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\ProjectController;  // Consolidated: handles all roles with authorization
use App\Http\Controllers\BaselineScheduleController;
use App\Http\Controllers\SCurveImportController;
use App\Http\Controllers\DocumentParserController;
use App\Http\Controllers\ProjectActivityController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\RiskController;
use App\Http\Controllers\Admin\ProjectExtensionController;
use App\Http\Controllers\Admin\MilestoneController;
use App\Http\Controllers\ProjectProgressController;
use App\Http\Controllers\Admin\ProposalController;
use App\Http\Controllers\ActivityLogController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GoogleController;  
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ContractorController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\WarrantyController;
use App\Http\Controllers\Contractor\ContractorWarrantyController;
use App\Http\Controllers\staff\StaffWarrantyController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\BudgetSourceController;
use App\Http\Controllers\Admin\ParserKeywordProposalController;
use App\Http\Controllers\Admin\SCurveHeaderProposalController;
use App\Http\Controllers\Admin\DocumentTypeController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Admin\EmailController as AdminEmailController;
use App\Http\Controllers\Admin\RatingController;
use App\Http\Controllers\Staff\EmailController as StaffEmailController;
use App\Http\Controllers\Contractor\EmailController as ContractorEmailController;
use App\Http\Controllers\ReportExportController;
/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// Redirect root to login
Route::get('/', fn() => redirect()->route('login'));

// Login page - Google only
Route::get('/login', function () {
    return view('auth.login');
})->name('login');

// Google login redirect
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('google.login');

// Google callback
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('google.callback');

// Public warranty overview — viewable without login (read-only table, no files/actions)
Route::get('/warranty/preview', [WarrantyController::class, 'publicPreview'])->name('warranty.preview.public');
/*
|--------------------------------------------------------------------------
| Staff Routes
|--------------------------------------------------------------------------
*/

Route::prefix('staff')->middleware(['auth', 'active', 'role:staff'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])
    ->name('staff.dashboard');

    Route::get('/dashboard/filter', [DashboardController::class, 'filter'])
    ->name('staff.dashboard.filter');

    Route::get('/dashboard/search', [DashboardController::class, 'search'])
    ->name('staff.dashboard.search');

    Route::get('/warranty', [StaffWarrantyController::class, 'index'])
        ->name('staff.warranty');

    Route::get('/warranty/preview', [StaffWarrantyController::class, 'preview'])
        ->name('staff.warranty.preview');

    Route::post('/warranty', [StaffWarrantyController::class, 'store'])
        ->name('staff.warranty.store');

    Route::get('/email', [StaffEmailController::class, 'index'])->name('staff.email');
    Route::post('/emails', [StaffEmailController::class, 'store'])->name('staff.emails.store');
    Route::get('/emails/{email}/edit', [StaffEmailController::class, 'edit'])->name('staff.emails.edit');
    Route::put('/emails/{email}', [StaffEmailController::class, 'update'])->name('staff.emails.update');
    Route::delete('/emails/{email}', [StaffEmailController::class, 'destroy'])->name('staff.emails.destroy');
    Route::post('/emails/{email}/send', [StaffEmailController::class, 'sendDraft'])->name('staff.emails.send');
    Route::post('/emails/{email}/mark-read', [StaffEmailController::class, 'markAsRead'])->name('staff.emails.mark-read');
    Route::get('/emails/{email}', [StaffEmailController::class, 'show'])->name('staff.emails.show');
    Route::post('/email/send-now', [StaffEmailController::class, 'sendNow'])->name('staff.email.send-now');
    Route::post('/email/send-one', [StaffEmailController::class, 'sendOne'])->name('staff.email.send-one');
    Route::post('/email/toggle-auto-send', [StaffEmailController::class, 'toggleAutoSend'])->name('staff.email.toggle-auto-send');

    
    Route::get('/profile', [ProfileStaffController::class, 'index'])
        ->name('staff.profile');

    Route::post('/profile/update', [ProfileStaffController::class, 'update'])
        ->name('staff.profile.update');

    Route::post('/profile/change-password', [ProfileStaffController::class, 'changePassword'])
        ->name('staff.profile.change.password');

    // === PROJECT ROUTES (Using Shared Pages) ===
    Route::get('/projects', [ProjectController::class, 'index'])
        ->name('staff.projects');

    Route::post('/projects', [ProjectController::class, 'store'])
        ->name('staff.projects.store');

    Route::get('/projects/generate-code', [ProjectController::class, 'generateCode'])
        ->name('staff.projects.generateCode');

    Route::post('/projects/validate-code', [ProjectController::class, 'validateProjectCode'])
        ->name('staff.projects.validateCode');

    Route::post('/projects/validate-title', [ProjectController::class, 'validateProjectTitle'])
        ->name('staff.projects.validateTitle');

    Route::get('/projects/archived', [ProjectController::class, 'archived'])
        ->name('staff.projects.archived');

    Route::get('/projects/{id}', [ProjectController::class, 'show'])
        ->name('staff.projects.detail');

    Route::get('/projects/{id}/chart-data', [ProjectController::class, 'getChartData'])
    ->name('staff.projects.chart-data');

    Route::get('/projects/{id}/data', [ProjectController::class, 'getProjectData'])
        ->name('staff.projects.getData');

    Route::put('/projects/{id}', [ProjectController::class, 'update'])
        ->name('staff.projects.update');

    Route::post('/projects/{id}/update-progress', [ProjectController::class, 'updateProgress'])
        ->name('staff.projects.updateProgress');

    Route::post('/projects/{id}/archive', [ProjectController::class, 'archive'])
        ->name('staff.projects.archive');

    Route::post('/projects/{id}/restore', [ProjectController::class, 'restore'])
        ->name('staff.projects.restore');

    Route::delete('/projects/{id}', [ProjectController::class, 'delete'])
        ->name('staff.projects.destroy');

    Route::post('/projects/{project}/regenerate-analysis', [ProjectController::class, 'regenerateAnalysis'])
        ->name('staff.projects.regenerate-analysis');

    // === CONTRACTOR ROUTES ===
    Route::get('/contractors', [ContractorController::class, 'index'])->name('staff.contractors.index');
    Route::post('/contractors', [ContractorController::class, 'store'])->name('staff.contractors.store');
    Route::get('/contractors/{id}', [ContractorController::class, 'show'])->name('staff.contractors.show');
    Route::post('/contractors/{id}/toggle-status', [ContractorController::class, 'toggleStatus'])->name('staff.contractors.toggleStatus');

    // === PROGRESS HISTORY ROUTES ===
    Route::post('/projects/{project}/progress', [ProjectProgressController::class, 'storeProgress'])
        ->name('staff.projects.progress.store');
    Route::put('/projects/{project}/progress/{progressId}', [ProjectProgressController::class, 'editProgressRecord'])
        ->name('staff.projects.progress.update');
    Route::delete('/projects/{project}/progress/{progressId}', [ProjectProgressController::class, 'destroyProgress'])
        ->name('staff.projects.progress.destroy');

    // === ACTIVITY CHECKLIST: RECORD MONTHLY PROGRESS ROUTE ===
    Route::post('/projects/{project}/record-monthly-progress', [ProjectProgressController::class, 'recordMonthlyProgress'])
        ->name('staff.projects.record-monthly-progress');

    // === BASELINE SCHEDULE: CRUD ROUTES ===
    Route::post('/projects/{project}/baseline-schedules', [BaselineScheduleController::class, 'storeBaselineSchedule'])
        ->name('staff.projects.baseline-schedules.store');
    // NEW: atomic bulk-create for the initial "Set Up Baseline" modal (B3.2)
    Route::post('/projects/{project}/baseline-schedules/setup', [BaselineScheduleController::class, 'storeInitialBaselineSetup'])
        ->name('staff.projects.baseline-schedules.setup');
    Route::get('/projects/{project}/activities-by-month', [ProjectActivityController::class, 'getActivitiesByMonth'])
        ->name('staff.projects.activities.by-month');
    Route::delete('/projects/{project}/baseline-schedules/{scheduleId}', [BaselineScheduleController::class, 'deleteBaselineSchedule'])
        ->name('staff.projects.baseline-schedules.destroy');
    // NEW: edit a baseline schedule row (post-extension, no-actual-progress rows only — guarded in controller)
    Route::patch('/projects/{project}/baseline-schedules/{scheduleId}', [BaselineScheduleController::class, 'updateBaselineSchedule'])
        ->name('staff.projects.baseline-schedules.update');
    // Carry an incomplete activity over to a future baseline schedule month
    Route::post('/projects/{project}/activities/{activity}/carry-over', [BaselineScheduleController::class, 'carryOverActivity'])
        ->name('staff.projects.activities.carry-over');
    // Adjusted baseline generation (auto-distribution)
    Route::get('/projects/{project}/baseline-schedules/adjust', [BaselineScheduleController::class, 'getBaselineMonthsForAdjustment'])
        ->name('staff.projects.baseline-schedules.adjust');
    Route::post('/projects/{project}/baseline-schedules/adjust/save', [BaselineScheduleController::class, 'saveAdjustedBaseline'])
        ->name('staff.projects.baseline-schedules.adjust.save');
    // Edit original (frozen) baseline — assigned staff can edit their projects
    Route::get('/projects/{project}/baseline-schedules/original', [BaselineScheduleController::class, 'getOriginalBaselineMonths'])
        ->name('staff.projects.baseline-schedules.original');
    Route::post('/projects/{project}/baseline-schedules/original/save', [BaselineScheduleController::class, 'saveEditedOriginalBaseline'])
        ->name('staff.projects.baseline-schedules.original.save');

    // S-Curve Excel baseline import (Phase 2)
    Route::post('/s-curve/parse', [SCurveImportController::class, 'parseExcel'])
        ->name('staff.projects.s-curve.parse');

    // S-Curve AI fallback (E2 Excel Tier 3, opt-in)
    Route::post('/s-curve/parse-gemini', [SCurveImportController::class, 'parseWithGemini'])
        ->name('staff.projects.s-curve.parse-gemini');

    // S-Curve AI fallback (F-B PDF Tier 3, opt-in — page images only)
    Route::post('/s-curve/parse-pdf-gemini', [SCurveImportController::class, 'parsePdfWithGemini'])
        ->name('staff.projects.s-curve.parse-pdf-gemini');

    // AI fallback for normal-document parsing (Tier 3, opt-in — see AI plan)
    Route::post('/document/parse-gemini', [DocumentParserController::class, 'parseWithGemini'])
        ->name('staff.projects.document.parse-gemini');

    // === ACTIVITIES: CRUD ROUTES ===
    Route::post('/projects/{project}/activities', [ProjectActivityController::class, 'storeActivity'])
        ->name('staff.projects.activities.store');
    Route::delete('/projects/{project}/activities/{activityId}', [ProjectActivityController::class, 'deleteActivity'])
        ->name('staff.projects.activities.destroy');

    // === ISSUES: CRUD ROUTES ===
    Route::get('/projects/{project}/issues', [IssueController::class, 'getIssues'])
        ->name('staff.projects.issues.list');
    Route::post('/projects/{project}/issues', [IssueController::class, 'store'])
        ->name('staff.projects.issues.store');
    Route::put('/projects/{project}/issues/{issue}', [IssueController::class, 'update'])
        ->name('staff.projects.issues.update');
    Route::delete('/projects/{project}/issues/{issue}', [IssueController::class, 'destroy'])
        ->name('staff.projects.issues.destroy');

    // === RISK REGISTER: CRUD ROUTES ===
    Route::get('/projects/{project}/risks', [RiskController::class, 'getRisks'])
        ->name('staff.projects.risks.list');
    Route::post('/projects/{project}/risks', [RiskController::class, 'store'])
        ->name('staff.projects.risks.store');
    Route::put('/projects/{project}/risks/{risk}', [RiskController::class, 'update'])
        ->name('staff.projects.risks.update');
    Route::delete('/projects/{project}/risks/{risk}', [RiskController::class, 'destroy'])
        ->name('staff.projects.risks.destroy');
    Route::post('/projects/{project}/risks/{risk}/convert', [RiskController::class, 'convertToIssue'])
        ->name('staff.projects.risks.convert');

    // === PROJECT EXTENSIONS: CRUD ROUTES ===
    Route::post('/projects/{project}/extensions', [ProjectExtensionController::class, 'store'])
        ->name('staff.projects.extensions.store');
    Route::get('/projects/{project}/extensions', [ProjectExtensionController::class, 'index'])
        ->name('staff.projects.extensions.index');
    Route::get('/projects/{project}/extensions/{extension}', [ProjectExtensionController::class, 'show'])
        ->name('staff.projects.extensions.show');
    Route::put('/projects/{project}/extensions/{extension}', [ProjectExtensionController::class, 'update'])
        ->name('staff.projects.extensions.update');
    Route::delete('/projects/{project}/extensions/{extension}', [ProjectExtensionController::class, 'destroy'])
        ->name('staff.projects.extensions.destroy');
    // NEW: resubmit a rejected extension as a brand-new row (Decision 6) —
    // the rejected row is never mutated.
    Route::post('/projects/{project}/extensions/{extension}/resubmit', [ProjectExtensionController::class, 'resubmit'])
        ->name('staff.projects.extensions.resubmit');

    // Baseline snapshot endpoint — used by "Restore to Original" in adjust modal
    Route::get('/projects/{project}/extensions/latest-snapshot', [ProjectExtensionController::class, 'latestSnapshot'])
        ->name('staff.projects.extensions.latestSnapshot');

    // === CATEGORY ROUTES (READ-ONLY) ===
    Route::get('/categories', [CategoryController::class, 'getCategories'])
        ->name('staff.categories.list');

    // === BUDGET SOURCE ROUTES (READ-ONLY for dropdown) ===
    // Staff can CHOOSE a budget source when creating/editing their assigned project;
    // only admin can MANAGE (add/edit/delete) sources. This mirrors categories pattern.
    Route::get('/budget-sources', [BudgetSourceController::class, 'getBudgetSources'])
        ->name('staff.budget-sources.list');

    // === PARSER LEARNING LOOP (L1 silent collection) ===
    // Staff AND admin corrections feed the loop (Decision L0a); contractors excluded.
    Route::post('/parser-keyword-proposals/collect', [ParserKeywordProposalController::class, 'collect'])
        ->name('staff.parser-keywords.collect');

    // === PARSER LEARNING LOOP (L2 review: staff + admin per Decision L0a) ===
    Route::get('/parser-keyword-proposals/all', [ParserKeywordProposalController::class, 'getAllForManagement'])
        ->name('staff.parser-keywords.all');

    Route::patch('/parser-keyword-proposals/{proposal}/approve', [ParserKeywordProposalController::class, 'approve'])
        ->name('staff.parser-keywords.approve');

    Route::delete('/parser-keyword-proposals/{proposal}', [ParserKeywordProposalController::class, 'destroy'])
        ->name('staff.parser-keywords.destroy');

    // Soft-delete a REJECTED row (2026-09-21). The Deleted tab, restore and
    // permanent delete are admin-only and are registered in the admin group only.
    Route::delete('/parser-keyword-proposals/{proposal}/trash', [ParserKeywordProposalController::class, 'trash'])
        ->name('staff.parser-keywords.trash');

    // === S-CURVE HEADER LEARNING (E1-1 silent capture; staff + admin, contractors excluded) ===
    Route::post('/scurve-header-proposals/collect', [SCurveHeaderProposalController::class, 'collect'])
        ->name('staff.scurve-headers.collect');

    // === S-CURVE HEADER REVIEW (E1-2 desk; staff + admin per E1b) ===
    Route::get('/scurve-header-proposals/all', [SCurveHeaderProposalController::class, 'getAllForManagement'])
        ->name('staff.scurve-headers.all');

    Route::patch('/scurve-header-proposals/{proposal}/approve', [SCurveHeaderProposalController::class, 'approve'])
        ->name('staff.scurve-headers.approve');

    // === S-CURVE HEADER TEACHING (Phase-A point-and-teach; staff + admin per E1b) ===
    Route::patch('/scurve-header-proposals/{proposal}/columns', [SCurveHeaderProposalController::class, 'teachColumns'])
        ->name('staff.scurve-headers.columns');

    Route::delete('/scurve-header-proposals/{proposal}', [SCurveHeaderProposalController::class, 'destroy'])
        ->name('staff.scurve-headers.destroy');

    // Soft-delete a REJECTED layout (2026-09-21). The Deleted tab, restore and
    // permanent delete are admin-only and are registered in the admin group only.
    Route::delete('/scurve-header-proposals/{proposal}/trash', [SCurveHeaderProposalController::class, 'trash'])
        ->name('staff.scurve-headers.trash');

    // === DOCUMENT ROUTES ===
    Route::post('/documents/upload', [DocumentController::class, 'upload'])
        ->name('staff.documents.upload');

    Route::get('/projects/{project}/documents', [DocumentController::class, 'getProjectDocuments'])
        ->name('staff.documents.list');

    Route::delete('/documents/{document}', [DocumentController::class, 'delete'])
        ->name('staff.documents.destroy');

    Route::get('/documents/types', [DocumentController::class, 'getDocumentTypes'])
        ->name('staff.documents.types');

    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('staff.documents.download');
    
    Route::get('/documents/{document}/view', [DocumentController::class, 'view'])
        ->name('staff.documents.view');

    Route::get('/documents/{document}/preview', [DocumentController::class, 'previewHtml'])
        ->name('staff.documents.preview');

    // === ATTACHMENT ROUTES ===
    Route::post('/attachments/upload', [AttachmentController::class, 'upload'])
        ->name('staff.attachments.upload');

    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'delete'])
        ->name('staff.attachments.destroy');

    Route::get('/attachments/{attachment}/view', [AttachmentController::class, 'view'])
        ->name('staff.attachments.view');

    Route::get('/attachments/{attachment}/preview', [AttachmentController::class, 'previewHtml'])
        ->name('staff.attachments.preview');

    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('staff.attachments.download');

    // === NOTIFICATION ROUTES ===
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('staff.notifications');

    Route::get('/notifications/recent', [NotificationController::class, 'recent'])
        ->name('staff.notifications.recent');

    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('staff.notifications.read');

    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('staff.notifications.readAll');

    // === REPORT EXPORT ROUTES ===
    Route::get('/projects/{project}/reports/progress.xlsx', [ReportExportController::class, 'progressExcel'])
        ->name('staff.projects.exports.progress');

    Route::get('/projects/{project}/reports/baseline.xlsx', [ReportExportController::class, 'baselineExcel'])
        ->name('staff.projects.exports.baseline');

    Route::get('/projects/{project}/reports/extensions.xlsx', [ReportExportController::class, 'extensionsExcel'])
        ->name('staff.projects.exports.extensions');

    Route::get('/projects/{project}/reports/issues.xlsx', [ReportExportController::class, 'issuesExcel'])
        ->name('staff.projects.exports.issues');

    Route::get('/projects/{project}/reports/summary.pdf', [ReportExportController::class, 'summaryPdf'])
        ->name('staff.projects.exports.summary');

    Route::get('/projects/{project}/reports/summary/view', [ReportExportController::class, 'summaryPdfView'])
        ->name('staff.projects.exports.summary.view');

    // Project Monitoring Report as an editable Word document (?period=YYYY-MM)
    Route::get('/projects/{project}/reports/report.docx', [ReportExportController::class, 'reportDocx'])
        ->name('staff.projects.exports.report.docx');

});

/*
|--------------------------------------------------------------------------
| Contractor Routes
|--------------------------------------------------------------------------
*/

Route::prefix('contractor')->middleware(['auth', 'active', 'role:contractor'])->group(function () {

    Route::get('/dashboard', [ContractorDashboardController::class, 'index'])
        ->name('contractor.dashboard');

    Route::get('/dashboard/search', [ContractorDashboardController::class, 'search'])
        ->name('contractor.dashboard.search');

    Route::get('/email', [ContractorEmailController::class, 'index'])->name('contractor.email');
    Route::get('/emails/{email}', [ContractorEmailController::class, 'show'])->name('contractor.emails.show');
    /*
    Route::get('/contractor/project', function () {
        return view('contractor.project');
    })->name('contractor.project');*/

    Route::get('/profile',
        [ProfileContractorController::class, 'index']
    )->name('contractor.profile');

    Route::get('/warranty', [ContractorWarrantyController::class, 'index'])
        ->name('contractor.warranty');

    Route::post('/warranty/store', [ContractorWarrantyController::class, 'store'])
        ->name('contractor.warranty.store');

    Route::post('/profile/update',
        [ProfileContractorController::class, 'update']
    )->name('contractor.profile.update');

    Route::post('/profile/change-password',
        [ProfileContractorController::class, 'changePassword']
    )->name('contractor.profile.change.password');

    // === CONTRACTOR PROJECTS ROUTES (SHARED) ===
    Route::get('/projects', [ProjectController::class, 'index'])
        ->name('contractor.projects');
    
    Route::get('/projects/{id}', [ProjectController::class, 'show'])
        ->name('projects.show');

    Route::get('/projects/{id}/chart-data', [ProjectController::class, 'getChartData'])
    ->name('contractor.projects.chart-data');

    // === CONTRACTOR EXTENSIONS ROUTES (READ-ONLY) ===
    Route::get('/projects/{project}/extensions', [ProjectExtensionController::class, 'index'])
        ->name('contractor.projects.extensions.index');
    
    Route::get('/projects/{project}/extensions/{extension}', [ProjectExtensionController::class, 'show'])
        ->name('contractor.projects.extensions.show');

    // === CONTRACTOR ISSUES ROUTES (READ-ONLY) ===
    Route::get('/projects/{project}/issues', [IssueController::class, 'getIssues'])
        ->name('contractor.projects.issues.list');

    // === CONTRACTOR RISK REGISTER ROUTES (READ-ONLY) ===
    Route::get('/projects/{project}/risks', [RiskController::class, 'getRisks'])
        ->name('contractor.projects.risks.list');

    // === CATEGORY ROUTES (READ-ONLY) ===
    Route::get('/categories', [CategoryController::class, 'getCategories'])
        ->name('contractor.categories.list');

    // === BUDGET SOURCE ROUTES (READ-ONLY for dropdown) ===
    Route::get('/budget-sources', [BudgetSourceController::class, 'getBudgetSources'])
        ->name('contractor.budget-sources.list');

// === DOCUMENT ROUTES (READ-ONLY) ===
    Route::get('/projects/{project}/documents', [DocumentController::class, 'getProjectDocuments'])
        ->name('contractor.documents.list');
 
    Route::get('/documents/{document}/view', [DocumentController::class, 'view'])
        ->name('contractor.documents.view');

    Route::get('/documents/{document}/preview', [DocumentController::class, 'previewHtml'])
        ->name('contractor.documents.preview');
 
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('contractor.documents.download');

    // === ATTACHMENT ROUTES (READ-ONLY) ===
    Route::get('/attachments/{attachment}/view', [AttachmentController::class, 'view'])
        ->name('contractor.attachments.view');

    Route::get('/attachments/{attachment}/preview', [AttachmentController::class, 'previewHtml'])
        ->name('contractor.attachments.preview');

    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('contractor.attachments.download');

    // === REPORT EXPORT ROUTES (READ-ONLY; contractor scoped to own project) ===
    Route::get('/projects/{project}/reports/progress.xlsx', [ReportExportController::class, 'progressExcel'])
        ->name('contractor.projects.exports.progress');

    Route::get('/projects/{project}/reports/baseline.xlsx', [ReportExportController::class, 'baselineExcel'])
        ->name('contractor.projects.exports.baseline');

    Route::get('/projects/{project}/reports/extensions.xlsx', [ReportExportController::class, 'extensionsExcel'])
        ->name('contractor.projects.exports.extensions');

    Route::get('/projects/{project}/reports/issues.xlsx', [ReportExportController::class, 'issuesExcel'])
        ->name('contractor.projects.exports.issues');

    Route::get('/projects/{project}/reports/summary.pdf', [ReportExportController::class, 'summaryPdf'])
        ->name('contractor.projects.exports.summary');

    Route::get('/projects/{project}/reports/summary/view', [ReportExportController::class, 'summaryPdfView'])
        ->name('contractor.projects.exports.summary.view');

    // Project Monitoring Report as an editable Word document (?period=YYYY-MM)
    Route::get('/projects/{project}/reports/report.docx', [ReportExportController::class, 'reportDocx'])
        ->name('contractor.projects.exports.report.docx');

    // === NOTIFICATION ROUTES ===
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('contractor.notifications');

    Route::get('/notifications/recent', [NotificationController::class, 'recent'])
        ->name('contractor.notifications.recent');

    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('contractor.notifications.read');

    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('contractor.notifications.readAll');

});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->middleware(['auth', 'active', 'role:admin'])->group(function () {

    Route::resource('users', UserManagementController::class);

    Route::get('/dashboard', [DashboardController::class, 'index'])
    ->name('admin.dashboard');

    Route::get('/dashboard/filter', [DashboardController::class, 'filter'])
    ->name('admin.dashboard.filter');

    Route::get('/dashboard/search', [DashboardController::class, 'search'])
    ->name('admin.dashboard.search');
    
    Route::get('/staff/{id}', [StaffController::class, 'show']);
    Route::post('/staff/{id}/change-role', [StaffController::class, 'changeRole']);
    // === PROJECT ROUTES (Merged from Teammate) ===
    Route::get('/projects', [ProjectController::class, 'index'])
        ->name('admin.projects');

    Route::post('/projects', [ProjectController::class, 'store'])
        ->name('admin.projects.store');
        
    Route::delete('/warranty/{id}', [WarrantyController::class, 'destroy'])
        ->name('admin.warranty.delete');

    Route::get('/projects/generate-code', [ProjectController::class, 'generateCode'])
        ->name('admin.projects.generateCode');

    Route::post('/projects/validate-code', [ProjectController::class, 'validateProjectCode'])
        ->name('admin.projects.validateCode');

    Route::post('/projects/validate-title', [ProjectController::class, 'validateProjectTitle'])
        ->name('admin.projects.validateTitle');

    Route::get('/projects/archived', [ProjectController::class, 'archived'])
        ->name('admin.projects.archived');

    Route::get('/projects/{id}', [ProjectController::class, 'show'])
        ->name('admin.projects.detail');
        
    Route::get('/projects/{id}/chart-data', [ProjectController::class, 'getChartData'])
    ->name('admin.projects.chart-data');

    Route::get('/projects/{id}/data', [ProjectController::class, 'getProjectData'])
        ->name('admin.projects.getData');

    Route::put('/projects/{id}', [ProjectController::class, 'update'])
        ->name('admin.projects.update');

    Route::post('/projects/{id}/update-progress', [ProjectController::class, 'updateProgress'])
        ->name('admin.projects.updateProgress');

    Route::post('/projects/{id}/archive', [ProjectController::class, 'archive'])
        ->name('admin.projects.archive');

    Route::post('/projects/{id}/restore', [ProjectController::class, 'restore'])
        ->name('admin.projects.restore');

    Route::delete('/projects/{id}', [ProjectController::class, 'delete'])
        ->name('admin.projects.destroy');

    Route::post('/projects/{project}/regenerate-analysis', [ProjectController::class, 'regenerateAnalysis'])
        ->name('admin.projects.regenerate-analysis');

    // === CONTRACTOR ROUTES ===
    Route::get('/contractors', [ContractorController::class, 'index'])->name('contractors.index');
    Route::post('/contractors', [ContractorController::class, 'store'])->name('contractors.store');
    Route::get('/contractors/{id}', [ContractorController::class, 'show'])->name('admin.contractors.show');
    Route::post('/contractors/{id}/toggle-status', [ContractorController::class, 'toggleStatus'])->name('admin.contractors.toggleStatus');

    // === PROGRESS HISTORY ROUTES ===
    Route::post('/projects/{project}/progress', [ProjectProgressController::class, 'storeProgress'])
        ->name('admin.projects.progress.store');
    Route::put('/projects/{project}/progress/{progressId}', [ProjectProgressController::class, 'editProgressRecord'])
        ->name('admin.projects.progress.update');
    Route::delete('/projects/{project}/progress/{progressId}', [ProjectProgressController::class, 'destroyProgress'])
        ->name('admin.projects.progress.destroy');
        

    // === ACTIVITY CHECKLIST: RECORD MONTHLY PROGRESS ROUTE ===
    Route::post('/projects/{project}/record-monthly-progress', [ProjectProgressController::class, 'recordMonthlyProgress'])
        ->name('admin.projects.record-monthly-progress');

    // === BASELINE SCHEDULE: CRUD ROUTES ===
    Route::post('/projects/{project}/baseline-schedules', [BaselineScheduleController::class, 'storeBaselineSchedule'])
        ->name('admin.projects.baseline-schedules.store');
    // NEW: atomic bulk-create for the initial "Set Up Baseline" modal (B3.2)
    Route::post('/projects/{project}/baseline-schedules/setup', [BaselineScheduleController::class, 'storeInitialBaselineSetup'])
        ->name('admin.projects.baseline-schedules.setup');
    Route::get('/projects/{project}/activities-by-month', [ProjectActivityController::class, 'getActivitiesByMonth'])
        ->name('admin.projects.activities.by-month');
    Route::delete('/projects/{project}/baseline-schedules/{scheduleId}', [BaselineScheduleController::class, 'deleteBaselineSchedule'])
        ->name('admin.projects.baseline-schedules.destroy');
    // NEW: edit a baseline schedule row (post-extension, no-actual-progress rows only — guarded in controller)
    Route::patch('/projects/{project}/baseline-schedules/{scheduleId}', [BaselineScheduleController::class, 'updateBaselineSchedule'])
        ->name('admin.projects.baseline-schedules.update');
    // Carry an incomplete activity over to a future baseline schedule month
    Route::post('/projects/{project}/activities/{activity}/carry-over', [BaselineScheduleController::class, 'carryOverActivity'])
        ->name('admin.projects.activities.carry-over');
    // Adjusted baseline generation (auto-distribution)
    Route::get('/projects/{project}/baseline-schedules/adjust', [BaselineScheduleController::class, 'getBaselineMonthsForAdjustment'])
        ->name('admin.projects.baseline-schedules.adjust');
    Route::post('/projects/{project}/baseline-schedules/adjust/save', [BaselineScheduleController::class, 'saveAdjustedBaseline'])
        ->name('admin.projects.baseline-schedules.adjust.save');
    // Admin-only "Edit Original Baseline" — corrects the locked original plan
    Route::get('/projects/{project}/baseline-schedules/original', [BaselineScheduleController::class, 'getOriginalBaselineMonths'])
        ->name('admin.projects.baseline-schedules.original');
    Route::post('/projects/{project}/baseline-schedules/original/save', [BaselineScheduleController::class, 'saveEditedOriginalBaseline'])
        ->name('admin.projects.baseline-schedules.original.save');

    // S-Curve Excel baseline import (Phase 2)
    Route::post('/s-curve/parse', [SCurveImportController::class, 'parseExcel'])
        ->name('admin.projects.s-curve.parse');

    // S-Curve AI fallback (E2 Excel Tier 3, opt-in)
    Route::post('/s-curve/parse-gemini', [SCurveImportController::class, 'parseWithGemini'])
        ->name('admin.projects.s-curve.parse-gemini');

    // S-Curve AI fallback (F-B PDF Tier 3, opt-in — page images only)
    Route::post('/s-curve/parse-pdf-gemini', [SCurveImportController::class, 'parsePdfWithGemini'])
        ->name('admin.projects.s-curve.parse-pdf-gemini');

    // AI fallback for normal-document parsing (Tier 3, opt-in — see AI plan)
    Route::post('/document/parse-gemini', [DocumentParserController::class, 'parseWithGemini'])
        ->name('admin.projects.document.parse-gemini');

    // === ACTIVITIES: CRUD ROUTES ===
    Route::post('/projects/{project}/activities', [ProjectActivityController::class, 'storeActivity'])
        ->name('admin.projects.activities.store');
    Route::delete('/projects/{project}/activities/{activityId}', [ProjectActivityController::class, 'deleteActivity'])
        ->name('admin.projects.activities.destroy');

    // === ISSUES: CRUD ROUTES ===
    Route::get('/projects/{project}/issues', [IssueController::class, 'getIssues'])
        ->name('admin.projects.issues.list');
    Route::post('/projects/{project}/issues', [IssueController::class, 'store'])
        ->name('admin.projects.issues.store');
    Route::put('/projects/{project}/issues/{issue}', [IssueController::class, 'update'])
        ->name('admin.projects.issues.update');
    Route::delete('/projects/{project}/issues/{issue}', [IssueController::class, 'destroy'])
        ->name('admin.projects.issues.destroy');

    // === RISK REGISTER: CRUD ROUTES ===
    Route::get('/projects/{project}/risks', [RiskController::class, 'getRisks'])
        ->name('admin.projects.risks.list');
    Route::post('/projects/{project}/risks', [RiskController::class, 'store'])
        ->name('admin.projects.risks.store');
    Route::put('/projects/{project}/risks/{risk}', [RiskController::class, 'update'])
        ->name('admin.projects.risks.update');
    Route::delete('/projects/{project}/risks/{risk}', [RiskController::class, 'destroy'])
        ->name('admin.projects.risks.destroy');
    Route::post('/projects/{project}/risks/{risk}/convert', [RiskController::class, 'convertToIssue'])
        ->name('admin.projects.risks.convert');

    // === PROJECT EXTENSIONS: CRUD ROUTES ===
    Route::post('/projects/{project}/extensions', [ProjectExtensionController::class, 'store'])
        ->name('admin.projects.extensions.store');
    Route::get('/projects/{project}/extensions', [ProjectExtensionController::class, 'index'])
        ->name('admin.projects.extensions.index');
    // NEW: archived (soft-deleted) extensions panel — admin only (Decision 12).
    // Registered BEFORE the {extension} show route so "archived" is not
    // swallowed as an extension id.
    Route::get('/projects/{project}/extensions/archived', [ProjectExtensionController::class, 'archived'])
        ->name('admin.projects.extensions.archived');
    // NEW: bulk archive / bulk permanent-delete (admin only, §8 bulk ops).
    // Registered before {extension} routes so "bulk-*" is not treated as an id.
    Route::post('/projects/{project}/extensions/bulk-delete', [ProjectExtensionController::class, 'bulkDestroy'])
        ->name('admin.projects.extensions.bulkDestroy');
    Route::post('/projects/{project}/extensions/bulk-force-delete', [ProjectExtensionController::class, 'bulkForceDestroy'])
        ->name('admin.projects.extensions.bulkForceDestroy');
    Route::post('/projects/{project}/extensions/bulk-restore', [ProjectExtensionController::class, 'bulkRestore'])
        ->name('admin.projects.extensions.bulkRestore');
    Route::get('/projects/{project}/extensions/{extension}', [ProjectExtensionController::class, 'show'])
        ->name('admin.projects.extensions.show');
    Route::put('/projects/{project}/extensions/{extension}', [ProjectExtensionController::class, 'update'])
        ->name('admin.projects.extensions.update');
    Route::delete('/projects/{project}/extensions/{extension}', [ProjectExtensionController::class, 'destroy'])
        ->name('admin.projects.extensions.destroy');
    // NEW: resubmit a rejected extension as a brand-new row (Decision 6).
    Route::post('/projects/{project}/extensions/{extension}/resubmit', [ProjectExtensionController::class, 'resubmit'])
        ->name('admin.projects.extensions.resubmit');
    // NEW: archived-panel actions — restore a soft-deleted row, or purge it
    // permanently (admin only, Decision 12). withTrashed() so the implicit
    // binding still resolves soft-deleted extension models.
    Route::post('/projects/{project}/extensions/{extension}/restore', [ProjectExtensionController::class, 'restore'])
        ->name('admin.projects.extensions.restore')
        ->withTrashed();
    Route::delete('/projects/{project}/extensions/{extension}/force', [ProjectExtensionController::class, 'forceDestroy'])
        ->name('admin.projects.extensions.forceDestroy')
        ->withTrashed();

    // Baseline snapshot endpoint — used by "Restore to Original" in adjust modal
    Route::get('/projects/{project}/extensions/latest-snapshot', [ProjectExtensionController::class, 'latestSnapshot'])
        ->name('admin.projects.extensions.latestSnapshot');

    // === MILESTONE ROUTES ===
    Route::get('/projects/{projectId}/milestones', [MilestoneController::class, 'index'])
        ->name('admin.milestones.index');

    Route::post('/projects/{projectId}/milestones', [MilestoneController::class, 'store'])
        ->name('admin.milestones.store');

    Route::post('/projects/{projectId}/milestones/bulk', [MilestoneController::class, 'bulkStore'])
        ->name('admin.milestones.bulkStore');

    Route::put('/projects/{projectId}/milestones/{milestoneId}', [MilestoneController::class, 'update'])
        ->name('admin.milestones.update');

    Route::delete('/projects/{projectId}/milestones/{milestoneId}', [MilestoneController::class, 'destroy'])
        ->name('admin.milestones.destroy');

    Route::post('/projects/{projectId}/milestones/reorder', [MilestoneController::class, 'reorder'])
        ->name('admin.milestones.reorder');

    Route::get('/projects/{projectId}/timeline', [MilestoneController::class, 'timeline'])
        ->name('admin.milestones.timeline');

    // === CATEGORY ROUTES ===
    Route::get('/categories', [CategoryController::class, 'getCategories'])
        ->name('admin.categories.list');
    
    Route::get('/categories/all', [CategoryController::class, 'getAllForManagement'])
        ->name('admin.categories.all');

    Route::post('/categories', [CategoryController::class, 'store'])
        ->name('admin.categories.store');

    Route::put('/categories/{category}', [CategoryController::class, 'update'])
        ->name('admin.categories.update');

    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
        ->name('admin.categories.destroy');

    // Budget Sources
    Route::get('/budget-sources', [BudgetSourceController::class, 'getBudgetSources'])
        ->name('admin.budget-sources.list');

    Route::get('/budget-sources/all', [BudgetSourceController::class, 'getAllForManagement'])
        ->name('admin.budget-sources.all');

    Route::post('/budget-sources', [BudgetSourceController::class, 'store'])
        ->name('admin.budget-sources.store');

    Route::put('/budget-sources/{budgetSource}', [BudgetSourceController::class, 'update'])
        ->name('admin.budget-sources.update');

    Route::delete('/budget-sources/{budgetSource}', [BudgetSourceController::class, 'destroy'])
        ->name('admin.budget-sources.destroy');

    // === PARSER LEARNING LOOP (L1 silent collection; L2 review UI lands here later) ===
    Route::post('/parser-keyword-proposals/collect', [ParserKeywordProposalController::class, 'collect'])
        ->name('admin.parser-keywords.collect');

    // === PARSER LEARNING LOOP (L2 review: staff + admin per Decision L0a) ===
    Route::get('/parser-keyword-proposals/all', [ParserKeywordProposalController::class, 'getAllForManagement'])
        ->name('admin.parser-keywords.all');

    Route::patch('/parser-keyword-proposals/{proposal}/approve', [ParserKeywordProposalController::class, 'approve'])
        ->name('admin.parser-keywords.approve');

    Route::delete('/parser-keyword-proposals/{proposal}', [ParserKeywordProposalController::class, 'destroy'])
        ->name('admin.parser-keywords.destroy');

    // Deleted rejected wording (2026-09-21): soft-delete (admin + staff), then the
    // admin-only Deleted tab with restore / permanent delete. withTrashed() lets the
    // binding find a soft-deleted row; the controller checks it really is trashed.
    Route::delete('/parser-keyword-proposals/{proposal}/trash', [ParserKeywordProposalController::class, 'trash'])
        ->name('admin.parser-keywords.trash');

    Route::get('/parser-keyword-proposals/deleted', [ParserKeywordProposalController::class, 'getDeleted'])
        ->name('admin.parser-keywords.deleted');

    Route::patch('/parser-keyword-proposals/{proposal}/restore', [ParserKeywordProposalController::class, 'restore'])
        ->withTrashed()
        ->name('admin.parser-keywords.restore');

    Route::delete('/parser-keyword-proposals/{proposal}/force', [ParserKeywordProposalController::class, 'forceDestroy'])
        ->withTrashed()
        ->name('admin.parser-keywords.force-destroy');

    // === S-CURVE HEADER LEARNING (E1-1 silent capture; staff + admin, contractors excluded) ===
    Route::post('/scurve-header-proposals/collect', [SCurveHeaderProposalController::class, 'collect'])
        ->name('admin.scurve-headers.collect');

    // === S-CURVE HEADER REVIEW (E1-2 desk; staff + admin per E1b) ===
    Route::get('/scurve-header-proposals/all', [SCurveHeaderProposalController::class, 'getAllForManagement'])
        ->name('admin.scurve-headers.all');

    Route::patch('/scurve-header-proposals/{proposal}/approve', [SCurveHeaderProposalController::class, 'approve'])
        ->name('admin.scurve-headers.approve');

    // === S-CURVE HEADER TEACHING (Phase-A point-and-teach; staff + admin per E1b) ===
    Route::patch('/scurve-header-proposals/{proposal}/columns', [SCurveHeaderProposalController::class, 'teachColumns'])
        ->name('admin.scurve-headers.columns');

    Route::delete('/scurve-header-proposals/{proposal}', [SCurveHeaderProposalController::class, 'destroy'])
        ->name('admin.scurve-headers.destroy');

    // Deleted rejected layouts (2026-09-21, parity with the keyword desk):
    // soft-delete (admin + staff), then the admin-only Deleted tab with restore /
    // permanent delete. withTrashed() lets the binding find a soft-deleted row.
    Route::delete('/scurve-header-proposals/{proposal}/trash', [SCurveHeaderProposalController::class, 'trash'])
        ->name('admin.scurve-headers.trash');

    Route::get('/scurve-header-proposals/deleted', [SCurveHeaderProposalController::class, 'getDeleted'])
        ->name('admin.scurve-headers.deleted');

    Route::patch('/scurve-header-proposals/{proposal}/restore', [SCurveHeaderProposalController::class, 'restore'])
        ->withTrashed()
        ->name('admin.scurve-headers.restore');

    Route::delete('/scurve-header-proposals/{proposal}/force', [SCurveHeaderProposalController::class, 'forceDestroy'])
        ->withTrashed()
        ->name('admin.scurve-headers.force-destroy');

    // === DOCUMENT ROUTES ===
    Route::post('/documents/upload', [DocumentController::class, 'upload'])
        ->name('admin.documents.upload');

    Route::get('/projects/{project}/documents', [DocumentController::class, 'getProjectDocuments'])
        ->name('admin.documents.list');

    Route::delete('/documents/{document}', [DocumentController::class, 'delete'])
        ->name('admin.documents.destroy');

    Route::get('/documents/types', [DocumentController::class, 'getDocumentTypes'])
        ->name('admin.documents.types');

    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('admin.documents.download');

    Route::get('/documents/{document}/view', [DocumentController::class, 'view'])
        ->name('admin.documents.view');

    Route::get('/documents/{document}/preview', [DocumentController::class, 'previewHtml'])
        ->name('admin.documents.preview');

    // === ATTACHMENT ROUTES ===
    Route::post('/attachments/upload', [AttachmentController::class, 'upload'])
        ->name('admin.attachments.upload');

    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'delete'])
        ->name('admin.attachments.destroy');

    Route::get('/attachments/{attachment}/view', [AttachmentController::class, 'view'])
        ->name('admin.attachments.view');

    Route::get('/attachments/{attachment}/preview', [AttachmentController::class, 'previewHtml'])
        ->name('admin.attachments.preview');

    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('admin.attachments.download');

    // === REPORT EXPORT ROUTES ===
    Route::get('/projects/{project}/reports/progress.xlsx', [ReportExportController::class, 'progressExcel'])
        ->name('admin.projects.exports.progress');

    Route::get('/projects/{project}/reports/baseline.xlsx', [ReportExportController::class, 'baselineExcel'])
        ->name('admin.projects.exports.baseline');

    Route::get('/projects/{project}/reports/extensions.xlsx', [ReportExportController::class, 'extensionsExcel'])
        ->name('admin.projects.exports.extensions');

    Route::get('/projects/{project}/reports/issues.xlsx', [ReportExportController::class, 'issuesExcel'])
        ->name('admin.projects.exports.issues');

    Route::get('/projects/{project}/reports/summary.pdf', [ReportExportController::class, 'summaryPdf'])
        ->name('admin.projects.exports.summary');

    Route::get('/projects/{project}/reports/summary/view', [ReportExportController::class, 'summaryPdfView'])
        ->name('admin.projects.exports.summary.view');

    // Project Monitoring Report as an editable Word document (?period=YYYY-MM)
    Route::get('/projects/{project}/reports/report.docx', [ReportExportController::class, 'reportDocx'])
        ->name('admin.projects.exports.report.docx');

    // === NOTIFICATION ROUTES ===
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('admin.notifications');

    Route::get('/notifications/recent', [NotificationController::class, 'recent'])
        ->name('admin.notifications.recent');

    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('admin.notifications.read');

    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('admin.notifications.readAll');

    // === DOCUMENT TYPE MANAGEMENT ROUTES ===
    Route::get('/document-types/all', [DocumentTypeController::class, 'getAllForManagement'])
        ->name('admin.document-types.all');

    Route::post('/document-types', [DocumentTypeController::class, 'store'])
        ->name('admin.document-types.store');

    Route::put('/document-types/{documentType}', [DocumentTypeController::class, 'update'])
        ->name('admin.document-types.update');

    Route::delete('/document-types/{documentType}', [DocumentTypeController::class, 'destroy'])
        ->name('admin.document-types.destroy');

    /* ===== ENHANCED CONTRACTOR ROUTES (Commented Out - For Future Use) =====
    Route::get('/contractors/list', [ContractorController::class, 'list'])
        ->name('admin.contractors.list');
    Route::post('/contractors', [ContractorController::class, 'store'])
        ->name('admin.contractors.store');
    Route::get('/contractors/{id}', [ContractorController::class, 'show'])
        ->name('admin.contractors.show');
    Route::put('/contractors/{id}', [ContractorController::class, 'update'])
        ->name('admin.contractors.update');
    Route::delete('/contractors/{id}', [ContractorController::class, 'destroy'])
        ->name('admin.contractors.destroy');
    // ===== END ENHANCED CONTRACTOR ROUTES ===== */

    // === PROPOSAL ROUTES ===
    Route::get('/proposals/list', [ProposalController::class, 'list'])
        ->name('admin.proposals.list');

    Route::post('/proposals', [ProposalController::class, 'store'])
        ->name('admin.proposals.store');

    Route::get('/proposals/{id}', [ProposalController::class, 'show'])
        ->name('admin.proposals.show');

    Route::get('/proposals/{id}/download', [ProposalController::class, 'download'])
        ->name('admin.proposals.download');

    Route::get('/warranty', [WarrantyController::class, 'index'])
        ->name('admin.warranty');

    Route::get('/warranty/preview', [WarrantyController::class, 'preview'])
        ->name('admin.warranty.preview');

    Route::post('/warranty', [WarrantyController::class, 'store'])
        ->name('admin.warranty.store');

    Route::get('/rating', [RatingController::class, 'index'])->name('admin.rating');
    Route::post('/rating/suggest', [RatingController::class, 'suggest'])->name('admin.rating.suggest');
    Route::post('/rating/contractor-overview', [RatingController::class, 'contractorOverview'])->name('admin.rating.contractor-overview');
    Route::post('/rating/store', [RatingController::class, 'store'])->name('admin.rating.store');
    Route::post('/rating/auto-rate-all', [RatingController::class, 'autoRateAll'])->name('admin.rating.auto-rate-all');
    Route::post('/rating/{contractor}/recompute-performance', [RatingController::class, 'recomputePerformance'])->name('admin.rating.recompute-performance');
    
    Route::get('/email', [AdminEmailController::class, 'index'])->name('admin.email');
    Route::post('/emails', [AdminEmailController::class, 'store'])->name('admin.emails.store');
    Route::get('/emails/{email}/edit', [AdminEmailController::class, 'edit'])->name('admin.emails.edit');
    Route::put('/emails/{email}', [AdminEmailController::class, 'update'])->name('admin.emails.update');
    Route::delete('/emails/{email}', [AdminEmailController::class, 'destroy'])->name('admin.emails.destroy');
    Route::post('/emails/{email}/send', [AdminEmailController::class, 'sendDraft'])->name('admin.emails.send');
    Route::get('/emails/{email}', [AdminEmailController::class, 'show'])->name('admin.emails.show');
    Route::post('/emails/{email}/mark-read', [AdminEmailController::class, 'markAsRead'])->name('admin.emails.mark-read');
    Route::post('/email/send-now', [AdminEmailController::class, 'sendNow'])->name('admin.email.send-now');
    Route::post('/email/send-one', [AdminEmailController::class, 'sendOne'])->name('admin.email.send-one');
    Route::post('/email/toggle-auto-send', [AdminEmailController::class, 'toggleAutoSend'])->name('admin.email.toggle-auto-send');

    Route::get('/activity-logs',
        [ActivityLogController::class, 'index']
    )->name('admin.activity.logs');

    // ✅ ADMIN PROFILE ROUTES
    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('admin.profile');

    Route::post('/profile/update', [ProfileController::class, 'update'])
        ->name('admin.profile.update');

    Route::post('/profile/change-password', [ProfileController::class, 'changePassword'])
        ->name('admin.profile.change.password');

    // === STAFF CRUD ROUTES ===
    Route::get('/staff', [StaffController::class, 'index'])->name('admin.staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('admin.staff.store');
    Route::put('/staff/{id}', [StaffController::class, 'update'])->name('admin.staff.update');
    Route::post('/staff/{id}/toggle-status', [StaffController::class, 'toggleStatus'])->name('admin.staff.toggleStatus');

    Route::view('/project-view', 'project-view');
});




/*
|--------------------------------------------------------------------------
| Logout Route
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Email Tracking Pixel (Public — no auth)
|--------------------------------------------------------------------------
*/
Route::get('/track/{token}', function (string $token) {
    $tracking = App\Models\EmailTracking::where('tracking_token', $token)->first();
    if ($tracking) {
        if (!$tracking->sent_at) {
            $tracking->update(['sent_at' => now()]);
        }
        if (!$tracking->delivered_at) {
            $tracking->update(['delivered_at' => now()]);
        }
        if (!$tracking->opened_at) {
            $tracking->update([
                'opened_at' => now(),
                'is_read' => true,
                'open_count' => $tracking->open_count + 1,
            ]);
        } else {
            $tracking->increment('open_count');
        }
    }

    return response(base64_decode(
        'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'
    ))->header('Content-Type', 'image/gif');
})->name('tracking.open');

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

/*
|--------------------------------------------------------------------------
| Authenticated Shared Routes
|--------------------------------------------------------------------------
*/

// Shared delete project endpoint for admin/staff (authorization checked in controller)
Route::middleware(['auth'])->delete('/projects/{id}', [ProjectController::class, 'destroy'])
    ->name('projects.destroy.shared');

/*
|--------------------------------------------------------------------------
| GOOGLE CALENDAR (project dates auto-sync + dashboard calendar)
|--------------------------------------------------------------------------
|
| Login requests calendar consent up front (AuthController); these cover
| already-logged-in users (connect without re-login), status/disconnect,
| per-project manual re-sync, and the no-consent .ics fallback used by the
| dashboard calendar modal and the project header.
|
*/

Route::middleware(['auth'])->group(function () {
    Route::get('/calendar/google/connect', [App\Http\Controllers\GoogleCalendarController::class, 'connect'])
        ->name('calendar.google.connect');
    Route::get('/calendar/google/callback', [App\Http\Controllers\GoogleCalendarController::class, 'callback'])
        ->name('calendar.google.callback');
    Route::get('/calendar/google/status', [App\Http\Controllers\GoogleCalendarController::class, 'status'])
        ->name('calendar.google.status');
    Route::post('/calendar/google/disconnect', [App\Http\Controllers\GoogleCalendarController::class, 'disconnect'])
        ->name('calendar.google.disconnect');

    Route::post('/calendar/projects/{project}/sync', [App\Http\Controllers\GoogleCalendarController::class, 'sync'])
        ->name('calendar.project.sync');
    Route::get('/calendar/projects/{project}/ics', [App\Http\Controllers\GoogleCalendarController::class, 'ics'])
        ->name('calendar.project.ics');
});

/*
|--------------------------------------------------------------------------
| GMAIL API
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| DEV TOOLS (non-production only)
|--------------------------------------------------------------------------
*/
if (app()->environment() !== 'production') {
    Route::prefix('dev')->middleware('auth')->name('dev.')->group(function () {
        Route::get('/date', [App\Http\Controllers\Admin\DevDateController::class, 'get'])->name('date.get');
        Route::post('/date/set', [App\Http\Controllers\Admin\DevDateController::class, 'set'])->name('date.set');
        Route::post('/date/clear', [App\Http\Controllers\Admin\DevDateController::class, 'clear'])->name('date.clear');

        Route::post('/notifications/scan', [App\Http\Controllers\Admin\DevNotificationController::class, 'scan'])
            ->name('notifications.scan');

        Route::post('/notifications/clear', [App\Http\Controllers\Admin\DevNotificationController::class, 'clear'])
            ->name('notifications.clear');

        Route::get('/prediction-accuracy', [App\Http\Controllers\Admin\DevPredictionAccuracyController::class, 'index'])
            ->name('prediction-accuracy.index');
    });
}

/*
|--------------------------------------------------------------------------
| ERROR PAGES
|--------------------------------------------------------------------------
*/

Route::get('/unauthorized', fn() => response()->view('errors.unauthorized', [], 403))->name('unauthorized');

Route::fallback(fn() => response()->view('errors.404', [], 404));
