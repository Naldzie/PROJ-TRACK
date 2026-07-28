<?php
// New files(for merging)
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\Proposal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProposalController extends Controller
{
    /**
     * Store a new proposal (uploaded during project creation)
     * All proposals coming in are considered already approved.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            // === FILE AND PROJECT INFORMATION ===
            'proposal_file' => 'required|file|mimes:pdf|max:10240',
            'project_title' => 'required|string|max:255',
            'proposed_project_name' => 'nullable|string|max:255',
            'project_description' => 'required|string',
            
            // === CATEGORY AND LOCATION ===
            'category' => 'required|in:new_construction,renovation,repair,maintenance',
            'location_site' => 'required|string|max:255',
            
            // === CONTRACTOR ===
            'contractor_id' => 'nullable|exists:contractors,id',
            
            // === PROJECT TIMELINE (fill either duration OR end date) ===
            'proposed_start_date' => 'required|date',
            'proposed_duration_days' => 'nullable|integer|min:1',
            'proposed_end_date' => 'nullable|date|after_or_equal:proposed_start_date',
            
            // === BUDGET ===
            'estimated_budget' => 'required|numeric|min:0',
            
            // === SUBMISSION INFO ===
            'date_submitted' => 'nullable|date_format:Y-m-d\TH:i',
            
            // === APPROVAL INFO (optional - all proposals are pre-approved) ===
            'evaluation_remarks' => 'nullable|string',
        ]);

        // === CALCULATE MISSING DATE/DURATION ===
        if (!empty($data['proposed_start_date'])) {
            if (!empty($data['proposed_duration_days']) && empty($data['proposed_end_date'])) {
                // Calculate end date from duration
                $data['proposed_end_date'] = date('Y-m-d', strtotime($data['proposed_start_date'] . ' +' . ($data['proposed_duration_days'] - 1) . ' days'));
            } elseif (!empty($data['proposed_end_date']) && empty($data['proposed_duration_days'])) {
                // Calculate duration from end date
                $startDate = new \DateTime($data['proposed_start_date']);
                $endDate = new \DateTime($data['proposed_end_date']);
                $diff = $startDate->diff($endDate);
                $data['proposed_duration_days'] = $diff->days + 1;
            }
        }

        // === HANDLE PDF FILE UPLOAD ===
        if ($request->hasFile('proposal_file')) {
            $file = $request->file('proposal_file');
            $fileName = Str::slug($data['project_title'], '-') . '-proposal-' . time() . '.pdf';
            $data['file_path'] = $file->storeAs('proposals', $fileName, 'public');
        }

        // === SET SUBMISSION INFO ===
        $data['submitted_by'] = Auth::id();
        if (!isset($data['date_submitted']) || empty($data['date_submitted'])) {
            $data['date_submitted'] = now();
        }

        // === SET APPROVAL INFO (all proposals are pre-approved) ===
        $data['approved_by'] = Auth::id();
        $data['approval_date'] = now();

        $proposal = Proposal::create($data);

        // Return JSON response for AJAX
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Proposal uploaded successfully.',
                'proposal' => $proposal->load('contractor'),
            ]);
        }

        return back()->with('success', 'Proposal uploaded successfully.');
    }

    /**
     * Get proposal details (for auto-filling project form)
     */
    public function show($id)
    {
        $proposal = Proposal::with('contractor')->findOrFail($id);
        
        return response()->json([
            'success' => true,
            'proposal' => $proposal,
        ]);
    }

    /**
     * List all proposals (for selection)
     */
    public function list()
    {
        $proposals = Proposal::with('contractor')
            ->whereDoesntHave('project') // Only proposals not yet converted to projects
            ->latest()
            ->get();
        
        return response()->json($proposals);
    }

    /**
     * Download proposal file
     */
    public function download($id)
    {
        $proposal = Proposal::findOrFail($id);
        
        if (!$proposal->file_path || !Storage::disk('public')->exists($proposal->file_path)) {
            abort(404, 'Proposal file not found.');
        }
        
        return Storage::disk('public')->download($proposal->file_path, Str::slug($proposal->project_title) . '-proposal.pdf');
    }
}
