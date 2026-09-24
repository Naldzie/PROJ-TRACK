<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectActivity;
use App\Models\ProjectProgressHistory;
use App\Models\ProjectExtension;
use App\Models\Issue;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        $contractorId = 1;
        $catNew = 1;
        $catRenovation = 2;
        $catRepair = 3;

        $projects = [
            [
                'code' => 'PRJ-2026-00001',
                'title' => 'Barangay Health Center Construction',
                'category_id' => $catNew,
                'location' => 'Barangay San Isidro, Main Street',
                'description' => 'Construction of a two-storey barangay health center with outpatient facilities, pharmacy, and waiting area.',
                'contract_price' => 4500000.00,
                'planned_start_date' => '2026-09-01',
                'planned_duration_days' => 120,
                'planned_end_date' => '2026-12-29',
                'status' => 'Planned',
                'assigned_project_manager' => 'Juan Cruz',
                'project_manager_contact' => '09987654321',
                'contractor_id' => $contractorId,
                'estimated_workers' => 25,
                'planned_handing_over_date' => '2027-01-15',
                'milestones' => [
                    ['name' => 'Site Preparation', 'weight' => 10, 'planned_start' => '2026-09-01', 'planned_end' => '2026-09-15', 'status' => 'Not Started'],
                    ['name' => 'Foundation Works', 'weight' => 20, 'planned_start' => '2026-09-16', 'planned_end' => '2026-10-15', 'status' => 'Not Started'],
                    ['name' => 'Structural Framing', 'weight' => 25, 'planned_start' => '2026-10-16', 'planned_end' => '2026-11-15', 'status' => 'Not Started'],
                    ['name' => 'Roofing and Finishing', 'weight' => 25, 'planned_start' => '2026-11-16', 'planned_end' => '2026-12-10', 'status' => 'Not Started'],
                    ['name' => 'Utilities and Handover', 'weight' => 20, 'planned_start' => '2026-12-11', 'planned_end' => '2026-12-29', 'status' => 'Not Started'],
                ],
            ],
            [
                'code' => 'PRJ-2026-00002',
                'title' => 'School Building Renovation',
                'category_id' => $catRenovation,
                'location' => 'CSPC Main Campus, Engineering Building',
                'description' => 'Complete renovation of the engineering building including classroom upgrades, laboratory modernization, and accessibility improvements.',
                'contract_price' => 3200000.00,
                'planned_start_date' => '2026-10-01',
                'planned_duration_days' => 90,
                'planned_end_date' => '2026-12-29',
                'status' => 'Planned',
                'assigned_project_manager' => 'Neil Inocencio',
                'project_manager_contact' => '09123456789',
                'contractor_id' => $contractorId,
                'estimated_workers' => 20,
                'planned_handing_over_date' => '2027-01-15',
                'milestones' => [
                    ['name' => 'Demolition and Clearing', 'weight' => 10, 'planned_start' => '2026-10-01', 'planned_end' => '2026-10-10', 'status' => 'Not Started'],
                    ['name' => 'Classroom Renovation', 'weight' => 35, 'planned_start' => '2026-10-11', 'planned_end' => '2026-11-15', 'status' => 'Not Started'],
                    ['name' => 'Laboratory Upgrades', 'weight' => 30, 'planned_start' => '2026-11-16', 'planned_end' => '2026-12-10', 'status' => 'Not Started'],
                    ['name' => 'Finishing and Painting', 'weight' => 25, 'planned_start' => '2026-12-11', 'planned_end' => '2026-12-29', 'status' => 'Not Started'],
                ],
            ],
            [
                'code' => 'PRJ-2026-00003',
                'title' => 'Public Market Rehabilitation',
                'category_id' => $catRenovation,
                'location' => 'Public Market, Poblacion Zone 2',
                'description' => 'Major rehabilitation of the public market including structural repairs, new roofing, drainage system upgrade, and vendor stall reconfiguration.',
                'contract_price' => 8500000.00,
                'planned_start_date' => '2026-05-15',
                'planned_duration_days' => 150,
                'planned_end_date' => '2026-10-11',
                'actual_start_date' => '2026-05-20',
                'status' => 'Ongoing',
                'assigned_project_manager' => 'Juan Cruz',
                'project_manager_contact' => '09987654321',
                'contractor_id' => $contractorId,
                'estimated_workers' => 35,
                'planned_handing_over_date' => '2026-10-30',
                'milestones' => [
                    ['name' => 'Assessment and Planning', 'weight' => 5, 'planned_start' => '2026-05-15', 'planned_end' => '2026-05-25', 'actual_start' => '2026-05-20', 'actual_end' => '2026-05-28', 'status' => 'Completed', 'progress' => 100],
                    ['name' => 'Structural Repairs', 'weight' => 25, 'planned_start' => '2026-05-26', 'planned_end' => '2026-06-30', 'actual_start' => '2026-05-29', 'actual_end' => '2026-07-05', 'status' => 'Completed', 'progress' => 100],
                    ['name' => 'Roofing Replacement', 'weight' => 25, 'planned_start' => '2026-07-01', 'planned_end' => '2026-08-15', 'actual_start' => '2026-07-06', 'status' => 'In Progress', 'progress' => 65],
                    ['name' => 'Drainage System Upgrade', 'weight' => 20, 'planned_start' => '2026-08-16', 'planned_end' => '2026-09-15', 'status' => 'Not Started'],
                    ['name' => 'Stall Construction and Finishing', 'weight' => 25, 'planned_start' => '2026-09-16', 'planned_end' => '2026-10-11', 'status' => 'Not Started'],
                ],
            ],
            [
                'code' => 'PRJ-2026-00004',
                'title' => 'Flood Control Drainage System',
                'category_id' => $catNew,
                'location' => 'Barangay Riverside, Zone 1 to Zone 3',
                'description' => 'Construction of a comprehensive flood control drainage system including concrete channels, catch basins, and outfall structures to mitigate flooding.',
                'contract_price' => 12000000.00,
                'planned_start_date' => '2026-04-01',
                'planned_duration_days' => 240,
                'planned_end_date' => '2026-11-26',
                'actual_start_date' => '2026-04-10',
                'status' => 'Ongoing',
                'assigned_project_manager' => 'Neil Inocencio',
                'project_manager_contact' => '09123456789',
                'contractor_id' => $contractorId,
                'estimated_workers' => 45,
                'planned_handing_over_date' => '2026-12-15',
                'milestones' => [
                    ['name' => 'Survey and Layout', 'weight' => 5, 'planned_start' => '2026-04-01', 'planned_end' => '2026-04-15', 'actual_start' => '2026-04-10', 'actual_end' => '2026-04-20', 'status' => 'Completed', 'progress' => 100],
                    ['name' => 'Excavation Works', 'weight' => 20, 'planned_start' => '2026-04-16', 'planned_end' => '2026-05-31', 'actual_start' => '2026-04-21', 'actual_end' => '2026-06-10', 'status' => 'Completed', 'progress' => 100],
                    ['name' => 'Concrete Channel Construction', 'weight' => 35, 'planned_start' => '2026-06-01', 'planned_end' => '2026-08-15', 'actual_start' => '2026-06-11', 'status' => 'In Progress', 'progress' => 60],
                    ['name' => 'Catch Basin Installation', 'weight' => 20, 'planned_start' => '2026-08-16', 'planned_end' => '2026-10-15', 'status' => 'Not Started'],
                    ['name' => 'Outfall Structures and Testing', 'weight' => 20, 'planned_start' => '2026-10-16', 'planned_end' => '2026-11-26', 'status' => 'Not Started'],
                ],
            ],
            [
                'code' => 'PRJ-2026-00005',
                'title' => 'Municipal Hall Extension',
                'category_id' => $catNew,
                'location' => 'Municipal Compound, Poblacion',
                'description' => 'Construction of a new two-storey extension building for the municipal hall to accommodate growing administrative departments.',
                'contract_price' => 15000000.00,
                'planned_start_date' => '2025-11-01',
                'planned_duration_days' => 150,
                'planned_end_date' => '2026-03-30',
                'original_planned_start_date' => '2025-11-01',
                'original_planned_end_date' => '2026-03-30',
                'actual_start_date' => '2025-11-10',
                'actual_end_date' => '2026-05-15',
                'actual_duration_days' => 186,
                'contract_duration_days' => 150,
                'status' => 'Completed',
                'assigned_project_manager' => 'Juan Cruz',
                'project_manager_contact' => '09987654321',
                'contractor_id' => $contractorId,
                'estimated_workers' => 30,
                'planned_handing_over_date' => '2026-04-15',
                'extension_count' => 1,
                'milestones' => [
                    ['name' => 'Site Preparation', 'weight' => 8, 'planned_start' => '2025-11-01', 'planned_end' => '2025-11-15', 'actual_start' => '2025-11-10', 'actual_end' => '2025-11-20', 'status' => 'Completed', 'progress' => 100],
                    ['name' => 'Foundation and Ground Works', 'weight' => 20, 'planned_start' => '2025-11-16', 'planned_end' => '2025-12-15', 'actual_start' => '2025-11-21', 'actual_end' => '2026-01-05', 'status' => 'Completed', 'progress' => 100],
                    ['name' => 'Structural Framing', 'weight' => 25, 'planned_start' => '2025-12-16', 'planned_end' => '2026-01-31', 'actual_start' => '2026-01-06', 'actual_end' => '2026-02-20', 'status' => 'Completed', 'progress' => 100],
                    ['name' => 'Architectural Finishing', 'weight' => 30, 'planned_start' => '2026-02-01', 'planned_end' => '2026-03-15', 'actual_start' => '2026-02-21', 'actual_end' => '2026-04-10', 'status' => 'Completed', 'progress' => 100],
                    ['name' => 'Building Utilities and Handover', 'weight' => 17, 'planned_start' => '2026-03-16', 'planned_end' => '2026-03-30', 'actual_start' => '2026-04-11', 'actual_end' => '2026-05-15', 'status' => 'Completed', 'progress' => 100],
                ],
                'extensions' => [
                    [
                        'extension_number' => 1,
                        'original_end_date' => '2026-03-30',
                        'new_end_date' => '2026-05-15',
                        'duration_days' => 46,
                        'reason' => 'Delay due to material delivery issues and unforeseen weather conditions during foundation works.',
                        'status' => 'approved',
                        'approved_by_user_id' => 1,
                        'approved_at' => '2026-02-15 10:00:00',
                    ],
                ],
                'issues' => [
                    [
                        'issue_name' => 'Material Delivery Delay',
                        'description' => 'Steel reinforcement delivery delayed by 3 weeks due to supplier logistics issues.',
                        'status' => 'Resolved',
                        'issue_type' => 'material',
                        'severity' => 'High',
                        'resolved_at' => '2026-01-10 14:30:00',
                    ],
                    [
                        'issue_name' => 'Weather Disruption',
                        'description' => 'Heavy rains caused flooding at construction site, halting foundation work.',
                        'status' => 'Resolved',
                        'issue_type' => 'weather',
                        'severity' => 'Medium',
                        'resolved_at' => '2025-12-20 16:00:00',
                    ],
                ],
            ],
            [
                'code' => 'PRJ-2026-00006',
                'title' => 'Road Concreting Project',
                'category_id' => $catRepair,
                'location' => 'Barangay San Jose Access Road, 2.5 km stretch',
                'description' => 'Concreting of 2.5 kilometers of farm-to-market road including drainage culverts and road signage.',
                'contract_price' => 9500000.00,
                'planned_start_date' => '2025-08-01',
                'planned_duration_days' => 100,
                'planned_end_date' => '2025-11-08',
                'original_planned_start_date' => '2025-08-01',
                'original_planned_end_date' => '2025-11-08',
                'actual_start_date' => '2025-08-15',
                'actual_end_date' => '2025-12-20',
                'actual_duration_days' => 127,
                'contract_duration_days' => 100,
                'status' => 'Completed',
                'assigned_project_manager' => 'Neil Inocencio',
                'project_manager_contact' => '09123456789',
                'contractor_id' => $contractorId,
                'estimated_workers' => 30,
                'planned_handing_over_date' => '2025-11-30',
                'extension_count' => 1,
                'milestones' => [
                    ['name' => 'Road Preparation and Clearing', 'weight' => 10, 'planned_start' => '2025-08-01', 'planned_end' => '2025-08-15', 'actual_start' => '2025-08-15', 'actual_end' => '2025-08-28', 'status' => 'Completed', 'progress' => 100],
                    ['name' => 'Sub-base Preparation', 'weight' => 20, 'planned_start' => '2025-08-16', 'planned_end' => '2025-09-15', 'actual_start' => '2025-08-29', 'actual_end' => '2025-09-25', 'status' => 'Completed', 'progress' => 100],
                    ['name' => 'Concrete Pouring (Phase 1)', 'weight' => 30, 'planned_start' => '2025-09-16', 'planned_end' => '2025-10-15', 'actual_start' => '2025-09-26', 'actual_end' => '2025-10-30', 'status' => 'Completed', 'progress' => 100],
                    ['name' => 'Concrete Pouring (Phase 2)', 'weight' => 25, 'planned_start' => '2025-10-16', 'planned_end' => '2025-10-31', 'actual_start' => '2025-10-31', 'actual_end' => '2025-11-30', 'status' => 'Completed', 'progress' => 100],
                    ['name' => 'Culverts, Signage and Finishing', 'weight' => 15, 'planned_start' => '2025-11-01', 'planned_end' => '2025-11-08', 'actual_start' => '2025-12-01', 'actual_end' => '2025-12-20', 'status' => 'Completed', 'progress' => 100],
                ],
                'extensions' => [
                    [
                        'extension_number' => 1,
                        'original_end_date' => '2025-11-08',
                        'new_end_date' => '2025-12-20',
                        'duration_days' => 42,
                        'reason' => 'Extension requested due to right-of-way acquisition delays and additional scope for drainage culverts.',
                        'status' => 'approved',
                        'approved_by_user_id' => 1,
                        'approved_at' => '2025-10-15 09:00:00',
                    ],
                ],
                'issues' => [
                    [
                        'issue_name' => 'Right-of-Way Conflict',
                        'description' => 'Delays in acquiring right-of-way permits from affected landowners along the road alignment.',
                        'status' => 'Resolved',
                        'issue_type' => 'regulatory',
                        'severity' => 'High',
                        'resolved_at' => '2025-09-20 11:00:00',
                    ],
                    [
                        'issue_name' => 'Cement Shortage',
                        'description' => 'National cement shortage affected construction schedule for Phase 2 concrete pouring.',
                        'status' => 'Resolved',
                        'issue_type' => 'material',
                        'severity' => 'Critical',
                        'resolved_at' => '2025-11-15 15:00:00',
                    ],
                ],
            ],
        ];

        foreach ($projects as $projectData) {
            $milestones = $projectData['milestones'];
            $extensions = $projectData['extensions'] ?? [];
            $issues = $projectData['issues'] ?? [];
            unset($projectData['milestones'], $projectData['extensions'], $projectData['issues']);

            $project = Project::create($projectData);

            foreach ($milestones as $ms) {
                ProjectMilestone::create([
                    'project_id' => $project->id,
                    'name' => $ms['name'],
                    'description' => $ms['name'] . ' milestone for ' . $project->title,
                    'weight_percentage' => $ms['weight'],
                    'planned_start_date' => $ms['planned_start'],
                    'planned_end_date' => $ms['planned_end'],
                    'actual_start_date' => $ms['actual_start'] ?? null,
                    'actual_end_date' => $ms['actual_end'] ?? null,
                    'progress_percentage' => $ms['progress'] ?? 0,
                    'status' => $ms['status'],
                    'remarks' => null,
                    'assigned_to' => $projectData['assigned_project_manager'],
                ]);
            }

            if ($projectData['status'] === 'Ongoing' || $projectData['status'] === 'Completed') {
                $this->seedBaselineAndProgress($project);
            }

            foreach ($extensions as $ext) {
                ProjectExtension::create(array_merge($ext, ['project_id' => $project->id]));
            }

            foreach ($issues as $iss) {
                Issue::create(array_merge($iss, ['project_id' => $project->id]));
            }
        }
    }

    private function seedBaselineAndProgress(Project $project): void
    {
        $start = Carbon::parse($project->planned_start_date);
        $end = Carbon::parse($project->planned_end_date);
        $totalDays = max(1, $start->diffInDays($end));

        $months = [];
        $current = $start->copy()->startOfMonth();
        $lastMonth = $end->copy()->startOfMonth();
        while ($current->lte($lastMonth)) {
            $months[] = $current->copy();
            $current->addMonth();
        }

        $monthCount = count($months);
        $perMonth = 100 / $monthCount;
        $cumulative = 0;

        foreach ($months as $i => $month) {
            $cumulative += $perMonth;
            $monthEnd = $month->copy()->endOfMonth();
            $plannedDate = $monthEnd->gt($end) ? $end->format('Y-m-d') : $monthEnd->format('Y-m-d');
            $plannedPct = round(min($cumulative, 100), 2);

            $baseline = ProjectBaselineSchedule::create([
                'project_id' => $project->id,
                'planned_date' => $plannedDate,
                'month_label' => $month->format('Y-m'),
                'planned_progress_percent' => $plannedPct,
                'original_planned_progress_percent' => $plannedPct,
                'original_planned_date' => $plannedDate,
                'description' => 'Baseline planned progress for ' . $month->format('F Y'),
            ]);

            ProjectActivity::create([
                'project_id' => $project->id,
                'baseline_schedule_id' => $baseline->id,
                'activity_name' => 'Month ' . ($i + 1) . ' - ' . $month->format('F Y'),
                'description' => 'Work activities for ' . $month->format('F Y'),
                'month_label' => $month->format('Y-m'),
                'is_completed' => $project->status === 'Completed' || ($project->status === 'Ongoing' && $month->copy()->endOfMonth()->isPast()),
            ]);
        }

        $this->seedProgressHistory($project);
    }

    private function seedProgressHistory(Project $project): void
    {
        $actualStart = Carbon::parse($project->actual_start_date ?? $project->planned_start_date);
        $actualEnd = $project->actual_end_date
            ? Carbon::parse($project->actual_end_date)
            : now();
        $totalMonths = max(1, $actualStart->diffInMonths($actualEnd) + 1);

        $baselines = $project->baselineSchedules()->orderBy('planned_date')->get();
        $activities = $project->activities()->orderBy('month_label')->get();

        $isCompleted = $project->status === 'Completed';
        $progressPerMonth = $isCompleted ? (100 / $totalMonths) : (80 / $totalMonths);

        for ($i = 0; $i < $totalMonths; $i++) {
            $monthDate = $actualStart->copy()->addMonthsNoOverflow($i);
            $monthLabel = $monthDate->format('Y-m');
            $inspectionDate = $monthDate->copy()->endOfMonth();

            $baseline = $baselines->firstWhere('month_label', $monthLabel);
            $planned = $baseline ? $baseline->planned_progress_percent : 0;
            $progress = round(min(100, ($i + 1) * $progressPerMonth), 2);

            $activity = $activities->firstWhere('month_label', $monthLabel);
            $activityId = $activity ? [$activity->id] : null;

            $previous = $i > 0 ? round($i * $progressPerMonth, 2) : 0;

            ProjectProgressHistory::create([
                'project_id' => $project->id,
                'baseline_schedule_id' => $baseline?->id,
                'activity_id' => $activityId,
                'month_label' => $monthLabel,
                'inspection_date' => $inspectionDate->format('Y-m-d'),
                'progress_percentage' => $progress,
                'planned_progress_percent' => $planned,
                'actual_progress_percent' => $progress,
                'variance_percent' => round($progress - $planned, 2),
                'previous_progress' => $previous,
                'month_number' => $i + 1,
                'actual_start_date' => $i === 0 ? $actualStart->format('Y-m-d') : null,
                'actual_end_date' => ($isCompleted && $i === $totalMonths - 1) ? $actualEnd->format('Y-m-d') : null,
                'status' => $progress >= $planned ? 'OnTrack' : 'Behind',
                'remarks' => 'Monthly progress inspection for ' . $monthDate->format('F Y'),
                'recorded_by' => $project->assigned_project_manager,
            ]);
        }
    }
}
