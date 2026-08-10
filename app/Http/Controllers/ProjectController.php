<?php

/**
 * === CONSOLIDATED PROJECT CONTROLLER ===
 * 
 * This controller handles project management for ALL ROLES:
 * - Admin: Full access to create, read, update, delete projects
 * - Staff: Full access to create, read, update, delete projects (with full permissions)
 * - Contractor: Read-only access to their assigned projects
 * 
 * Authorization checks are implemented at the method level:
 * - index() & show(): Role-based filtering applied (contractors see only their projects)
 * - store(), update(), getProjectData(), generateCode(), etc.: Admin/Staff only
 * - destroy(): Admin/Staff only
 * 
 * This file was consolidated from:
 * - app/Http/Controllers/ProjectController.php (original shared controller)
 * - app/Http/Controllers/Admin/ProjectController.php (admin-specific, now merged here)
 * 
 * Routes point to this single controller to avoid duplication and maintain consistency.
 */

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Staff;
use App\Models\Project;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Contractor;
use App\Models\Proposal;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectActivity;
use App\Models\ProjectProgressHistory;
use App\Services\AI\AIPredictionPresenter;
use App\Services\AI\AIExplanationService;
use App\Services\AIReportSendingService;
use App\Services\ProjectDetailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    /**
     * === LIST ALL PROJECTS (SHARED - ALL ROLES) ===
     * 
     * Filters projects based on user role:
     * - Admin/Staff: See all projects
     * - Contractor: See only their assigned projects
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // Build query
        $query = Project::with('contractor')
            ->where('is_archived', false);
        
        // Apply role-specific filtering
        if ($user->role === 'contractor') {
            // Contractors can only see their assigned projects
            // A contractor is assigned to a project via project.contractor_id = contractor.id
            // and contractor.user_id = auth user id
            $query->whereHas('contractor', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        } else {
            // Admin/Staff: optional deep-link filters from the dashboard stat cards.
            //   ?status=Ongoing|Completed|Planned|... — exact status match
            //   ?filter=overdue|atrisk|pending         — health buckets (ongoing only)
            $status = $request->get('status');
            $filter = $request->get('filter');

            if (in_array($status, ['Ongoing', 'Completed', 'Planned', 'Suspended', 'Cancelled'])) {
                $query->where('status', $status);
            } elseif ($filter === 'overdue') {
                $query->where('status', 'Ongoing')->where('planned_end_date', '<', now());
            } elseif ($filter === 'atrisk') {
                $query->where('status', 'Ongoing')
                    ->whereBetween('planned_end_date', [now(), now()->addDays(14)]);
            } elseif ($filter === 'pending') {
                $projectsWithRecord = ProjectProgressHistory
                    ::where('month_label', 'like', now()->format('Y-m') . '%')
                    ->distinct()
                    ->pluck('project_id');
                $query->where('status', 'Ongoing')
                    ->whereNotIn('id', $projectsWithRecord);
            } elseif ($filter === 'inspection_overdue') {
                $graceDays = (int) \App\Models\Setting::getValue('notification_overdue_grace_days', 7);
                $query->where('status', 'Ongoing')
                    ->whereHas('baselineSchedules', function ($q) use ($graceDays) {
                        $q->whereNotNull('month_label')
                            ->where('planned_date', '<', now()->subDays($graceDays))
                            ->whereNotIn('month_label', function ($sub) {
                                $sub->select('month_label')
                                    ->from('project_progress_history')
                                    ->whereColumn('project_id', 'project_baseline_schedules.project_id')
                                    ->whereNotNull('month_label');
                            });
                    });
            }
        }
        // Admin and Staff see all projects (no additional filter)
        
        $projects = $query->latest()->paginate(15)->withQueryString();
        
        //Prepare data array
        $data = compact('projects');
        
        //Add extra data for admin/staff only
        if (in_array($user->role, ['admin', 'staff'])) {
            $data['contractors'] = Contractor::with('user')->get();
             // Get all admins and staff for project manager dropdown
            $data['admins'] = Admin::with('user')->get();
            $data['staff'] = Staff::with('user')->get();
             // Dashboard metrics
            $data['metrics'] = [
                'active_projects' => Project::where('is_archived', false)
                    ->whereIn('status', ['Ongoing', 'Planned'])
                    ->count(),
                'completed_projects' => Project::where('status', 'Completed')->count(),
                'at_risk_projects' => 0, // Will calculate per project
                'total_budget' => Project::where('is_archived', false)->sum('approved_budget'),
            ];
        }
        
        //return view('admin.projects', $data);
        
        // Add document types for modal
        $data['documentTypes'] = DocumentType::active()->orderBy('name')->get();
        
        return view('projects.index', $data);
    }

    /**
     * === SHOW PROJECT DETAILS (SHARED - ALL ROLES) ===
     * 
     * Authorization:
     * - Admin: Can view any project
     * - Staff: Can view any project
     * - Contractor: Can only view their assigned project
     */
    public function show($id)
    {
        $user = Auth::user();
        $project = Project::with([
            'contractor',  
            'baselineSchedules', 
            'activities', 
            'progressHistory',
            'documents.documentType'
        ])->findOrFail($id);
        
        // Check authorization
        if (!in_array($user->role, ['admin', 'staff'])) {
            if ($user->role === 'contractor') {
                $contractor = $user->contractor;
                if (!$contractor || $project->contractor_id !== $contractor->id) {
                    abort(403, 'Unauthorized: You can only view your assigned projects.');
                }
            } else {
                abort(403, 'Unauthorized: You do not have permission to view this project.');
            }
        }
        
        // Use the service to prepare data
        // Resolved via service container because ProjectDetailService
        // now requires constructor injection (AIPredictionPresenter).
        $service = app(ProjectDetailService::class);
        $data = $service->prepareProjectData($project);

        // Initialize variables with empty/null values
        $contractors = collect();
        $admins = collect();
        $staff = collect();

        // Add extra data for admin/staff only
        if (in_array($user->role, ['admin', 'staff'])) {
            $contractors = Contractor::with('user')->get();
            $admins = Admin::with('user')->get();
            $staff = Staff::with('user')->get();
        }

        // Add document types for modal
        $documentTypes = DocumentType::active()->orderBy('name')->get();

        // Use the consolidated detail view
        return view('projects.project_detail.project_detail', compact('project', 'data', 'contractors', 'admins', 'staff', 'documentTypes'));
    }

    /**
 * === GET CHART DATA FOR PROGRESS TAB (AJAX - ALL ROLES) ===
 *
 * Called lazily when the user clicks the Progress tab.
 * Runs the heavy S-curve calculations only on demand.
 */
    public function getChartData($id)
    {
        $user = Auth::user();
        $project = Project::findOrFail($id);

        // Same authorization as show()
        if (!in_array($user->role, ['admin', 'staff'])) {
            if ($user->role === 'contractor') {
                $contractor = $user->contractor;
                if (!$contractor || $project->contractor_id !== $contractor->id) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
                }
            } else {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }
        }

        $service = app(ProjectDetailService::class);
        $chartData    = $service->prepareChartData($project);
        $trendData    = $service->prepareTrendData($project);

        /*
        |--------------------------------------------------------------------------
        | AI Prediction for AJAX Response
        |--------------------------------------------------------------------------
        |
        | Uses AIPredictionPresenter directly (not via ProjectDetailService)
        | to avoid the overhead of prepareProjectData() on AJAX calls.
        |
        */

        $presenter    = app(AIPredictionPresenter::class);
        $aiPrediction = $presenter->present($project);

        /*
        |--------------------------------------------------------------------------
        | Forecast Chart Data (Phase 5)
        |--------------------------------------------------------------------------
        |
        | Computes polynomial near-term forecast points (Chart 1) and shifted
        | baseline curves for Schedule-Aware / EAC_time completion estimates
        | (Chart 2). Pure array manipulation on already-computed data.
        |
        */

        $forecastData = $service->prepareForecastData($chartData, $aiPrediction);

        return response()->json([
            'success'             => true,
            'charts'              => $chartData,
            'ai_prediction'       => $aiPrediction,
            'polynomial_forecast' => $forecastData['polynomial_forecast'],
            'completion_forecast' => $forecastData['completion_forecast'],
            'trends'              => $trendData,
        ]);
    }

    // === GENERATE UNIQUE PROJECT CODE (ADMIN/STAFF ONLY) ===
    // Protected by route middleware: 'role:admin' or 'role:staff'
    public function generateCode(Request $request)
    {
        return response()->json([
            'success' => true,
            'code' => Project::generateCode(),
        ]);
    }

    // === VALIDATE PROJECT CODE (check uniqueness - ADMIN/STAFF ONLY) ===
    // Protected by route middleware: 'role:admin' or 'role:staff'
    public function validateProjectCode(Request $request)
    {
        $code = $request->input('code');
        $projectId = $request->input('project_id'); // For edit mode, exclude current project

        if (!$code) {
            return response()->json([
                'success' => false,
                'is_unique' => false,
                'message' => 'Project code is required'
            ], 422);
        }

        // Check if code exists (excluding current project in edit mode)
        $query = Project::where('code', $code);
        if ($projectId) {
            $query->where('id', '!=', $projectId);
        }
        
        $exists = $query->exists();

        return response()->json([
            'success' => true,
            'is_unique' => !$exists,
            'code' => $code,
            'message' => $exists ? 'This project code already exists' : 'Project code is available'
        ]);
    }

    // === VALIDATE PROJECT TITLE (check uniqueness - ADMIN/STAFF ONLY) ===
    // Protected by route middleware: 'role:admin' or 'role:staff'
    public function validateProjectTitle(Request $request)
    {
        $title = $request->input('title');
        $projectId = $request->input('project_id'); // For edit mode, exclude current project

        if (!$title) {
            return response()->json([
                'success' => false,
                'is_unique' => false,
                'message' => 'Project title is required'
            ], 422);
        }

        // Check if title exists (excluding current project in edit mode)
        $query = Project::where('title', $title);
        if ($projectId) {
            $query->where('id', '!=', $projectId);
        }
        
        $exists = $query->exists();

        return response()->json([
            'success' => true,
            'is_unique' => !$exists,
            'title' => $title,
            'message' => $exists ? 'This project title already exists' : 'Project title is available'
        ]);
    }

    // === STORE NEW PROJECT (ADMIN/STAFF ONLY) ===
    // Protected by route middleware: 'role:admin' or 'role:staff'
    // Staff can create projects, but cannot delete them
    public function store(Request $request)
    {
        // Check if title already exists
        if (Project::titleExists($request->input('title'))) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A project with this title already exists. Please use a different title.',
                    'errors' => ['title' => ['A project with this title already exists.']]
                ], 422);
            }
            return back()->withErrors(['title' => 'A project with this title already exists.'])->withInput();
        }

        // === DATA VALIDATION ===
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100', 'unique:projects,code'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'category' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'approved_budget' => ['required', 'numeric', 'min:0'],
            'planned_start_date' => ['nullable', 'date'],
            'planned_duration_days' => ['nullable', 'integer', 'min:1'],
            'planned_end_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'planned_handing_over_date' => ['nullable', 'date', 'after_or_equal:planned_end_date'],
            'actual_start_date' => ['nullable', 'date'],
            'actual_end_date' => ['nullable', 'date', 'after_or_equal:actual_start_date'],
            'status' => ['nullable', 'in:Planned,Ongoing,Completed,Suspended,Cancelled'],
            'assigned_project_manager' => ['nullable', 'string', 'max:255'],
            'project_manager_contact' => ['nullable', 'string', 'max:50'],
            'estimated_workers' => ['nullable', 'integer', 'min:1'],
            'contractor_id' => ['nullable', 'integer', 'exists:contractors,id'],
            'signed_contract' => ['nullable', 'file', 'max:20480'],
            'supporting_documents.*' => ['nullable', 'file', 'max:20480'],
            'pending_document_files.*' => ['nullable', 'file', 'max:20480'],
            'pending_document_types.*' => ['nullable', 'integer', 'exists:document_types,id'],
        ];

        // Add proposal validation rules if proposal data is present
        if ($request->hasFile('proposal_file')) {
            $rules['proposal_file'] = ['required', 'file', 'mimes:pdf', 'max:10240'];
            $rules['proposal_project_title'] = ['required', 'string', 'max:255'];
            $rules['proposal_project_description'] = ['required', 'string'];
            $rules['proposal_proposed_project_name'] = ['nullable', 'string', 'max:255'];
            $rules['proposal_category'] = ['required', 'in:new_construction,renovation,repair,maintenance'];
            $rules['proposal_location_site'] = ['required', 'string', 'max:255'];
            $rules['proposal_contractor_id'] = ['nullable', 'integer', 'exists:contractors,id'];
            $rules['proposal_proposed_start_date'] = ['required', 'date'];
            $rules['proposal_proposed_duration_days'] = ['nullable', 'integer', 'min:1'];
            $rules['proposal_proposed_end_date'] = ['nullable', 'date'];
            $rules['proposal_estimated_budget'] = ['required', 'numeric', 'min:0'];
            $rules['proposal_date_submitted'] = ['nullable', 'date_format:Y-m-d\TH:i'];
            $rules['proposal_evaluation_remarks'] = ['nullable', 'string'];
        }

        $data = $request->validate($rules);

        // Generate code if not provided
        if (empty($data['code'])) {
            $data['code'] = Project::generateCode();
        }

        // If planned_end_date not provided but start + duration provided, calculate
        if (empty($data['planned_end_date']) && !empty($data['planned_start_date']) && !empty($data['planned_duration_days'])) {
            $data['planned_end_date'] = date('Y-m-d', strtotime($data['planned_start_date'] . ' +' . ($data['planned_duration_days'] - 1) . ' days'));
        }

        // Extract file objects before creating project - they'll be stored after
        $signedContractFile = $request->file('signed_contract');
        $supportingDocumentFiles = $request->file('supporting_documents') ?? [];
        
        // Extract pending documents from modal (new format)
        $pendingDocuments = [];
        
        // Get arrays of files and types
        $documentFiles = $request->file('pending_document_files') ?? [];
        $documentTypes = $request->input('pending_document_types') ?? [];
        
        // Pair them together
        if (is_array($documentFiles) && count($documentFiles) > 0) {
            foreach ($documentFiles as $index => $file) {
                if ($file && isset($documentTypes[$index])) {
                    $pendingDocuments[] = [
                        'file' => $file,
                        'document_type_id' => $documentTypes[$index]
                    ];
                }
            }
        }
        
        // Debug: Log what we received
        Log::debug('Pending documents extracted', [
            'pending_document_files_count' => count($documentFiles),
            'pending_document_types_count' => count($documentTypes),
            'pending_documents_count' => count($pendingDocuments),
            'request_all_keys' => array_keys($request->all())
        ]);

        $data['status'] = $data['status'] ?? 'Planned';
        $data['is_archived'] = false;

        // === VALIDATE BASELINE TARGETS AGAINST PROJECT TIMELINE ===
        // The baseline IS the plan — every target must fall within the
        // project's planned start/end dates. Fails fast BEFORE the project
        // is created so nothing is written when the plan is out of bounds.
        $baselineScheduleTargets = $request->input('baseline_schedule_targets');

        if ($baselineScheduleTargets) {
            if (is_string($baselineScheduleTargets)) {
                $baselineScheduleTargets = json_decode($baselineScheduleTargets, true);
            }

            if (is_array($baselineScheduleTargets) && count($baselineScheduleTargets) > 0) {
                $projectStart = !empty($data['planned_start_date']) ? \Carbon\Carbon::parse($data['planned_start_date']) : null;
                $projectEnd   = !empty($data['planned_end_date']) ? \Carbon\Carbon::parse($data['planned_end_date']) : null;

                if (!$projectStart || !$projectEnd) {
                    return back()->withErrors([
                        'planned_start_date' => 'Set the project planned start and end dates before adding baseline targets.'
                    ])->withInput();
                }

                foreach ($baselineScheduleTargets as $target) {
                    $plannedDate = !empty($target['planned_date']) ? \Carbon\Carbon::parse($target['planned_date']) : null;
                    if (!$plannedDate) {
                        return back()->withErrors([
                            'baseline_schedule_targets' => 'Each baseline target requires a planned date.'
                        ])->withInput();
                    }
                    if ($plannedDate->lt($projectStart)) {
                        return back()->withErrors([
                            'baseline_schedule_targets' => "Baseline target {$target['planned_date']} is before the project's planned start date ({$projectStart->format('Y-m-d')})."
                        ])->withInput();
                    }
                    if ($plannedDate->gt($projectEnd)) {
                        return back()->withErrors([
                            'baseline_schedule_targets' => "Baseline target {$target['planned_date']} is after the project's planned end date ({$projectEnd->format('Y-m-d')})."
                        ])->withInput();
                    }
                }
            }
        }

        // Use a transaction so proposal + project are saved atomically
        $project = DB::transaction(function () use ($data, $request) {

            // === CREATE PROPOSAL IF DATA IS PRESENT ===
            $proposalId = null;
            if ($request->hasFile('proposal_file')) {
                $proposalFile = $request->file('proposal_file');
                $proposalFileName = Str::slug($data['proposal_project_title'] ?? 'proposal', '-') . '-proposal-' . time() . '.pdf';
                $proposalFilePath = $proposalFile->storeAs('proposals', $proposalFileName, 'public');

                // Calculate missing date/duration for proposal
                $proposalStartDate = $data['proposal_proposed_start_date'] ?? null;
                $proposalDuration = !empty($data['proposal_proposed_duration_days']) ? (int)$data['proposal_proposed_duration_days'] : null;
                $proposalEndDate = !empty($data['proposal_proposed_end_date']) ? $data['proposal_proposed_end_date'] : null;

                if ($proposalStartDate && $proposalDuration && !$proposalEndDate) {
                    $proposalEndDate = date('Y-m-d', strtotime($proposalStartDate . ' +' . ($proposalDuration - 1) . ' days'));
                } elseif ($proposalStartDate && $proposalEndDate && !$proposalDuration) {
                    $start = new \DateTime($proposalStartDate);
                    $end = new \DateTime($proposalEndDate);
                    $proposalDuration = $start->diff($end)->days + 1;
                }

                $proposal = Proposal::create([
                    'file_path' => $proposalFilePath,
                    'project_title' => $data['proposal_project_title'],
                    'project_description' => $data['proposal_project_description'] ?? '',
                    'proposed_project_name' => $data['proposal_proposed_project_name'] ?? null,
                    'category' => $data['proposal_category'],
                    'location_site' => $data['proposal_location_site'],
                    'contractor_id' => !empty($data['proposal_contractor_id']) ? $data['proposal_contractor_id'] : null,
                    'proposed_start_date' => $proposalStartDate,
                    'proposed_duration_days' => $proposalDuration,
                    'proposed_end_date' => $proposalEndDate,
                    'estimated_budget' => $data['proposal_estimated_budget'],
                    'date_submitted' => !empty($data['proposal_date_submitted']) ? $data['proposal_date_submitted'] : now(),
                    'submitted_by' => Auth::id(),
                    'evaluation_remarks' => $data['proposal_evaluation_remarks'] ?? null,
                    'approved_by' => Auth::id(),
                    'approval_date' => now(),
                ]);

                $proposalId = $proposal->id;
            }

            // === STRIP PROPOSAL-PREFIXED FIELDS FROM PROJECT DATA ===
            $projectData = array_filter($data, function ($key) {
                return !str_starts_with($key, 'proposal_');
            }, ARRAY_FILTER_USE_KEY);

            // Link proposal to project
            if ($proposalId) {
                $projectData['proposal_id'] = $proposalId;
            }

            // Set original planned dates (immutable - frozen at creation)
            // These should match the initial planned dates
            if (!empty($projectData['planned_start_date'])) {
                $projectData['original_planned_start_date'] = $projectData['planned_start_date'];
            }
            if (!empty($projectData['planned_end_date'])) {
                $projectData['original_planned_end_date'] = $projectData['planned_end_date'];
            }

            return Project::create($projectData);
        });

        // === CREATE BASELINE SCHEDULES IF PROVIDED ===
        // First try to get baseline_schedule_targets from the create modal (new way)
        $baselineScheduleTargets = $request->input('baseline_schedule_targets');
        
        if ($baselineScheduleTargets) {
            // Parse JSON if it's a string
            if (is_string($baselineScheduleTargets)) {
                $baselineScheduleTargets = json_decode($baselineScheduleTargets, true);
            }
            
            if (is_array($baselineScheduleTargets) && count($baselineScheduleTargets) > 0) {
                // Process multiple targets from modal
                foreach ($baselineScheduleTargets as $target) {
                    $schedule = ProjectBaselineSchedule::create([
                        'project_id'                          => $project->id,
                        'planned_date'                        => $target['planned_date'] ?? null,
                        'month_label'                         => $target['month_label'] ?? null,
                        'planned_progress_percent'            => $target['planned_progress_percent'] ?? 0,
                        'original_planned_progress_percent'   => $target['original_planned_progress_percent'] ?? ($target['planned_progress_percent'] ?? 0),
                        'original_planned_date'               => $target['planned_date'] ?? null,
                        'description'                         => $target['description'] ?? ''
                    ]);
                    
                    // Add activities if provided
                    if (!empty($target['activities']) && is_array($target['activities'])) {
                        $activities = [];
                        foreach ($target['activities'] as $activity) {
                            $activities[] = [
                                'project_id' => $project->id,
                                'baseline_schedule_id' => $schedule->id,
                                'activity_name' => $activity['activity_name'] ?? '',
                                'description' => $activity['description'] ?? '',
                                'month_label' => $target['month_label'] ?? '',
                                'is_completed' => false,
                                'created_at' => now(),
                                'updated_at' => now()
                            ];
                        }
                        ProjectActivity::insert($activities);
                    }
                }
            }
        } elseif ($request->input('setup_baseline_schedule')) {
            // Fall back to old single-target way (for backward compatibility)
            $monthLabel = $request->input('baseline_schedule_month');
            $progressPercent = $request->input('baseline_schedule_progress_percent', 25);

            if ($monthLabel && $progressPercent) {
                // Convert month input (YYYY-MM) to date
                $plannedDate = \Carbon\Carbon::createFromFormat('Y-m', $monthLabel)->endOfMonth();

                // Bound the legacy target to the project timeline as well
                $projectStart = $project->planned_start_date;
                $projectEnd   = $project->planned_end_date;

                if ($projectStart && $projectEnd) {
                    if ($plannedDate->lt($projectStart) || $plannedDate->gt($projectEnd)) {
                        return back()->withErrors([
                            'baseline_schedule_month' => "Baseline target {$plannedDate->format('Y-m-d')} is outside the project's planned timeline ({$projectStart->format('Y-m-d')} to {$projectEnd->format('Y-m-d')})."
                        ])->withInput();
                    }
                }

                ProjectBaselineSchedule::create([
                    'project_id'                        => $project->id,
                    'planned_date'                      => $plannedDate,
                    'month_label'                       => $monthLabel,
                    'planned_progress_percent'          => $progressPercent,
                    'original_planned_progress_percent' => $progressPercent,
                    'original_planned_date'             => $plannedDate,
                    'description'                      => 'Initial planning target'
                ]);
            }
        }

        // === CREATE DOCUMENTS FROM UPLOADED FILES ===
        $this->createProjectDocuments($project, $signedContractFile, $supportingDocumentFiles, $pendingDocuments);

        if ($request->ajax() || $request->wantsJson()) {
            // Determine redirect route based on user role
            $redirectRoute = match(Auth::user()->role) {
                'admin' => route('admin.projects.detail', $project->id),
                'staff' => route('staff.projects.detail', $project->id),
                default => route('projects.show', $project->id)
            };

            // Flash a success toast so it renders AFTER the modal JS navigates
            // to the project detail page (consumed by the load-time toast script
            // in project_detail.blade.php).
            session()->flash('toast_success', 'Project created successfully.');

            return response()->json([
                'success' => true,
                'message' => 'Project created successfully.',
                'project' => $project,
                'redirect' => $redirectRoute
            ]);
        }

        // Determine redirect route based on user role
        $redirectRoute = match(Auth::user()->role) {
            'admin' => route('admin.projects.detail', $project->id),
            'staff' => route('staff.projects.detail', $project->id),
            default => route('projects.show', $project->id)
        };
        
        return redirect()->to($redirectRoute)->with('success', 'Project created successfully.');
    }

    // === GET PROJECT DATA FOR MODAL (AJAX - ADMIN/STAFF ONLY) ===
    // Protected by route middleware: 'role:admin' or 'role:staff'
    public function getProjectData($id)
    {
        $project = Project::with(['contractor', 'proposal.contractor'])->findOrFail($id);
        
        return response()->json([
            'success' => true,
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'code' => $project->code,
                'category_id' => $project->category_id,
                'location' => $project->location,
                'description' => $project->description,
                'approved_budget' => $project->approved_budget,
                'planned_start_date' => $project->planned_start_date?->format('Y-m-d'),
                'planned_duration_days' => $project->planned_duration_days,
                'planned_end_date' => $project->planned_end_date?->format('Y-m-d'),
                'planned_handing_over_date' => $project->planned_handing_over_date?->format('Y-m-d'),
                'actual_start_date' => $project->actual_start_date?->format('Y-m-d'),
                'actual_end_date' => $project->actual_end_date?->format('Y-m-d'),
                'status' => $project->status,
                'assigned_project_manager' => $project->assigned_project_manager,
                'project_manager_contact' => $project->project_manager_contact,
                'estimated_workers' => $project->estimated_workers,
                'contractor_id' => $project->contractor_id,
                'signed_contract' => $project->signed_contract,
                'supporting_documents' => $project->supporting_documents,
                'proposal_id' => $project->proposal_id,
            ],
            'proposal' => $project->proposal ? [
                'id' => $project->proposal->id,
                'file_path' => $project->proposal->file_path,
                'project_title' => $project->proposal->project_title,
                'project_description' => $project->proposal->project_description,
                'proposed_project_name' => $project->proposal->proposed_project_name,
                'category' => $project->proposal->category,
                'location_site' => $project->proposal->location_site,
                'contractor_id' => $project->proposal->contractor_id,
                'proposed_start_date' => $project->proposal->proposed_start_date?->format('Y-m-d'),
                'proposed_duration_days' => $project->proposal->proposed_duration_days,
                'proposed_end_date' => $project->proposal->proposed_end_date?->format('Y-m-d'),
                'estimated_budget' => $project->proposal->estimated_budget,
                'date_submitted' => $project->proposal->date_submitted?->format('Y-m-d\TH:i'),
                'evaluation_remarks' => $project->proposal->evaluation_remarks,
            ] : null,
        ]);
    }

    // === EDIT PROJECT (legacy - redirect to projects with edit modal - ADMIN/STAFF ONLY) ===
    // Protected by route middleware: 'role:admin' or 'role:staff'
    public function edit($id)
    {
        // Redirect to projects page with edit parameter
        return redirect()->route('admin.projects', ['edit' => $id]);
    }

    // === UPDATE PROJECT (ADMIN/STAFF ONLY) ===
    // Protected by route middleware: 'role:admin' or 'role:staff'
    // Staff can update projects
    public function update(Request $request, $id)
    {
        $project = Project::findOrFail($id);

        // Check if title already exists (excluding this project)
        if (Project::where('title', $request->input('title'))->where('id', '!=', $id)->exists()) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A project with this title already exists. Please use a different title.',
                    'errors' => ['title' => ['A project with this title already exists.']]
                ], 422);
            }
            return back()->withErrors(['title' => 'A project with this title already exists.'])->withInput();
        }

        // === DATA VALIDATION ===
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100', 'unique:projects,code,' . $id],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'category' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'approved_budget' => ['required', 'numeric', 'min:0'],
            'planned_start_date' => ['nullable', 'date'],
            'planned_duration_days' => ['nullable', 'integer', 'min:1'],
            'planned_end_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'planned_handing_over_date' => ['nullable', 'date', 'after_or_equal:planned_end_date'],
            'actual_start_date' => ['nullable', 'date'],
            'actual_end_date' => ['nullable', 'date', 'after_or_equal:actual_start_date'],
            'actual_duration_days' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:Planned,Ongoing,Completed,Suspended,Cancelled'],
            'assigned_project_manager' => ['nullable', 'string', 'max:255'],
            'project_manager_contact' => ['nullable', 'string', 'max:50'],
            'estimated_workers' => ['nullable', 'integer', 'min:1'],
            'contractor_id' => ['nullable', 'integer', 'exists:contractors,id'],
            'signed_contract' => ['nullable', 'file', 'max:20480'],
            'supporting_documents.*' => ['nullable', 'file', 'max:20480'],
        ];

        // Add proposal validation rules if proposal data is present
        if ($request->hasFile('proposal_file')) {
            $rules['proposal_file'] = ['required', 'file', 'mimes:pdf', 'max:10240'];
            $rules['proposal_project_title'] = ['required', 'string', 'max:255'];
            $rules['proposal_project_description'] = ['required', 'string'];
            $rules['proposal_proposed_project_name'] = ['nullable', 'string', 'max:255'];
            $rules['proposal_category'] = ['required', 'in:new_construction,renovation,repair,maintenance'];
            $rules['proposal_location_site'] = ['required', 'string', 'max:255'];
            $rules['proposal_contractor_id'] = ['nullable', 'integer', 'exists:contractors,id'];
            $rules['proposal_proposed_start_date'] = ['required', 'date'];
            $rules['proposal_proposed_duration_days'] = ['nullable', 'integer', 'min:1'];
            $rules['proposal_proposed_end_date'] = ['nullable', 'date'];
            $rules['proposal_estimated_budget'] = ['required', 'numeric', 'min:0'];
            $rules['proposal_date_submitted'] = ['nullable', 'date_format:Y-m-d\TH:i'];
            $rules['proposal_evaluation_remarks'] = ['nullable', 'string'];
        }

        $data = $request->validate($rules);

        // If planned_end_date not provided but start + duration provided, calculate
        if (empty($data['planned_end_date']) && !empty($data['planned_start_date']) && !empty($data['planned_duration_days'])) {
            $data['planned_end_date'] = date('Y-m-d', strtotime($data['planned_start_date'] . ' +' . ($data['planned_duration_days'] - 1) . ' days'));
        }

        // Handle signed contract upload
        if ($request->hasFile('signed_contract')) {
            $file = $request->file('signed_contract');
            $fileName = Str::slug($data['title'], '-') . '-contract-' . time() . '.' . $file->getClientOriginalExtension();
            $data['signed_contract'] = $file->storeAs('projects/contracts', $fileName, 'public');
        }

        // Handle supporting documents
        $supporting = $project->supporting_documents ?? [];
        if ($request->hasFile('supporting_documents')) {
            foreach ($request->file('supporting_documents') as $file) {
                $fileName = Str::slug($data['title'], '-') . '-supp-' . time() . '-' . uniqid() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('projects/supporting', $fileName, 'public');
                $supporting[] = $path;
            }
        }
        if (!empty($supporting)) {
            $data['supporting_documents'] = $supporting;
        }

        // Use a transaction for atomicity with proposal
        $project = DB::transaction(function () use ($data, $request, $project) {

            // === CREATE OR UPDATE PROPOSAL IF DATA IS PRESENT ===
            if ($request->hasFile('proposal_file')) {
                $proposalFile = $request->file('proposal_file');
                $proposalFileName = Str::slug($data['proposal_project_title'] ?? 'proposal', '-') . '-proposal-' . time() . '.pdf';
                $proposalFilePath = $proposalFile->storeAs('proposals', $proposalFileName, 'public');

                // Calculate missing date/duration for proposal
                $proposalStartDate = $data['proposal_proposed_start_date'] ?? null;
                $proposalDuration = !empty($data['proposal_proposed_duration_days']) ? (int)$data['proposal_proposed_duration_days'] : null;
                $proposalEndDate = !empty($data['proposal_proposed_end_date']) ? $data['proposal_proposed_end_date'] : null;

                if ($proposalStartDate && $proposalDuration && !$proposalEndDate) {
                    $proposalEndDate = date('Y-m-d', strtotime($proposalStartDate . ' +' . ($proposalDuration - 1) . ' days'));
                } elseif ($proposalStartDate && $proposalEndDate && !$proposalDuration) {
                    $start = new \DateTime($proposalStartDate);
                    $end = new \DateTime($proposalEndDate);
                    $proposalDuration = $start->diff($end)->days + 1;
                }

                $proposalData = [
                    'file_path' => $proposalFilePath,
                    'project_title' => $data['proposal_project_title'],
                    'project_description' => $data['proposal_project_description'] ?? '',
                    'proposed_project_name' => $data['proposal_proposed_project_name'] ?? null,
                    'category' => $data['proposal_category'],
                    'location_site' => $data['proposal_location_site'],
                    'contractor_id' => !empty($data['proposal_contractor_id']) ? $data['proposal_contractor_id'] : null,
                    'proposed_start_date' => $proposalStartDate,
                    'proposed_duration_days' => $proposalDuration,
                    'proposed_end_date' => $proposalEndDate,
                    'estimated_budget' => $data['proposal_estimated_budget'],
                    'date_submitted' => !empty($data['proposal_date_submitted']) ? $data['proposal_date_submitted'] : now(),
                    'submitted_by' => Auth::id(),
                    'evaluation_remarks' => $data['proposal_evaluation_remarks'] ?? null,
                ];

                // Check if project already has a proposal - update it, otherwise create new
                if ($project->proposal_id && $project->proposal) {
                    $project->proposal->update($proposalData);
                } else {
                    $proposalData['approved_by'] = Auth::id();
                    $proposalData['approval_date'] = now();
                    $proposal = Proposal::create($proposalData);
                    $data['proposal_id'] = $proposal->id;
                }
            }

            // === STRIP PROPOSAL-PREFIXED FIELDS FROM PROJECT DATA ===
            $projectData = array_filter($data, function ($key) {
                return !str_starts_with($key, 'proposal_');
            }, ARRAY_FILTER_USE_KEY);

            // Protect immutable original dates - they should never be updated after creation
            unset($projectData['original_planned_start_date']);
            unset($projectData['original_planned_end_date']);

            $project->update($projectData);

            return $project;
        });

        if ($request->ajax() || $request->wantsJson()) {
            // Determine redirect route based on user role
            $redirectRoute = match(Auth::user()->role) {
                'admin' => route('admin.projects.detail', $project->id),
                'staff' => route('staff.projects.detail', $project->id),
                default => route('projects.show', $project->id)
            };

            // Flash a success toast so it renders AFTER the modal JS navigates
            // to the project detail page (consumed by the load-time toast script
            // in project_detail.blade.php).
            session()->flash('toast_success', 'Project updated successfully.');

            return response()->json([
                'success' => true,
                'message' => 'Project updated successfully.',
                'project' => $project,
                'redirect' => $redirectRoute
            ]);
        }

        // Determine redirect route based on user role
        $redirectRoute = match(Auth::user()->role) {
            'admin' => route('admin.projects.detail', $project->id),
            'staff' => route('staff.projects.detail', $project->id),
            default => route('projects.show', $project->id)
        };
        
        return redirect()->to($redirectRoute)->with('success', 'Project updated successfully.');
    }

    /**
     * === DELETE PROJECT (ADMIN ONLY) ===
     * 
     * Staff cannot delete projects. Only admins can permanently delete projects.
     * Authorization:
     * - Admin: Can delete any project
     * - Staff/Contractor: Cannot delete projects
     */
    public function destroy($id)
    {
        $user = Auth::user();
        
        // Only admin can delete projects (staff cannot)
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Only admins can delete projects.'
            ], 403);
        }

        try {
            $project = Project::findOrFail($id);
            $projectTitle = $project->title;
            
            // Delete all related records (cascade delete)
            try {
                $project->activities()->delete();
            } catch (\Exception $e) {
                \Log::error('Error deleting activities', ['error' => $e->getMessage()]);
            }
            
            try {
                $project->baselineSchedules()->delete();
            } catch (\Exception $e) {
                \Log::error('Error deleting baselineSchedules', ['error' => $e->getMessage()]);
            }
            
            try {
                $project->progressHistory()->delete();
            } catch (\Exception $e) {
                \Log::error('Error deleting progressHistory', ['error' => $e->getMessage()]);
            }
            
            try {
                $project->extensions()->delete();
            } catch (\Exception $e) {
                \Log::error('Error deleting extensions', ['error' => $e->getMessage()]);
            }
            
            try {
                $project->milestones()->delete();
            } catch (\Exception $e) {
                \Log::error('Error deleting milestones', ['error' => $e->getMessage()]);
            }
            
            // Delete the project itself
            $project->delete();

            // Flash a success toast message so it renders AFTER the page reloads
            // (consumed by the load-time toast script in projects/index.blade.php).
            session()->flash('toast_success', "Project '{$projectTitle}' has been permanently deleted.");

            return response()->json([
                'success' => true,
                'message' => "Project '{$projectTitle}' has been permanently deleted."
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error deleting project', [
                'project_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting project: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Regenerate AI Analysis Text
     * ------------------------------------------------------------------------
     *
     * AJAX endpoint called when the user clicks "Regenerate Analysis" on
     * the AI Prediction tab.
     *
     * Workflow
     * --------
     * 1. Call AIExplanationService::generate() to get AI-powered text
     * 2. If empty (API failure / insufficient data), fall back to static
     *    template text from AIPredictionPresenter
     * 3. Return JSON with analysis_text and generated_at timestamp
     *
     * Called by: ai-prediction.blade.php JS (Phase 6.5)
     * Uses: AIExplanationService, AIPredictionPresenter
     */
    public function regenerateAnalysis(
        Request $request,
        Project $project,
        AIExplanationService $aiService,
        AIReportSendingService $emailService
    ) {
        $analysisText = $aiService->generate($project);
        $emailStatus = null;

        // Fallback to static template text if AI returned empty
        if (empty($analysisText)) {
            $presenter = app(AIPredictionPresenter::class);
            $prediction = $presenter->present($project);
            $analysisText = $prediction['analysis_text'] ?? '';
            $generatedAt = null;
        } else {
            $latest = $aiService->getLatest($project);
            $generatedAt = $latest ? $latest->created_at->toDateTimeString() : null;

            $result = $emailService->sendForProject($project);
            $emailStatus = [
                'success' => $result['success'],
                'message' => $result['message'],
            ];
        }

        return response()->json([
            'analysis_text' => $analysisText,
            'generated_at'  => $generatedAt,
            'email'         => $emailStatus,
        ]);
    }

    /**
     * === CREATE PROJECT DOCUMENTS HELPER ===
     * 
     * Handles file uploads and creates Document records in the database.
     * Files are stored in: storage/app/public/projects/documents/PRJ-{CODE}/{filename}
     * 
     * @param Project $project
     * @param UploadedFile $signedContractFile (optional)
     * @param array $supportingDocumentFiles (optional)
     */
    private function createProjectDocuments($project, $signedContractFile = null, $supportingDocumentFiles = [], $pendingDocuments = [])
    {
        // Get document types from database
        $contractType = DocumentType::where('name', 'Contract')->first();
        $supportingType = DocumentType::where('name', 'Supporting Document')->first();

        // If document types don't exist, log warning and return
        if (!$contractType || !$supportingType) {
            Log::warning('Document types not found. Document creation skipped.', [
                'project_id' => $project->id,
                'contract_type_exists' => (bool)$contractType,
                'supporting_type_exists' => (bool)$supportingType
            ]);
            return;
        }

        // === CREATE PENDING DOCUMENTS (FROM MODAL) ===
        // These have explicit document_type_id from the user selection
        if (is_array($pendingDocuments) && count($pendingDocuments) > 0) {
            foreach ($pendingDocuments as $pending) {
                if (!isset($pending['file']) || !isset($pending['document_type_id'])) {
                    continue;
                }

                $file = $pending['file'];
                if (!$file || !$file->isValid()) {
                    continue;
                }

                try {
                    $documentType = DocumentType::find($pending['document_type_id']);
                    if (!$documentType) {
                        continue;
                    }

                    $storagePath = Document::generateStoragePath(
                        $project->code,
                        $documentType->name,
                        $file->getClientOriginalExtension()
                    );

                    // Store the file
                    $file->storeAs(
                        dirname($storagePath),
                        basename($storagePath),
                        'public'
                    );

                    // Create document record
                    Document::create([
                        'project_id' => $project->id,
                        'document_type_id' => $pending['document_type_id'],
                        'document_name' => $file->getClientOriginalName(),
                        'file_path' => $storagePath,
                        'uploaded_by' => Auth::user()->name ?? Auth::user()->email,
                        'description' => $documentType->name . ' uploaded from project creation',
                    ]);

                    Log::info('Pending document created', [
                        'project_id' => $project->id,
                        'project_code' => $project->code,
                        'document_type' => $documentType->name,
                        'file_path' => $storagePath,
                        'original_name' => $file->getClientOriginalName()
                    ]);

                } catch (\Exception $e) {
                    Log::error('Error uploading pending document', [
                        'project_id' => $project->id,
                        'project_code' => $project->code,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        }

        // === CREATE SIGNED CONTRACT DOCUMENT ===
        if ($signedContractFile && $signedContractFile->isValid()) {
            try {
                $extension = $signedContractFile->getClientOriginalExtension();
                $timestamp = now()->format('Y-m-d-His');
                $random = uniqid();
                $filename = 'contract-' . $timestamp . '-' . $random . '.' . $extension;
                $storagePath = 'projects/documents/' . $project->code . '/' . $filename;

                // Store the file
                $signedContractFile->storeAs(
                    dirname($storagePath),
                    basename($storagePath),
                    'public'
                );

                // Create document record
                Document::create([
                    'project_id' => $project->id,
                    'document_type_id' => $contractType->id,
                    'document_name' => $signedContractFile->getClientOriginalName(),
                    'file_path' => $storagePath,
                    'uploaded_by' => Auth::user()->name ?? Auth::user()->email,
                    'description' => 'Signed contract for project ' . $project->title,
                ]);

                Log::info('Contract document created', [
                    'project_id' => $project->id,
                    'project_code' => $project->code,
                    'file_path' => $storagePath,
                    'original_name' => $signedContractFile->getClientOriginalName()
                ]);

            } catch (\Exception $e) {
                Log::error('Error uploading contract document', [
                    'project_id' => $project->id,
                    'project_code' => $project->code,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        // === CREATE SUPPORTING DOCUMENTS ===
        if (is_array($supportingDocumentFiles) && count($supportingDocumentFiles) > 0) {
            foreach ($supportingDocumentFiles as $file) {
                if (!$file || !$file->isValid()) {
                    continue;
                }

                try {
                    $extension = $file->getClientOriginalExtension();
                    $timestamp = now()->format('Y-m-d-His');
                    $random = uniqid();
                    $filename = 'supporting-' . $timestamp . '-' . $random . '.' . $extension;
                    $storagePath = 'projects/documents/' . $project->code . '/' . $filename;

                    // Store the file
                    $file->storeAs(
                        dirname($storagePath),
                        basename($storagePath),
                        'public'
                    );

                    // Create document record
                    Document::create([
                        'project_id' => $project->id,
                        'document_type_id' => $supportingType->id,
                        'document_name' => $file->getClientOriginalName(),
                        'file_path' => $storagePath,
                        'uploaded_by' => Auth::user()->name ?? Auth::user()->email,
                        'description' => 'Supporting document for project ' . $project->title,
                    ]);

                    Log::info('Supporting document created', [
                        'project_id' => $project->id,
                        'project_code' => $project->code,
                        'file_path' => $storagePath,
                        'original_name' => $file->getClientOriginalName()
                    ]);

                } catch (\Exception $e) {
                    Log::error('Error uploading supporting document', [
                        'project_id' => $project->id,
                        'project_code' => $project->code,
                        'filename' => $file->getClientOriginalName(),
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        }
    }
}