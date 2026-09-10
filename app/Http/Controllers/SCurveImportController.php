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
    /**
     * Inline workbook ceiling (2026-09-10): native bytes ride the request only
     * under this size (Gemini inline_data caps at 20MB; headroom kept). Past it
     * the endpoint falls back to the tagged-CSV path with a note — never a refusal.
     */
    public const FILE_INLINE_MAX_BYTES = 15 * 1024 * 1024;

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
     *
     * Prefer-math (2026-09-09, user choice): when an approved learned layout
     * parses the same file and diverges past 1.0, the AI months are REPLACED
     * with the checkable activity math (R3 principle) plus a swap note — the
     * AI can no longer silently bake a summary series into a baseline wherever
     * ground truth exists. No map → AI result stands with its own warnings.
     */
    public function parseWithGemini(Request $request): JsonResponse
    {
        try {
            $path = $this->resolveSource($request);

            $csv = $this->sCurveImportService->serializeForAi($path);
            // Native grid alongside the tagged CSV (user-approved 2026-09-10:
            // public-bidding schedule data; the webapp proved the same model
            // computes correctly from the native sheet).
            $filePart = $this->buildInlineWorkbookPart($path);
            $result = $this->geminiParser->parseExcelSlice($csv, $filePart);

            if ($result === null) {
                throw new RuntimeException('The AI assistant could not read an S-curve from this file. Please set up the baseline manually.');
            }

            $result = $this->preferMapMath($request, $path, $result);

            $meta = array_merge($result['meta'], ['aiAssisted' => true]);
            if ($filePart === null) {
                $meta['warnings'][] = 'Sent as a text excerpt — the workbook was too large to attach.';
            }

            return response()->json([
                'success' => true,
                'months' => $result['months'],
                'meta' => $meta,
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
     * Prefer-math cross-check for AI results (user choice 2026-09-09).
     *
     * Quietly runs the map reader on the same file; when an approved layout
     * parses it and any same-month gap past 1.0 appears, the AI months are
     * replaced with the map's activity math plus a swap note (same threshold
     * as the 2b warnings). Agreeing maps, map-less files, and map failures
     * all leave the AI result untouched — this never blocks, only corrects.
     *
     * Called by: parseWithGemini() after a successful AI read.
     */
    protected function preferMapMath(Request $request, string $path, array $aiResult): array
    {
        try {
            $maps = $this->loadLiveMaps($request);
            if (count($maps) === 0) {
                return $aiResult;
            }

            $mapResult = $this->sCurveImportService->parse($path, $maps);
            if (($mapResult['meta']['mapUsed'] ?? false) !== true) {
                return $aiResult;
            }

            $aiByMonth = [];
            foreach (($aiResult['months'] ?? []) as $m) {
                if (isset($m['month_index'], $m['planned_progress_percent'])) {
                    $aiByMonth[(int) $m['month_index']] = (float) $m['planned_progress_percent'];
                }
            }

            $diverges = false;
            foreach (($mapResult['months'] ?? []) as $m) {
                if (!isset($m['month_index'], $m['planned_progress_percent'])) continue;
                $idx = (int) $m['month_index'];
                if (isset($aiByMonth[$idx])
                    && abs($aiByMonth[$idx] - (float) $m['planned_progress_percent']) > 1.0) {
                    $diverges = true;
                    break;
                }
            }

            if (!$diverges) {
                return $aiResult;
            }

            $months = [];
            foreach ($mapResult['months'] as $m) {
                $months[] = [
                    'month_index' => (int) $m['month_index'],
                    'planned_progress_percent' => (float) $m['planned_progress_percent'],
                ];
            }

            $meta = $aiResult['meta'] ?? [];
            if (($meta['contractAmount'] ?? null) === null
                && isset($mapResult['meta']['contractAmount'])) {
                $meta['contractAmount'] = $mapResult['meta']['contractAmount'];
            }
            $warnings = $meta['warnings'] ?? [];
            $warnings[] = 'Heads-up: the AI matched the file\'s summary line, so the baseline'
                . ' below uses the activity math from the learned layout instead. No action'
                . ' needed unless something looks off.';
            $meta['warnings'] = $warnings;
            $meta['mapPreferred'] = true;

            return ['months' => $months, 'meta' => $meta];
        } catch (\Throwable $e) {
            return $aiResult;
        }
    }

    /**
     * Build the native-workbook inline part for the AI call.
     *
     * Returns ['data' => base64, 'mimeType' => ...] or null when the bytes are
     * unavailable or past the inline ceiling (caller falls back to CSV-only).
     * Best-effort by contract — never throws into the import flow.
     *
     * Called by: parseWithGemini()
     */
    protected function buildInlineWorkbookPart(string $path): ?array
    {
        try {
            if (!is_file($path) || filesize($path) > self::FILE_INLINE_MAX_BYTES) {
                return null;
            }
            $bytes = @file_get_contents($path);
            if ($bytes === false || $bytes === '') {
                return null;
            }
            // Uploaded temp paths carry no extension (phpXXXX.tmp), so sniff the
            // magic bytes: OLE compound document = legacy .xls, otherwise ZIP = .xlsx.
            $mime = str_starts_with($bytes, "\xD0\xCF\x11\xE0")
                ? 'application/vnd.ms-excel'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

            return ['data' => base64_encode($bytes), 'mimeType' => $mime];
        } catch (\Throwable $e) {
            return null;
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
