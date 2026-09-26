<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Warranty;
use App\Models\Project;
use App\Models\Contractor;

class WarrantyController extends Controller
{
    public function index()
    {
        $warranties = Warranty::with(['project', 'contractor'])->latest()->get();
        $projects = Project::with('contractor')->get();
        $contractors = Contractor::all();

        return view('admin.warranty', compact('warranties', 'projects', 'contractors'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'contractor_id' => 'nullable|exists:contractors,id',
            'title' => 'nullable|string',
            'completion_date' => 'nullable|date',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'mid_year_inspection' => 'nullable|date',
            'final_inspection' => 'nullable|date',
            'status' => 'required|string',
            'remarks' => 'nullable|string',
            'file' => 'required|file|max:10240', // 10MB
        ]);

        $filePath = $request->file('file')->store('warranties', 'public');

        Warranty::create([
            'project_id' => $request->project_id,
            'contractor_id' => $request->contractor_id,
            'title' => $request->title,
            'completion_date' => $request->completion_date,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'mid_year_inspection' => $request->mid_year_inspection,
            'final_inspection' => $request->final_inspection,
            'status' => $request->status,
            'remarks' => $request->remarks,
            'file_path' => $filePath,
        ]);

        return back()->with('success', 'Warranty added successfully');
    }

    public function destroy($id)
    {
        $warranty = Warranty::findOrFail($id);

        // Optional: delete the file from storage
        if ($warranty->file_path && \Storage::disk('public')->exists($warranty->file_path)) {
            \Storage::disk('public')->delete($warranty->file_path);
        }

        $warranty->delete();

        return back()->with('success', 'Warranty deleted successfully');
    }

    public function preview(Request $request)
    {
        [$warranties, $years, $selectedYear] = $this->previewData($request);

        return view('warranty.preview', compact('warranties', 'years', 'selectedYear'));
    }

    // Public warranty overview — no login required. Renders the same
    // read-only overview table (no file downloads, no write actions).
    public function publicPreview(Request $request)
    {
        [$warranties, $years, $selectedYear] = $this->previewData($request);

        return view('warranty.preview', compact('warranties', 'years', 'selectedYear'));
    }

    // Shared year data for the preview board. The board opens on the earliest
    // year (e.g. 2025) and loops years CLIENT-SIDE with no reload, so Present
    // never stops. Every row renders with a data-year; undated rows belong to
    // every year so they are never hidden.
    private function previewData(Request $request): array
    {
        $warranties = Warranty::with(['project', 'contractor'])->latest()->get();

        $years = $warranties->map(fn ($w) => $w->completion_date ? \Carbon\Carbon::parse($w->completion_date)->year : null)
            ->filter()->unique()->sort()->values();

        $selectedYear = is_numeric($request->query('year')) ? (int) $request->query('year') : null;
        if ($selectedYear && !$years->contains($selectedYear)) {
            $selectedYear = null;
        }
        if (!$selectedYear) {
            $selectedYear = $years->first();
        }

        return [$warranties, $years, $selectedYear];
    }
}