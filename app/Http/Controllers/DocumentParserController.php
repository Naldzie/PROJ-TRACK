<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\AI\GeminiDocumentParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * DocumentParserController
 *
 * AI fallback (Tier 3, very last) for normal-document parsing: sends 1-3 confusing
 * page images the local rules provably cannot solve to Gemini and returns the same
 * 11-field shape the JS parser produces. Accepts exactly one source:
 *   - `images[]` client slices (create-project wizard AND project detail — the client
 *     renders weak pages to canvas via pdf.js and uploads base64; AI-3 wires the slicer),
 *   - a multipart image `file` (photo uploads: png/jpg/jpeg/webp), or
 *   - a `document_id` pointing at a stored IMAGE document (same photo class).
 *
 * PDF uploads / PDF documents are declined with a slice instruction — the server holds
 * no PDF renderer by design (see Tier-3 plan); pages must arrive as images so only the
 * smallest confusing slice ever leaves the server (slice-only data minimization).
 * Results merge into the preview modal for review — never auto-applied (AI-3).
 */
class DocumentParserController extends Controller
{
    // Single-slice byte budget (base64 string length ≈ 4/3 of binary; a 150 DPI page
    // PNG is typically 1-2 MB — 10 MB per slice is generous headroom, not a target).
    private const MAX_SLICE_BYTES = 10485760;

    // Whole-request budget for up to 3 slices.
    private const MAX_TOTAL_BYTES = 31457280;

    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    public function __construct(
        protected GeminiDocumentParserService $parserService,
    ) {}

    public function parseWithGemini(Request $request): JsonResponse
    {
        try {
            $images = $this->resolveSlices($request);

            $parsed = $this->parserService->parseProjectSlice($images);

            if ($parsed === null) {
                throw new RuntimeException('The AI assistant could not extract project fields from these pages. Please fill in the fields manually.');
            }

            return response()->json([
                'success' => true,
                'parsed' => $parsed,
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
                'message' => 'An unexpected error occurred while asking the AI assistant to read the pages.',
            ], 422);
        }
    }

    /**
     * Resolve exactly one image source to base64 slices.
     *
     * Reads stored bytes via the Storage disk (not full_path) so Storage::fake()
     * feature tests exercise the same code as production.
     *
     * @throws RuntimeException
     */
    protected function resolveSlices(Request $request): array
    {
        $hasFile = $request->hasFile('file');
        $documentId = $request->input('document_id');
        $images = $request->input('images');

        $provided = ($hasFile ? 1 : 0) + (!empty($documentId) ? 1 : 0) + (!empty($images) && is_array($images) ? 1 : 0);

        if ($provided !== 1) {
            throw new RuntimeException('Provide exactly one of: page images, an image file, or a document_id.');
        }

        if ($hasFile) {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());

            if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                throw new RuntimeException('Only image files (PNG / JPG / WebP) can be read directly. Render PDF pages to images in the browser first.');
            }

            return [$this->encodeSlice(file_get_contents($file->getRealPath()), $extension)];
        }

        if (!empty($documentId)) {
            $document = Document::find($documentId);

            if (!$document || !$document->fileExists()) {
                throw new RuntimeException('The selected document could not be found.');
            }

            $extension = strtolower($document->getFileExtensionAttribute());

            if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                throw new RuntimeException('Only image documents can be read directly. Render PDF pages to images in the browser first.');
            }

            return [$this->encodeSlice(Storage::disk('public')->get($document->file_path), $extension)];
        }

        if (!is_array($images) || count($images) === 0 || count($images) > 3) {
            throw new RuntimeException('Provide 1 to 3 page images.');
        }

        $slices = [];

        foreach ($images as $img) {
            $data = is_array($img) ? ($img['data'] ?? null) : $img;

            if (!is_string($data) || $data === '') {
                throw new RuntimeException('Each page image must carry base64 data.');
            }

            // Accept raw base64 or data URIs (canvas.toDataURL emits the latter).
            if (str_starts_with($data, 'data:')) {
                $parts = explode(',', $data, 2);

                if (count($parts) !== 2 || $parts[1] === '') {
                    throw new RuntimeException('Each page image must carry base64 data.');
                }

                $mime = 'image/png';

                if (preg_match('#^data:([\w/\-\.+]+);base64$#', $parts[0], $m)) {
                    $mime = strtolower($m[1]);
                }

                $data = $parts[1];
            } else {
                $mime = is_array($img) && !empty($img['mimeType']) ? strtolower($img['mimeType']) : 'image/png';
            }

            if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                throw new RuntimeException('Page images must be PNG, JPEG, or WebP.');
            }

            if (strlen($data) > self::MAX_SLICE_BYTES) {
                throw new RuntimeException('A page image exceeds the size budget. Re-render at 150 DPI and retry.');
            }

            $slices[] = ['data' => $data, 'mimeType' => $mime];
        }

        $total = array_sum(array_map('strlen', array_column($slices, 'data')));

        if ($total > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException('The page images exceed the total size budget. Send fewer pages.');
        }

        return $slices;
    }

    /**
     * Encode raw image bytes to a service slice.
     *
     * @throws RuntimeException
     */
    protected function encodeSlice(string|false $bytes, string $extension): array
    {
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('The image could not be read.');
        }

        $mime = $extension === 'png' ? 'image/png' : ($extension === 'webp' ? 'image/webp' : 'image/jpeg');
        $data = base64_encode($bytes);

        if (strlen($data) > self::MAX_SLICE_BYTES) {
            throw new RuntimeException('The image exceeds the size budget. Re-render at 150 DPI and retry.');
        }

        return ['data' => $data, 'mimeType' => $mime];
    }
}
