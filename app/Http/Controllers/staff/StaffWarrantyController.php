<?php

namespace App\Http\Controllers\staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Warranty;
use App\Models\Project;

class StaffWarrantyController extends Controller
{
    public function index()
    {
        $warranties = Warranty::with('project')->latest()->get();
        $projects = Project::all();

        return view('staff.warranty', compact('warranties', 'projects'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'title' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'status' => 'required|string',
            'file' => 'required|file|max:10240', // 10MB
        ]);

        $filePath = $request->file('file')->store('warranties', 'public');

        Warranty::create([
            'project_id' => $request->project_id,
            'title' => $request->title,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'status' => $request->status,
            'file_path' => $filePath,
        ]);

        return back()->with('success', 'Warranty added successfully');
    }
}