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
 *   - a `document_id` (project detail, reads the stored document the user
 *     picked — any type; the caller must be allowed to view its project).
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
     *
     * Learn-from-AI (Phase-B): on a trusted AI read with no map in play, derive
     * a proposed layout from the AI's reported columns (best-effort, never
     * blocks the response; never auto-live).
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
                // The answer's NUMBERS may have been refused while its columns were
                // fine — read the sheet ourselves before giving up on the import.
                $result = $this->salvageRejectedAnswer($path);
            }

            if ($result === null) {
                throw new RuntimeException('The AI assistant could not read an S-curve from this file. Please set up the baseline manually.');
            }

            $result = $this->preferMapMath($request, $path, $result);

            // GAP 1 + derived math: read the sheet with the layout the AI reported —
            // names for months it left unnamed, and its own arithmetic when the AI's
            // numbers disagree with the file's activity rows.
            $result = $this->applyDerivedLayout($path, $result);

            // Phase-B: a trusted answer on a map-less file teaches a layout.
            // Skipped when preferMapMath already fired (a map exists — nothing
            // to learn) or the layout rode along unresolved.
            if (!($result['meta']['mapPreferred'] ?? false) && isset($result['layout'])) {
                $this->learnMapFromAi($request, $path, $result);
            }

            $meta = array_merge($result['meta'], ['aiAssisted' => true]);
            if ($filePart === null) {
                $meta['warnings'][] = 'Sent as a text excerpt — the workbook was too large to attach.';
            }

            return response()->json([
                'success' => true,
                'months' => $result['months'],
                'meta' => $meta,
                // E4b (2026-09-13): the Excel path can now climb the parser fallback
                // ladder, so it must say when a rung answered. Null on the normal
                // path. Without this an answer from a weaker model would arrive
                // SILENTLY, which is exactly what this track refuses to do — the
                // client's shared warning stash renders the note by name.
                'fallbackModel' => $this->geminiParser->lastFallbackModel,
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
     * AI fallback (F-B PDF Tier 3, opt-in): read S-curve months from PDF page
     * images the local PDF rules provably cannot solve. Images-only input —
     * the server holds no PDF renderer by design, so the client renders the
     * weakest 1-2 pages to canvas (≈150 DPI) and uploads base64 slices (same
     * shape as DocumentParserController::parseWithGemini). Optional
     * `pageWords[]` carries the machine-read words per page for the
     * printed-words names check (empty on scanned pages).
     *
     * No map consult here (E1 maps are Excel column layouts — PDFs have no
     * columns); F-D learns vocabulary from verified answers instead. The
     * service's monthLabels ride-along is stripped before responding (F-D
     * wires it later). Results merge into the setup modal for review — never
     * auto-applied.
     */
    public function parsePdfWithGemini(Request $request): JsonResponse
    {
        try {
            $images = $this->resolvePdfSlices($request);
            $pageWords = $request->input('pageWords', []);
            $pageWords = is_array($pageWords) ? array_values($pageWords) : [];

            $result = $this->geminiParser->parseSCurvePdfSlice($images, $pageWords);

            if ($result === null) {
                throw new RuntimeException('The AI assistant could not read an S-curve from these pages. Please set up the baseline manually.');
            }

            // F-D vocabulary fuel: exact month-header labels ride the response
            // so the client can harvest NOVEL words as proposals (approval
            // stays the finish line — nothing here is trusted yet).
            $meta = array_merge($result['meta'], ['aiAssisted' => true]);

            return response()->json([
                'success' => true,
                'months' => $result['months'],
                'monthLabels' => $result['monthLabels'] ?? [],
                'meta' => $meta,
                // Images-sender Lite fallback provenance (null unless the
                // backup model answered — review should know whose homework).
                'fallbackModel' => $this->geminiParser->lastFallbackModel,
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
                'message' => 'An unexpected error occurred while asking the AI assistant to read the S-curve pages.',
            ], 422);
        }
    }

    /**
     * Resolve client-rendered PDF page slices to service input.
     *
     * Mirrors DocumentParserController::resolveSlices() budgets (10 MB per
     * slice, 30 MB total, PNG/JPEG/WebP, data-URI or raw base64) — duplicated
     * compactly because this endpoint takes images[] only (no file or
     * document_id: stored S-curve documents are PDFs the server cannot
     * render, and photo uploads are not S-curves).
     *
     * @throws RuntimeException
     */
    protected function resolvePdfSlices(Request $request): array
    {
        $images = $request->input('images');

        if (!is_array($images) || count($images) === 0 || count($images) > 3) {
            throw new RuntimeException('Provide 1 to 3 page images. Render PDF pages to images in the browser first.');
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

            if (strlen($data) > 10485760) {
                throw new RuntimeException('A page image exceeds the size budget. Re-render at 150 DPI and retry.');
            }

            $slices[] = ['data' => $data, 'mimeType' => $mime];
        }

        $total = array_sum(array_map('strlen', array_column($slices, 'data')));

        if ($total > 31457280) {
            throw new RuntimeException('The page images exceed the total size budget. Send fewer pages.');
        }

        return $slices;
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
                // F-3: a document the caller may not view scopes nothing (global maps only)
                if ($doc && !$doc->isViewableBy($request->user())) {
                    $doc = null;
                }
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
                    // The map parse already read the work names from the sheet —
                    // carry them instead of dropping them (they used to be built
                    // here and thrown away, leaving the preferred curve nameless).
                    'activities' => $m['activities'] ?? [],
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
     * GAP 1 + derived math — read the sheet with the layout the AI reported, and
     * let those bytes correct the AI where the two disagree.
     *
     * The Excel reply reports layout keys reliably but keeps omitting per-month
     * "activities", so AI-assisted Excel imports arrived as bare percentages while
     * PDF ones carried names. Rather than fight the prompt, the server reads the
     * sheet with the layout the AI DID report — real cells, no hallucination
     * possible, identical to what a learned-layout import shows.
     *
     * Numbers too (user decision 2026-09-12): on the 10-month file the model
     * returned 40/60/70 for months 5-7 where the sheet's rows say 50/70/80, and its
     * own "Checked" verdict passed because the amounts it invented coincided with
     * budget figures elsewhere in the sheet. When the two disagree past 1.0 the
     * sheet wins and says so; the AI's verdicts are dropped with its arithmetic,
     * since they described numbers no longer on screen. Rationale is the banked
     * reversal: maps store POSITIONS and the curve is recomputed from rows, so
     * correct columns yield a correct curve whatever the months claimed.
     *
     * Mirrors preferMapMath but for the map DERIVED from this answer; an approved
     * map still wins outright, and learning stays approval-gated (a separate flag
     * is used so learnMapFromAi still banks the proposal). Best-effort — any
     * failure leaves the AI's answer exactly as it arrived.
     *
     * Called by: parseWithGemini() after preferMapMath().
     */
    protected function applyDerivedLayout(string $path, array $result): array
    {
        try {
            // An APPROVED map already won — that path is the stronger claim.
            if ($result['meta']['mapPreferred'] ?? false) {
                return $result;
            }
            $layout = $result['layout'] ?? null;
            $months = $result['months'] ?? [];
            if (!is_array($layout) || count($months) === 0) {
                return $result;
            }

            $parsed = $this->sCurveImportService->parseWithAiLayout($layout, $path);
            if ($parsed === null) {
                return $result;
            }

            $sheetByMonth = [];
            foreach (($parsed['months'] ?? []) as $month) {
                $index = (int) ($month['month_index'] ?? 0);
                if ($index > 0) {
                    $sheetByMonth[$index] = $month;
                }
            }
            if (count($sheetByMonth) === 0) {
                return $result;
            }

            // A sheet curve is only worth preferring when it was computed from a
            // real denominator and covers every month the AI reported. Without a
            // contract column or TOTAL row the parse falls back to weights and can
            // produce a degenerate curve (0/0/100) — replacing a good AI answer
            // with that would be a downgrade, so its NAMES still ride but its
            // numbers stay out.
            $trustworthy = ((float) ($parsed['meta']['contractAmount'] ?? 0)) > 0;
            foreach ($months as $month) {
                if (!isset($sheetByMonth[(int) ($month['month_index'] ?? 0)])) {
                    $trustworthy = false;
                    break;
                }
            }

            // Same 1.0 threshold the approved-map comparison uses.
            $diverges = false;
            foreach ($months as $month) {
                $index = (int) ($month['month_index'] ?? 0);
                if (!isset($sheetByMonth[$index])) {
                    continue;
                }
                $gap = abs((float) $month['planned_progress_percent']
                    - (float) $sheetByMonth[$index]['planned_progress_percent']);
                if ($gap > 1.0) {
                    $diverges = true;
                    break;
                }
            }
            $diverges = $diverges && $trustworthy;

            if (!$diverges) {
                // The numbers agree — keep the AI's curve and only fill in names
                // for months it left unnamed.
                foreach ($months as $i => $month) {
                    $index = (int) ($month['month_index'] ?? 0);
                    if (!empty($month['activities']) || !isset($sheetByMonth[$index])) {
                        continue;
                    }
                    $names = $this->activityNamesOf($sheetByMonth[$index]);
                    if (count($names) > 0) {
                        $months[$i]['activities'] = $names;
                    }
                }
                $result['months'] = $months;

                return $result;
            }

            // They disagree: the sheet's own rows win (user decision 2026-09-12).
            $newMonths = [];
            foreach ($parsed['months'] as $month) {
                $newMonths[] = [
                    'month_index' => (int) $month['month_index'],
                    'planned_progress_percent' => (float) $month['planned_progress_percent'],
                    'activities' => $this->activityNamesOf($month),
                ];
            }

            $meta = $result['meta'] ?? [];
            if (($meta['contractAmount'] ?? null) === null
                && isset($parsed['meta']['contractAmount'])) {
                $meta['contractAmount'] = $parsed['meta']['contractAmount'];
            }

            // The AI's own verdicts described ITS arithmetic, which was just
            // rejected — keeping them would describe numbers no longer on screen.
            // The sheet parse brings its own summary comparisons; its
            // learned-layout note is dropped because no approved layout was used.
            $warnings = ['AI-assisted curve — verify before locking the baseline.'];
            $warnings[] = 'Heads-up: the AI\'s monthly numbers did not match the file\'s own'
                . ' activity rows, so the baseline below was recomputed from the sheet using'
                . ' the columns the AI identified. Please review before locking.';
            foreach (($parsed['meta']['warnings'] ?? []) as $warning) {
                if (mb_strpos($warning, 'learned layout') !== false) {
                    continue;
                }
                $warnings[] = $warning;
            }
            $meta['warnings'] = $warnings;
            $meta['derivedMathUsed'] = true;

            $result['months'] = $newMonths;
            $result['meta'] = $meta;

            return $result;
        } catch (\Throwable $e) {
            // Never let the second opinion break an import.
            return $result;
        }
    }

    /**
     * Rescue an AI answer whose NUMBERS were rejected by the fabrication guards.
     *
     * Those guards (months not following the AI's own amounts; a claimed peso
     * printed nowhere in the sheet) used to end the import with "could not read"
     * — thrown away before any map work, even though the rejected reply almost
     * always still named the right columns. Column trust is not month trust, so
     * the letters are used to read the sheet and the SERVER's own math fills the
     * baseline instead. The AI's refused figures never reach the preview.
     *
     * Considered and rejected (user 2026-09-12): retrying the AI. The parser
     * sends temperature 0.0, so a second sample is near-deterministic — a
     * systematic misreading reproduces identically and its agreement merely looks
     * like confidence. Reading real cells is better evidence and costs no quota.
     *
     * Returns null when no layout was banked or no map derives, so the caller
     * throws the original message unchanged.
     *
     * Called by: parseWithGemini() when parseExcelSlice() declines.
     */
    protected function salvageRejectedAnswer(string $path): ?array
    {
        try {
            $layout = $this->geminiParser->lastRejectedLayout;
            if (!is_array($layout) || count($layout) === 0) {
                return null;
            }

            $parsed = $this->sCurveImportService->parseWithAiLayout($layout, $path);
            if ($parsed === null || count($parsed['months'] ?? []) === 0) {
                return null;
            }

            $months = [];
            foreach ($parsed['months'] as $month) {
                $months[] = [
                    'month_index' => (int) $month['month_index'],
                    'planned_progress_percent' => (float) $month['planned_progress_percent'],
                    'activities' => $this->activityNamesOf($month),
                ];
            }

            $warnings = ['AI-assisted curve — verify before locking the baseline.'];
            $warnings[] = 'Heads-up: the AI\'s monthly figures did not check out against the file,'
                . ' so the baseline below was read from the sheet itself using the columns the AI'
                . ' identified. Please review carefully before locking.';
            foreach (($parsed['meta']['warnings'] ?? []) as $warning) {
                // No approved layout was involved — that note would misdescribe this.
                if (mb_strpos($warning, 'learned layout') !== false) {
                    continue;
                }
                $warnings[] = $warning;
            }

            return [
                'months' => $months,
                'meta' => [
                    'contractAmount' => $parsed['meta']['contractAmount'] ?? null,
                    'warnings' => $warnings,
                    'derivedMathUsed' => true,
                ],
                // Kept so learnMapFromAi still banks the proposal: the columns
                // earned trust here even though the arithmetic did not.
                'layout' => $layout,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Flatten a parsed month's activities to plain, de-duplicated names.
     *
     * The rule/map path stores activities as objects; the AI path sends bare
     * strings. Both reach the same preview, so one shape goes out.
     *
     * Called by: applyDerivedLayout().
     */
    protected function activityNamesOf(array $month): array
    {
        $names = [];
        foreach (($month['activities'] ?? []) as $activity) {
            $name = trim((string) (is_array($activity)
                ? ($activity['activity_name'] ?? '')
                : $activity));
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Phase-B learn-from-AI (best-effort, never blocks the response).
     *
     * Derives a proposed layout from the AI's reported columns and stores it
     * (backfill-or-create, ai-derived, never auto-live — approval stays the sole
     * live flip). Untrusted answers never teach: mirrored months and adopted
     * summaries are refused inside derivation, and preferMapMath wins first
     * (a map exists → nothing to learn). Uses the same Log import as the file.
     *
     * Called by: parseWithGemini() after a successful, unswapped AI read.
     */
    protected function learnMapFromAi(Request $request, string $path, array $aiResult): void
    {
        $note = function (string $reason, array $extra = []): void {
            try {
                \Illuminate\Support\Facades\Log::debug('E1-B learn skipped', array_merge(['reason' => $reason], $extra));
            } catch (\Throwable $ignored) {
            }
        };

        try {
            $layout = $aiResult['layout'] ?? null;
            if (!is_array($layout)) {
                $note('no-layout');

                return;
            }

            $reason = null;
            $map = $this->sCurveImportService->deriveMapFromAiLayout($layout, $path, $reason);
            if ($map === null) {
                $note($reason ?? 'underivable');

                return;
            }

            // Contractor scope mirrors loadLiveMaps (explicit input wins, else the
            // stored document's project; AI routes carry no project_id).
            $contractorId = $request->input('contractor_id');
            if ($contractorId !== null && !Contractor::where('id', $contractorId)->exists()) {
                $contractorId = null;
            }
            if ($contractorId === null && $request->filled('document_id')) {
                $doc = Document::find($request->input('document_id'));
                // F-3: a document the caller may not view scopes nothing (global maps only)
                if ($doc && !$doc->isViewableBy($request->user())) {
                    $doc = null;
                }
                $contractorId = $doc?->project?->contractor_id;
            }

            $text = $this->sCurveImportService->serializeForAi($path, 15);
            $hash = hash('sha256', mb_strtolower(preg_replace('/\s+/', ' ', trim($text))));
            $row = ScurveHeaderProposal::storeAiDerived(
                $contractorId !== null ? (int) $contractorId : null,
                $hash,
                $text,
                $map
            );
            try {
                \Illuminate\Support\Facades\Log::debug('E1-B learned map', [
                    'proposal' => $row->id,
                    'status' => $row->status,
                    'source' => $row->source,
                ]);
            } catch (\Throwable $ignored) {
            }
        } catch (\Throwable $e) {
            // Learning never breaks imports.
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
            // F-3 (2026-09-21): only a document of a project the caller may view.
            // Same message as "not found", so ids of other projects are not revealed.
            if (!$document || !$document->isViewableBy($request->user()) || !$document->fileExists()) {
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
