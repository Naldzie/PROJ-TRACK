<?php

namespace App\Http\Controllers;

use App\Models\Contractor;
use App\Models\Document;
use App\Models\ScurveHeaderProposal;
use App\Services\AI\GeminiDocumentParserService;
use App\Services\SCurveImportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * SCurveImportController
 *
 * Parses a contractor S-curve Excel workbook into baseline months for the
 * Set Up Baseline modal. Accepts either:
 *   - a multipart `file` (create-project wizard, in-memory File), or
 *   - a `document_id` (project detail, reads the stored Schedule-type file).
 *
 * The server returns cumulative planned % per month_index (1..12). The CLIENT
 * derives the real planned_date from the project's planned start date — dates
 * are not present in the workbook, so the service stays pure/testable.
 */
class SCurveImportController extends Controller
{
    public function __construct(
        protected SCurveImportService $sCurveImportService,
        protected GeminiDocumentParserService $geminiParser,
    ) {}

    public function parseExcel(Request $request): JsonResponse
    {
        try {
            $path = $this->resolveSource($request);

            // E1-3: learned layouts ride along as the lowest-priority fallback
            // (rules-first inside the service; empty maps = today's behavior).
            // Loading never breaks imports — any failure yields no maps.
            $result = $this->sCurveImportService->parse($path, $this->loadLiveMaps($request));

            return response()->json([
                'success' => true,
                'months' => $result['months'],
                'meta' => $result['meta'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while reading the S-curve file.',
            ], 422);
        }
    }

    /**
     * AI fallback (E2 Excel Tier 3, opt-in): serialize the workbook to compact CSV
     * text and ask Gemini for the months. Same file|document_id dual input as
     * parseExcel (Excel only — the server holds no PDF renderer); same response
     * shapes. Results merge into the setup modal for review — never auto-applied.
     */
    public function parseWithGemini(Request $request): JsonResponse
    {
        try {
            $path = $this->resolveSource($request);

            $csv = $this->sCurveImportService->serializeForAi($path);
            $result = $this->geminiParser->parseExcelSlice($csv);

            if ($result === null) {
                throw new RuntimeException('The AI assistant could not read an S-curve from this file. Please set up the baseline manually.');
            }

            return response()->json([
                'success' => true,
                'months' => $result['months'],
                'meta' => array_merge($result['meta'], ['aiAssisted' => true]),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while asking the AI assistant to read the S-curve file.',
            ], 422);
        }
    }

    /**
     * E1-3 — load live learned layout maps for this import (server-side consult).
     *
     * Scope order per E1a: this contractor's live maps first, then global
     * (NULL-contractor) maps. Only map-bearing Excel rows travel — version
     * gating stays in the service (single enforcer, also covers direct callers).
     * Wizard sends contractor_id explicitly (project unsaved); detail resolves
     * it via document_id → project. Unknown ids degrade to global-only.
     *
     * Called by: parseExcel() on every import (cheap: live rows are few).
     */
    protected function loadLiveMaps(Request $request): array
    {
        try {
            $contractorId = $request->input('contractor_id');
            if ($contractorId !== null && !Contractor::where('id', $contractorId)->exists()) {
                $contractorId = null;
            }
            if ($contractorId === null && $request->filled('document_id')) {
                $doc = Document::find($request->input('document_id'));
                $contractorId = $doc?->project?->contractor_id;
            }

            $mine = [];
            $global = [];
            $rows = ScurveHeaderProposal::live()
                ->where('source_type', 'excel')
                ->orderBy('id')
                ->get();
            foreach ($rows as $row) {
                if (!is_array($row->column_map)) continue;
                $entry = [
                    'id' => $row->id,
                    'contractor_id' => $row->contractor_id,
                    'column_map' => $row->column_map,
                ];
                if ($contractorId !== null && (int) $row->contractor_id === (int) $contractorId) {
                    $mine[] = $entry;
                } elseif ($row->contractor_id === null) {
                    $global[] = $entry;
                }
            }

            return array_merge($mine, $global);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve the Excel source to a filesystem path.
     *
     * Stored bytes resolve via the Storage disk (not full_path) so Storage::fake()
     * feature tests exercise the same code as production (identical value on local disk).
     *
     * @throws RuntimeException
     */
    protected function resolveSource(Request $request): string
    {
        $hasFile = $request->hasFile('file');
        $documentId = $request->input('document_id');

        if ($hasFile && $documentId) {
            throw new RuntimeException('Provide either an uploaded file or a document_id, not both.');
        }

        if ($hasFile) {
            $file = $request->file('file');

            if (!in_array($file->getClientOriginalExtension(), ['xls', 'xlsx'], true)) {
                throw new RuntimeException('Only Excel (.xls / .xlsx) S-curve files can be imported.');
            }

            return $file->getRealPath();
        }

        if ($documentId) {
            $document = Document::find($documentId);
            if (!$document || !$document->fileExists()) {
                throw new RuntimeException('The selected document could not be found.');
            }

            $extension = strtolower($document->getFileExtensionAttribute());
            if (!in_array($extension, ['xls', 'xlsx'], true)) {
                throw new RuntimeException('The selected document is not an Excel (.xls / .xlsx) file.');
            }

            return Storage::disk('public')->path($document->file_path);
        }

        throw new RuntimeException('No S-curve file was provided.');
    }
}
