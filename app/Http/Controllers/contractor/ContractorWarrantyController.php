<?php

namespace App\Http\Controllers\Contractor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\Warranty;
use App\Models\Project;

class ContractorWarrantyController extends Controller
{
    // ✅ SHOW ONLY CONTRACTOR DATA
    public function index()
    {
        $user = Auth::user();
        $contractor = $user->contractor;

        // Contractor's projects only
        $projects = Project::where('contractor_id', $contractor->id)->get();

        // Warranties only from those projects
        $warranties = Warranty::with(['project', 'contractor'])
            ->whereIn('project_id', $projects->pluck('id'))
            ->latest()
            ->get();

        return view('contractor.warranty', compact('warranties', 'projects'));
    }

    // ✅ STORE (SECURED) - Contractors can only add basic warranty info
    public function store(Request $request)
    {
        $user = Auth::user();
        $contractor = $user->contractor;

        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'title' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'status' => 'required|in:Active,Expired,Pending',
            'file' => 'required|file|max:10240',
        ]);

        // 🔒 Ensure project belongs to contractor
        $project = Project::where('id', $request->project_id)
            ->where('contractor_id', $contractor->id)
            ->first();

        if (!$project) {
            abort(403, 'Unauthorized project access');
        }

        $filePath = $request->file('file')->store('warranties', 'public');

        // Only allow contractor to set basic fields; inspection fields are admin/staff only
        Warranty::create([
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'title' => $request->title,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'status' => $request->status,
            'file_path' => $filePath,
            // These fields are intentionally omitted - only admin/staff can set them:
            // 'completion_date', 'mid_year_inspection', 'final_inspection', 'remarks'
        ]);

        return back()->with('success', 'Warranty added successfully');
    }

    // Stream the warranty file inline (iframe preview + "Open file").
    // Served through the app via the public disk — NOT via asset('storage/...'),
    // so it works even when the public/storage symlink is missing or broken.
    // Contractors may only open files of their own projects (mirrors store()).
    public function file($id)
    {
        $warranty = Warranty::findOrFail($id);

        $contractor = Auth::user()->contractor;

        if (!$contractor || !Project::where('id', $warranty->project_id)
                ->where('contractor_id', $contractor->id)
                ->exists()) {
            abort(403, 'Unauthorized project access');
        }

        if (!$warranty->file_path || !Storage::disk('public')->exists($warranty->file_path)) {
            abort(404, 'Warranty file not found. It may have been deleted or was never uploaded to this server.');
        }

        return Storage::disk('public')->response($warranty->file_path);
    }
}