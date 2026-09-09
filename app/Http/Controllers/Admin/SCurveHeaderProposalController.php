<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\Project;
use App\Models\ScurveHeaderProposal;
use App\Services\SCurveImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * ScurveHeaderProposalController
 *
 * E1-1 silent capture: stashes header blocks from failed S-curve imports
 * ("No S-curve table found" 422s) as proposed layout rows. Collection is open to
 * admin AND staff (E1b: same trust as keywords); contractors get 403 (read-only,
 * they never upload). Captures never affect extraction until E1-2 review.
 */
class ScurveHeaderProposalController extends Controller
{
    public function __construct(
        protected SCurveImportService $sCurveImportService,
    ) {}

    public function collect(Request $request): JsonResponse
    {
        if (!in_array(auth()->user()->role, ['admin', 'staff'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'contractor_id' => 'nullable|integer|exists:contractors,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'source_type' => 'sometimes|string|in:excel,pdf',
        ]);

        $hasFile = $request->hasFile('file');
        $documentId = $request->input('document_id');

        if (($hasFile ? 1 : 0) + (!empty($documentId) ? 1 : 0) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Provide either an uploaded file or a document_id.',
            ], 422);
        }

        try {
            $path = $this->resolveSource($request, $hasFile, $documentId);
            $contractorId = $this->resolveContractor($validated, $documentId);
            $text = $this->sCurveImportService->serializeForAi($path, 15);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $hash = hash('sha256', mb_strtolower(preg_replace('/\s+/', ' ', trim($text))));

        $query = ScurveHeaderProposal::where('header_hash', $hash);
        if ($contractorId === null) {
            $query->whereNull('contractor_id');
        } else {
            $query->where('contractor_id', $contractorId);
        }

        $row = $query->first();
        if ($row) {
            $row->increment('occurrences');
            if ($row->occurrences >= 3 && $row->status === 'proposed') {
                $row->update(['status' => 'live']);
            }
        } else {
            ScurveHeaderProposal::create([
                'contractor_id' => $contractorId,
                'source_type' => $validated['source_type'] ?? 'excel',
                'header_rows_text' => mb_substr($text, 0, 4000),
                'header_hash' => $hash,
                'column_map' => null,
                'occurrences' => 1,
                'status' => 'proposed',
                'source' => 'auto-capture',
            ]);
        }

        return response()->json(['success' => true, 'stored' => 1], 201);
    }

    /**
     * Resolve the workbook to a filesystem path (same dual input as the importer).
     *
     * Reads stored bytes via the Storage disk (not full_path) so Storage::fake()
     * feature tests exercise the same code as production.
     *
     * @throws RuntimeException
     */
    protected function resolveSource(Request $request, bool $hasFile, mixed $documentId): string
    {
        if ($hasFile) {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, ['xls', 'xlsx'], true)) {
                throw new RuntimeException('Only Excel (.xls / .xlsx) header blocks can be captured.');
            }

            return $file->getRealPath();
        }

        $document = Document::find($documentId);
        if (!$document || !$document->fileExists()) {
            throw new RuntimeException('The selected document could not be found.');
        }

        $extension = strtolower($document->getFileExtensionAttribute());
        if (!in_array($extension, ['xls', 'xlsx'], true)) {
            throw new RuntimeException('Only Excel (.xls / .xlsx) header blocks can be captured.');
        }

        return Storage::disk('public')->path($document->file_path);
    }

    /**
     * Resolve the contractor: explicit id wins, else the document's project,
     * else null (global map per E1a hybrid scope).
     */
    protected function resolveContractor(array $validated, mixed $documentId): ?int
    {
        if (!empty($validated['contractor_id'])) {
            return Contractor::where('id', $validated['contractor_id'])->exists()
                ? (int) $validated['contractor_id']
                : null;
        }

        if (!empty($validated['project_id'])) {
            $project = Project::find($validated['project_id']);

            return $project?->contractor_id;
        }

        if (!empty($documentId)) {
            $document = Document::find($documentId);

            return $document?->project?->contractor_id;
        }

        return null;
    }
}
