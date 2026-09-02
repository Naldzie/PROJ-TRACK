<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BudgetSource;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;

class BudgetSourceController extends Controller
{
    use LogsActivity;

    /**
     * Get all active budget sources (for dropdowns).
     */
    public function getBudgetSources()
    {
        $sources = BudgetSource::where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json($sources);
    }

    /**
     * Store a newly created budget source.
     */
    public function store(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|min:3|max:255|unique:budget_sources,name',
            'description' => 'nullable|string',
        ]);

        $source = BudgetSource::create($validated);

        $this->logActivity('Create Budget Source', "Created budget source: {$source->name}");

        return response()->json([
            'success' => true,
            'message' => 'Budget source created successfully',
            'budget_source' => $source,
        ], 201);
    }

    /**
     * Update the specified budget source.
     */
    public function update(Request $request, BudgetSource $budgetSource)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|min:3|max:255|unique:budget_sources,name,' . $budgetSource->id,
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $budgetSource->update($validated);

        $this->logActivity('Update Budget Source', "Updated budget source: {$budgetSource->name}");

        return response()->json([
            'success' => true,
            'message' => 'Budget source updated successfully',
            'budget_source' => $budgetSource,
        ]);
    }

    /**
     * Delete the specified budget source.
     */
    public function destroy(BudgetSource $budgetSource)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($budgetSource->projects()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete budget source that is in use by projects',
            ], 422);
        }

        $this->logActivity('Delete Budget Source', "Deleted budget source: {$budgetSource->name}");

        $budgetSource->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Budget source deleted successfully',
        ]);
    }

    /**
     * Get all budget sources with project counts (for management modal).
     */
    public function getAllForManagement()
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $sources = BudgetSource::withCount('projects')
            ->orderBy('name')
            ->get()
            ->map(function ($source) {
                return [
                    'id' => $source->id,
                    'name' => $source->name,
                    'description' => $source->description ?? '',
                    'is_active' => $source->is_active,
                    'projects_count' => $source->projects_count ?? 0,
                    'created_at' => $source->created_at,
                    'updated_at' => $source->updated_at,
                ];
            });

        return response()->json($sources);
    }
}
