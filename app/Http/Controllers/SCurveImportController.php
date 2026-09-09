<?php

namespace App\Http\Controllers;

use App\Models\Document;
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

            $result = $this->sCurveImportService->parse($path);

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
