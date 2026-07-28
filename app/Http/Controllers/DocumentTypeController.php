<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use Illuminate\Http\Request;

class DocumentTypeController extends Controller
{
    /** =========redundant methods moved to Admin\DocumentTypeController=========
     * === GET ACTIVE DOCUMENT TYPES (AJAX) ===
     * 
     * Returns all active document types for use in select dropdowns
     * 
     * Response: { success: true, document_types: [...] }
     */
    public function getActive()
    {
        try {
            $documentTypes = DocumentType::active()
                ->select('id', 'name', 'description', 'icon')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'document_types' => $documentTypes
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching document types: ' . $e->getMessage()
            ], 500);
        }
    }
}
