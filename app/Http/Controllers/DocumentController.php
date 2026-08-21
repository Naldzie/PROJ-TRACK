<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    /**
     * === UPLOAD DOCUMENT (AJAX) ===
     *
     * Handles file upload for project documents.
     * Stores file in: storage/app/public/projects/documents/PRJ-{CODE}/{filename}
     *
     * Returns: JSON response with document data
     */
    public function upload(Request $request)
    {
        $validated = $request->validate([
            'project_id'       => ['required', 'integer', 'exists:projects,id'],
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'file'             => [
                'required',
                'file',
                'max:20480',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png',
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $project = Project::findOrFail($validated['project_id']);

        if (!Auth::user()->can('create', [Document::class, $project])) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to upload documents for this project.',
            ], 403);
        }

        try {
            $file         = $request->file('file');
            $documentType = DocumentType::findOrFail($validated['document_type_id']);

            $storagePath = Document::generateStoragePath(
                $project->code,
                $documentType->name,
                $file->getClientOriginalExtension()
            );

            $file->storeAs(
                dirname($storagePath),
                basename($storagePath),
                'public'
            );

            $document = Document::create([
                'project_id'       => $validated['project_id'],
                'document_type_id' => $validated['document_type_id'],
                'document_name'    => $file->getClientOriginalName(),
                'file_path'        => $storagePath,
                'uploaded_by'      => Auth::user()->name ?? Auth::user()->email,
                'description'      => $validated['description'] ?? null,
            ]);

            // Record in activity log
            $actorName = Auth::user()->name ?? Auth::user()->email;
            $actorRole = ucfirst(Auth::user()->role);

            ActivityLog::create([
                'user_id'     => Auth::id(),
                'project_id'  => $validated['project_id'],
                'action'      => 'Upload Document',
                'description' => "{$actorName} ({$actorRole}) uploaded document \"{$document->document_name}\" to project \"{$project->title}\".",
                'ip_address'  => request()->ip(),
            ]);

            return response()->json([
                'success'  => true,
                'message'  => 'Document uploaded successfully.',
                'document' => [
                    'id'            => $document->id,
                    'document_name' => $document->document_name,
                    'document_type' => $documentType->name,
                    'file_url'      => $document->file_url,
                    'file_size'     => round($document->file_size, 2) . ' KB',
                    'uploaded_by'   => $document->uploaded_by,
                    'created_at'    => $document->created_at->format('M d, Y H:i'),
                    'description'   => $document->description,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error uploading document: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * === GET PROJECT DOCUMENTS (AJAX) ===
     *
     * Returns all documents for a project, grouped by document type.
     */
    public function getProjectDocuments($projectId)
    {
        $project = Project::findOrFail($projectId);

        if (!Auth::user()->can('view', $project)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $documents = $project->documents()->with('documentType')->get();

        $grouped = [];
        foreach ($documents as $doc) {
            $typeName = $doc->documentType->name;
            if (!isset($grouped[$typeName])) {
                $grouped[$typeName] = [];
            }

            $grouped[$typeName][] = [
                'id'            => $doc->id,
                'document_name' => $doc->document_name,
                'document_type' => $typeName,
                'file_url'      => $doc->file_url,
                'file_size'     => round($doc->file_size, 2) . ' KB',
                'uploaded_by'   => $doc->uploaded_by,
                'created_at'    => $doc->created_at->format('M d, Y'),
                'description'   => $doc->description,
            ];
        }

        return response()->json([
            'success'     => true,
            'documents'   => $grouped,
            'total_count' => $documents->count(),
        ]);
    }

    /**
     * === DELETE DOCUMENT (AJAX) ===
     *
     * Deletes document record and physical file.
     * Accessible by admin and staff (enforced by DocumentPolicy::delete).
     * Records the deletion in the activity log.
     */
    public function delete($documentId)
    {
        $document = Document::findOrFail($documentId);

        if (!Auth::user()->can('delete', $document)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete this document.',
            ], 403);
        }

        try {
            // Capture details before deleting — model is gone after delete()
            $documentName = $document->document_name;
            $projectTitle = $document->project->title ?? 'Unknown Project';
            $actorName    = Auth::user()->name ?? Auth::user()->email;
            $actorRole    = ucfirst(Auth::user()->role);

            $document->delete();

            // Record in activity log
            ActivityLog::create([
                'user_id'     => Auth::id(),
                'project_id'  => $document->project_id,
                'action'      => 'Delete Document',
                'description' => "{$actorName} ({$actorRole}) deleted document \"{$documentName}\" from project \"{$projectTitle}\".",
                'ip_address'  => request()->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting document: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * === GET DOCUMENT TYPES ===
     *
     * Returns all active document types for dropdown/selection.
     */
    public function getDocumentTypes()
    {
        $types = DocumentType::where('is_active', true)->get();

        return response()->json([
            'success'        => true,
            'document_types' => $types->map(function ($type) {
                return [
                    'id'          => $type->id,
                    'name'        => $type->name,
                    'description' => $type->description,
                    'icon'        => $type->icon,
                ];
            }),
        ]);
    }

    /**
     * === VIEW DOCUMENT INLINE ===
     *
     * Streams the file back with Content-Disposition: inline so the
     * browser renders it (PDF, image) rather than forcing a download.
     * Works for all roles — authorization delegates to DocumentPolicy::view().
     */
    public function view($documentId)
    {
        $document = Document::findOrFail($documentId);

        if (!Auth::user()->can('view', $document)) {
            abort(403, 'You do not have permission to view this document.');
        }

        if (!$document->fileExists()) {
            abort(404, 'Document file not found.');
        }

        return Storage::disk('public')->response(
            $document->file_path,
            $document->document_name,
            ['Content-Disposition' => 'inline; filename="' . addslashes($document->document_name) . '"']
        );
    }

    /**
     * === PREVIEW DOCUMENT AS HTML ===
     *
     * Converts Excel/Word files to HTML for in-browser preview.
     * For xls/xlsx: uses PhpSpreadsheet Html writer.
     * Other types: redirects to the view endpoint.
     */
    public function previewHtml($documentId)
    {
        $document = Document::findOrFail($documentId);

        if (!Auth::user()->can('view', $document)) {
            abort(403, 'You do not have permission to view this document.');
        }

        if (!$document->fileExists()) {
            abort(404, 'Document file not found.');
        }

        $ext = strtolower(pathinfo($document->file_path, PATHINFO_EXTENSION));

        if (in_array($ext, ['xls', 'xlsx'])) {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(
                    $document->full_path
                );
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Html($spreadsheet);
                $html = $writer->generateHtmlAll();

                return response($html)
                    ->header('Content-Type', 'text/html; charset=utf-8');
            } catch (\Exception $e) {
                abort(500, 'Could not render spreadsheet preview.');
            }
        }

        return $this->view($documentId);
    }

    /**
     * === DOWNLOAD DOCUMENT ===
     *
     * Forces a file download (attachment disposition).
     */
    public function download($documentId)
    {
        $document = Document::findOrFail($documentId);

        if (!Auth::user()->can('view', $document)) {
            abort(403, 'Unauthorized');
        }

        if (!$document->fileExists()) {
            abort(404, 'Document file not found.');
        }

        return Storage::disk('public')->download($document->file_path, $document->document_name);
    }
}