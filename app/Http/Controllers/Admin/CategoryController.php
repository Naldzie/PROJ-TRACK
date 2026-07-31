<?php
 
namespace App\Http\Controllers\Admin;
 
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
 
class CategoryController extends Controller
{
    use LogsActivity;
    /**
     * Get all categories (for dropdown and management)
     */
    public function getCategories()
    {
        $categories = Category::where('is_active', true)
            ->orderBy('name')
            ->get();
 
        return response()->json($categories);
    }
 
    /**
     * Store a newly created category
     */
    public function store(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
 
        $validated = $request->validate([
            'name' => 'required|string|min:3|max:255|unique:categories,name',
            'description' => 'nullable|string',
        ]);
 
        $category = Category::create($validated);

        $this->logActivity('Create Category', "Created category: {$category->name}");

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'category' => $category
        ], 201);
    }
 
    /**
     * Update the specified category
     */
    public function update(Request $request, Category $category)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
 
        $validated = $request->validate([
            'name' => 'required|string|min:3|max:255|unique:categories,name,' . $category->id,
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);
 
        $category->update($validated);

        $this->logActivity('Update Category', "Updated category: {$category->name}");

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'category' => $category
        ]);
    }
 
    /**
     * Delete the specified category
     */
    public function destroy(Category $category)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
 
        if ($category->projects()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category that is in use by projects'
            ], 422);
        }
 
        // forceDelete() permanently removes the row from the DB instead of
        // soft-deleting it, so the unique index on 'name' is fully released
        // and the same name can be reused immediately after deletion.
        $this->logActivity('Delete Category', "Deleted category: {$category->name}");

        $category->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully'
        ]);
    }
 
    /**
     * Get all categories with counts (for management modal)
     */
    public function getAllForManagement()
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
 
        $categories = Category::withCount('projects')
            ->orderBy('name')
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'description' => $category->description ?? '',
                    'is_active' => $category->is_active,
                    'projects_count' => $category->projects_count ?? 0,
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                ];
            });
 
        return response()->json($categories);
    }
}