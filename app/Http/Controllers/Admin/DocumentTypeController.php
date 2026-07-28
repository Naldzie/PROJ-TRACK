<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DocumentTypeController extends Controller
{
    /**
     * === GET ALL DOCUMENT TYPES FOR MANAGEMENT ===
     * 
     * Returns all document types with project count
     */
    public function getAllForManagement()
    {
        $documentTypes = DocumentType::withCount('documents')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json($documentTypes);
    }

    /**
     * === GET ACTIVE DOCUMENT TYPES FOR DROPDOWN ===
     * 
     * Returns only active document types
     */
    public function getList()
    {
        $types = DocumentType::where('is_active', true)
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'document_types' => $types
        ]);
    }

    /**
     * === STORE NEW DOCUMENT TYPE ===
     * 
     * Creates a new document type
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:document_types,name|max:255',
            'description' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:50',
        ]);

        try {
            $documentType = DocumentType::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'icon' => $validated['icon'] ?? null,
                'is_active' => true,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Document type created successfully',
                'data' => $documentType
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create document type: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * === UPDATE DOCUMENT TYPE ===
     * 
     * Updates an existing document type
     */
    public function update(Request $request, DocumentType $documentType)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:document_types,name,' . $documentType->id . '|max:255',
            'description' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $documentType->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? $documentType->description,
                'icon' => $validated['icon'] ?? $documentType->icon,
                'is_active' => $validated['is_active'] ?? $documentType->is_active,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Document type updated successfully',
                'data' => $documentType
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update document type: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * === DELETE DOCUMENT TYPE ===
     * 
     * Deletes a document type (soft delete if documents exist)
     */
    public function destroy(DocumentType $documentType)
    {
        try {
            // Check if document type has associated documents
            if ($documentType->documents()->count() > 0) {
                // Soft delete by marking as inactive
                $documentType->update(['is_active' => false]);
                return response()->json([
                    'success' => true,
                    'message' => 'Document type deactivated (has associated documents)'
                ]);
            }

            // Hard delete if no documents
            $documentType->delete();

            return response()->json([
                'success' => true,
                'message' => 'Document type deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete document type: ' . $e->getMessage()
            ], 500);
        }
    }
}
