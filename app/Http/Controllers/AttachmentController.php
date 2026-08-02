<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

// AttachmentController handles upload / delete / view / download of
// polymorphic record-level evidence files (inspection photo, issue photo,
// resolution proof). Mirrors DocumentController's patterns.
class AttachmentController extends Controller
{
    /**
     * === UPLOAD ATTACHMENT (AJAX) ===
     *
     * Stores a file bound to a parent record (ProjectProgressHistory | Issue)
     * on the public disk at projects/attachments/{PROJECT_CODE}/{context}/...
     * Admin/staff only (AttachmentPolicy::create). Contractors are read-only.
     */
    public function upload(Request $request)
    {
        $validated = $request->validate([
            'project_id'       => ['required', 'integer', 'exists:projects,id'],
            'attachable_type'  => ['required', 'string', 'in:' . implode(',', Attachment::ALLOWED_ATTACHABLE_TYPES)],
            'attachable_id'    => ['required', 'integer'],
            'context'          => ['required', 'string', 'in:' . implode(',', Attachment::CONTEXTS)],
            'file'             => [
                'required',
                'file',
                'max:20480',
                'mimes:jpg,jpeg,png,pdf',
            ],
        ]);

        $project = Project::findOrFail($validated['project_id']);

        if (!Auth::user()->can('create', [Attachment::class, $project])) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to upload attachments for this project.',
            ], 403);
        }

        try {
            $attachableType = $validated['attachable_type'];
            $attachable     = $attachableType::findOrFail($validated['attachable_id']);

            // Guard: the parent record must belong to the same project.
            if ((int) $attachable->project_id !== (int) $project->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'The parent record does not belong to this project.',
                ], 422);
            }

            $file = $request->file('file');

            $storagePath = Attachment::generateStoragePath(
                $project->code,
                $validated['context'],
                $file->getClientOriginalExtension()
            );

            $file->storeAs(
                dirname($storagePath),
                basename($storagePath),
                'public'
            );

            $attachment = Attachment::create([
                'project_id'      => $project->id,
                'attachable_type' => $attachableType,
                'attachable_id'   => $attachable->id,
                'context'         => $validated['context'],
                'original_name'   => $file->getClientOriginalName(),
                'file_path'       => $storagePath,
                'file_size'       => $file->getSize(),
                'mime_type'       => $file->getClientMimeType(),
                'uploaded_by'     => Auth::user()->name ?? Auth::user()->email,
            ]);

            // Audit the upload. project_id population on activity_logs is Phase B.
            ActivityLog::create([
                'user_id'     => Auth::id(),
                'action'      => 'Upload Attachment',
                'description' => Auth::user()->name . ' uploaded an attachment to project "' . $project->title . '".',
                'ip_address'  => $request->ip(),
            ]);

            return response()->json([
                'success'    => true,
                'message'    => 'Attachment uploaded successfully.',
                'attachment' => [
                    'id'            => $attachment->id,
                    'original_name' => $attachment->original_name,
                    'context'       => $attachment->context,
                    'file_url'      => $attachment->file_url,
                    'size_kb'       => $attachment->size_kb,
                    'display_size'  => $attachment->display_size,
                    'uploaded_by'   => $attachment->uploaded_by,
                    'created_at'    => $attachment->created_at->format('M d, Y H:i'),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error uploading attachment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * === DELETE ATTACHMENT (AJAX) ===
     *
     * Deletes the attachment record and its physical file.
     * Admin/staff only (AttachmentPolicy::delete). Records the deletion.
     */
    public function delete(Attachment $attachment)
    {
        if (!Auth::user()->can('delete', $attachment)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete this attachment.',
            ], 403);
        }

        try {
            // Capture details before deleting — model is gone after delete()
            $attachmentName = $attachment->original_name;
            $projectTitle   = $attachment->project->title ?? 'Unknown Project';
            $actorName      = Auth::user()->name ?? Auth::user()->email;

            $attachment->delete();

            ActivityLog::create([
                'user_id'     => Auth::id(),
                'action'      => 'Delete Attachment',
                'description' => "{$actorName} deleted attachment \"{$attachmentName}\" from project \"{$projectTitle}\".",
                'ip_address'  => request()->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Attachment deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting attachment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * === VIEW ATTACHMENT INLINE ===
     *
     * Streams the file back with Content-Disposition: inline so the browser
     * renders PDFs/images. All roles — authorization delegates to the parent
     * project's view policy (AttachmentPolicy::view).
     */
    public function view(Attachment $attachment)
    {
        if (!Auth::user()->can('view', $attachment)) {
            abort(403, 'You do not have permission to view this attachment.');
        }

        if (!$attachment->fileExists()) {
            abort(404, 'Attachment file not found.');
        }

        return Storage::disk('public')->response(
            $attachment->file_path,
            $attachment->original_name,
            ['Content-Disposition' => 'inline; filename="' . addslashes($attachment->original_name) . '"']
        );
    }

    /**
     * === DOWNLOAD ATTACHMENT ===
     *
     * Forces a file download (attachment disposition).
     */
    public function download(Attachment $attachment)
    {
        if (!Auth::user()->can('view', $attachment)) {
            abort(403, 'Unauthorized');
        }

        if (!$attachment->fileExists()) {
            abort(404, 'Attachment file not found.');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->original_name);
    }
}
