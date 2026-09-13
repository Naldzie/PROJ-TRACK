<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ============================================================================
 * GeminiDocumentParserService
 * ============================================================================
 *
 * Purpose
 * -------
 * AI fallback (Tier 3, very last) for document parsing: reads 1-3 confusing page
 * images the local rules provably cannot solve and returns the same 11-field shape
 * the JS parser produces. Only ever called after Tier 1 (local rules) + OCR paths
 * admit defeat, and only on user opt-in ("[Try AI assist?]").
 *
 * Architecture
 * ------------
 * Same Gemini shape as AIRecommendationService::callGemini() (config key/model,
 * Http::timeout(30), candidates[0] text, fail-closed to empty). JSON fence-strip +
 * field validation mirror its parseResponse()/validateRecommendation() idiom.
 * Returns null on every failure mode so callers fall back to manual fill.
 *
 * Data minimization (user-approved sensitivity basis)
 * ---------------------------------------------------
 * Contract/supporting papers are public-bidding records, but only the smallest
 * confusing slice is ever sent: max 3 page images (150 DPI renders), never the whole
 * file. Values returned still pass preview review — never auto-applied.
 *
 * Fallback
 * --------
 * Returns null on any failure:
 * - No images / empty API key
 * - HTTP errors (4xx, 5xx) / timeouts (30s)
 * - Empty response text / malformed JSON / non-object JSON
 * - Every field null after validation (AI found nothing either)
 *
 * Used By
 * -------
 * DocumentParserController::parseWithGemini() (planned AI-2).
 *
 * Depends On
 * ----------
 * config/services.php — Gemini API key and model name
 */
class GeminiDocumentParserService
{
    // The 11 preview fields (mirrors AUTOFILL_CORE_FIELDS core names in
    // public/js/admin/projects/document-parser.js + parseProjectInfo keys).
    public const FIELDS = [
        'title',
        'location',
        'description',
        'budget',
        'abc',
        'budgetSource',
        'category',
        'startDate',
        'endDate',
        'duration',
        'contractor',
    ];

    // Free-tier guard: never send more than 3 page images in one call.
    private const MAX_IMAGES = 3;

    /**
     * E6 (2026-09-13): seconds allowed for ONE HTTP attempt, PER PATH. Also the
     * size of the slot the time budget must see free before starting an attempt —
     * one that cannot finish inside the budget is not worth beginning.
     *
     * The two senders carry categorically different payloads, so one number would
     * be wrong for one of them:
     *  - IMAGES: up to 3 small PNG page slices. Measured live across four runs —
     *    real answers arrived in 8s and 10s, refusals in 3-9s, and only a HUNG
     *    connection ever reached the old 30s. That hang was costing 77% of a
     *    ladder walk and starving the rungs behind it (the 15:12 run never reached
     *    gemini-3.5-flash). 15s keeps 50% headroom over anything that has actually
     *    worked while halving what a hang can steal.
     *  - TEXT: the Excel path uploads the NATIVE WORKBOOK inline, up to 15MB
     *    (SCurveImportController::FILE_INLINE_MAX_BYTES). Guzzle's timeout covers
     *    the upload, so a large .xlsx on a slow connection can spend 15s on
     *    transfer alone. It keeps 30s — none of the evidence above came from this
     *    path, and cutting it would risk breaking imports that work today.
     */
    private const DEFAULT_IMAGE_TIMEOUT = 15;
    private const DEFAULT_TEXT_TIMEOUT = 30;

    /** Sanity rails for the configured values — a typo must not disable the guard. */
    private const MIN_TIMEOUT = 5;
    private const MAX_TIMEOUT = 120;

    /**
     * E5: wall-clock instant after which no NEW attempt may start, or null when
     * no call is in flight. Per-call state, set by the senders exactly where
     * lastFallbackModel is reset, for the same reason: it must describe THIS call.
     *
     * Why it exists (measured live 2026-09-13): PHP kills a request at
     * max_execution_time — 60s on the dev server — and a FATAL cannot be caught.
     * A ladder walk ran 74s, died mid-rung, and the client received an HTML error
     * page instead of JSON, so the mapped "the AI service is busy" message was
     * replaced by the generic "could not read an S-curve from these pages". The
     * user was told the FILE was unreadable when the truth was that the service
     * was overloaded — the same class of lie the Lite rescue was reverted for.
     */
    private ?float $deadline = null;

    /**
     * Normal-doc Lite fallback marker (user-approved 2026-09-10: images sender
     * only, Excel never). Holds the backup model id when the last call was
     * answered by Lite, else null. Reset at the start of every images call so
     * stale flags can never leak across runs; read by DocumentParserController
     * to provenance-tag the response.
     */
    public ?string $lastFallbackModel = null;

    /**
     * Layout keys of an answer whose NUMBERS were rejected by the fabrication
     * guards (2026-09-12). Column trust is not month trust: a reply whose
     * arithmetic fails still usually names the right columns, and those letters
     * let the server compute the curve from real cells instead of sending the
     * user away with "could not read". Set ONLY where those two guards decline,
     * so malformed replies and transport failures can never trigger a salvage;
     * reset at the start of every Excel call so a stale layout cannot leak.
     * Read by SCurveImportController::salvageRejectedAnswer().
     */
    public ?array $lastRejectedLayout = null;

    /**
     * E3 (2026-09-10): parser credential seam — document/Excel parsing uses its
     * own key + model (institute second key; pinned gemini-2.5-flash), falling
     * back to the shared prediction credentials when the parser env is missing.
     * Runtime fallback (not just config-file) so direct config overrides and
     * cached configs resolve the same way.
     *
     * Called by: callGeminiText(), callGeminiWithImages().
     */
    private function parserKey(): ?string
    {
        $key = config('services.gemini.parser_key') ?? config('services.gemini.key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * E3 parser model seam (fallback chain ends at the legacy default).
     *
     * Called by: callGeminiText(), callGeminiWithImages().
     */
    private function parserModel(): string
    {
        $model = config('services.gemini.parser_model') ?? config('services.gemini.model');

        return is_string($model) && $model !== '' ? $model : 'gemini-1.5-flash';
    }

    /**
     * E4 (2026-09-13): the parser's own fallback ladder, in order.
     *
     * Reads AI_PARSER_FALLBACK_MODELS (comma-separated) and returns the rungs to
     * try after the parser model fails a transport check. Empty by default, so an
     * unconfigured install behaves exactly as it did before this existed.
     *
     * WHY NOT backupModel(): that resolves to config('services.gemini.model') =
     * AI_MODEL, which is the Lite family, and the S-curve paths exclude Lite by
     * user decision (2026-09-11) because it copies author summaries instead of
     * computing. A version ladder is a different thing entirely — 3.5/3.6 DO
     * compute — and Gemini meters per MODEL, so each rung carries its own quota.
     *
     * The parser model itself is dropped if it is listed, so a copy-pasted env
     * cannot make the same model answer twice.
     *
     * Called by: parseProjectSlice(), parseSCurvePdfSlice().
     */
    private function parserFallbackModels(): array
    {
        $raw = config('services.gemini.parser_fallback_models');

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $primary = $this->parserModel();
        $models = [];
        foreach (explode(',', $raw) as $model) {
            $model = trim($model);
            if ($model === '' || $model === $primary || in_array($model, $models, true)) {
                continue;
            }
            $models[] = $model;
        }

        return $models;
    }

    /**
     * E5: open the wall-clock window for one AI call.
     *
     * The budget is the SMALLER of the configured value and what PHP will
     * actually allow, so a tighter server limit narrows it automatically and a
     * generous config value can never make it unsafe. max_execution_time is 0
     * (unlimited) under CLI, which is why tests are unaffected by it.
     * Ten seconds of headroom are left for everything either side of the AI
     * call — receiving the uploaded slices, validating, rendering the response.
     *
     * Called by: callGeminiText(), callGeminiWithImages().
     */
    private function startTimeBudget(): void
    {
        $budget = (float) config('services.gemini.parser_time_budget', 50);
        if (!is_finite($budget) || $budget <= 0) {
            $budget = 50.0;
        }

        $phpLimit = (float) ini_get('max_execution_time');
        if ($phpLimit > 0) {
            $budget = min($budget, max(5.0, $phpLimit - 10.0));
        }

        $this->deadline = microtime(true) + $budget;
    }

    /**
     * E6: seconds one attempt on THIS path may take.
     *
     * Selected from $where, which the senders already tag 'images'/'text' (and
     * '-fallback' for a rung), so no extra per-call state is needed. Configurable
     * because the right number depends on the file: the Excel path's ceiling is
     * dominated by UPLOAD time for a workbook that may be 15MB, which nothing
     * measured so far describes.
     *
     * Called by: postGemini(), canAffordAttempt().
     */
    private function requestTimeout(string $where): int
    {
        $isImages = str_starts_with($where, 'images');
        $configured = $isImages
            ? config('services.gemini.parser_image_timeout', self::DEFAULT_IMAGE_TIMEOUT)
            : config('services.gemini.parser_text_timeout', self::DEFAULT_TEXT_TIMEOUT);

        $seconds = is_numeric($configured)
            ? (int) $configured
            : ($isImages ? self::DEFAULT_IMAGE_TIMEOUT : self::DEFAULT_TEXT_TIMEOUT);

        return max(self::MIN_TIMEOUT, min(self::MAX_TIMEOUT, $seconds));
    }

    /**
     * E5: is there room for another full attempt before the deadline?
     *
     * Deliberately asks for a WHOLE attempt's worth of time rather than shrinking
     * the timeout to fit (user decision 2026-09-13). A squeezed timeout can cut
     * off a model that was about to answer, turning a slow success into a
     * failure; stopping early and saying so is the honest trade.
     *
     * E6: the slot is the SAME per-path value the attempt would actually use.
     * These two must move together — a reservation that does not match what an
     * attempt costs either starves the ladder (too large) or lets it overrun
     * (too small), and the overrun is the uncatchable PHP fatal.
     *
     * Called by: postGemini() before a retry, climbFallbackLadder() before a rung.
     */
    private function canAffordAttempt(string $where): bool
    {
        if ($this->deadline === null) {
            return true;
        }

        return ($this->deadline - microtime(true)) >= $this->requestTimeout($where);
    }

    /**
     * E3: one-line failure breadcrumb so a dead model ID (404) or revoked key
     * (4xx) is diagnosable from logs instead of looking like "AI found nothing".
     * Carries the quota reason when the body names one (per-day vs per-minute
     * decides retry-vs-tomorrow downstream and in human debugging).
     */
    private function logGeminiFailure(string $where, string $model, mixed $status, ?string $quotaId = null): void
    {
        try {
            Log::debug('E3 gemini parser call failed', [
                'where' => $where,
                'model' => $model,
                'status' => $status,
                'quota' => $quotaId,
            ]);
        } catch (\Throwable $e) {
            // Logging never breaks calls.
        }
    }

    /**
     * POST to Gemini with one bounded retry (user choice 2026-09-10: 1 retry —
     * worst case ~35s at the spinner; sustained saturation still fails fast-ish).
     *
     * Retries TRANSIENT failures only (429/502/503/504 + connection throws),
     * honoring the Retry-After header when Google sends one (capped at 20s).
     * Returns ['status' => int, 'payload' => decoded body]. Throws
     * RuntimeException with a status-mapped message when transport ultimately
     * fails — validation misses still return null downstream (unchanged), so
     * only transport gets a voice, never silent nulls for dead models/keys.
     *
     * $maxAttempts caps the tries (E4): a FALLBACK rung passes 1, because the
     * parser model has already spent the retry budget and a ladder that retried
     * every rung would keep the user waiting minutes. Routing rungs through here
     * rather than a bare Http::post is what keeps them diagnosable — status log,
     * quota meter and mapped voice all come from this one place.
     *
     * Called by: callGeminiText(), callGeminiWithImages().
     */
    private function postGemini(string $url, array $payload, string $model, string $where, int $maxAttempts = 2): array
    {
        $maxAttempts = max(1, $maxAttempts);
        $attempts = 0;
        $lastStatus = 0;
        $lastQuotaId = null;

        while ($attempts < $maxAttempts) {
            $attempts++;
            try {
                $response = Http::timeout($this->requestTimeout($where))->post($url, $payload);
                $status = $response->status();
                if ($response->successful()) {
                    return ['status' => $status, 'payload' => $response->json()];
                }
                $lastStatus = $status;
                // Quota-kind handling (2026-09-10): the body names the meter.
                // Per-DAY exhaustion fails fast with a tomorrow message — retrying
                // burns quota and time for nothing. Per-minute keeps backoff.
                $lastQuotaId = $this->extractQuotaId($response);
                $this->logGeminiFailure($where, $model, $status, $lastQuotaId);
                if ($lastQuotaId !== null && $this->isPerDayQuota($lastQuotaId)) {
                    break;
                }
                if (!$this->isRetryableStatus($status) || $attempts >= $maxAttempts) {
                    break;
                }
                // E5 — a retry that cannot finish inside the budget is worse than
                // no retry: it burns the window a fallback rung could have used and
                // risks the uncatchable PHP fatal.
                if (!$this->canAffordAttempt($where)) {
                    break;
                }
                $this->backoff($response);
            } catch (\Exception $e) {
                $this->logGeminiFailure($where, $model, 'throwable');
                if ($attempts >= $maxAttempts) {
                    throw new GeminiTransportException('The AI service could not be reached. Please try again in a moment.', 0, null, $e);
                }
                $this->backoff(null);
            }
        }

        throw new GeminiTransportException($this->transportMessage($lastStatus, $lastQuotaId), $lastStatus, $lastQuotaId);
    }

    /**
     * Transient failures worth one retry (Google's own guidance: wait + retry
     * for these; fail fast on everything else like dead IDs and bad keys).
     *
     * Called by: postGemini().
     */
    private function isRetryableStatus(int $status): bool
    {
        return in_array($status, [429, 502, 503, 504], true);
    }

    /**
     * Pause before the single retry — Retry-After when sent (capped), else a
     * short default. Zero-safe for tests faking Retry-After: 0.
     *
     * Called by: postGemini().
     */
    private function backoff($response): void
    {
        $wait = 2;
        try {
            $retryAfter = $response !== null ? $response->header('Retry-After') : null;
            if ($retryAfter !== null && $retryAfter !== '' && is_numeric($retryAfter)) {
                $wait = min(20, max(0, (int) $retryAfter));
            }
        } catch (\Throwable $e) {
            // Default stands.
        }
        if ($wait > 0) {
            sleep($wait);
        }
    }

    /**
     * Read the quota meter from an error body (QuotaFailure violations).
     * Returns the first quotaId found, else null (non-quota errors, bodies
     * without details, and undecodable bodies all read as "no meter named").
     *
     * Called by: postGemini().
     */
    private function extractQuotaId($response): ?string
    {
        try {
            $body = $response->json();
            if (!is_array($body)) {
                return null;
            }
            $details = $body['error']['details'] ?? null;
            if (!is_array($details)) {
                return null;
            }
            foreach ($details as $detail) {
                if (!is_array($detail)) continue;
                $type = (string) ($detail['@type'] ?? '');
                if (stripos($type, 'QuotaFailure') === false) continue;
                foreach ((array) ($detail['violations'] ?? []) as $violation) {
                    $quotaId = is_array($violation) ? ($violation['quotaId'] ?? null) : null;
                    if (is_string($quotaId) && $quotaId !== '') {
                        return $quotaId;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Unreadable body — no meter named.
        }

        return null;
    }

    /**
     * Per-day exhaustion never heals within a session (midnight Pacific at best).
     *
     * Called by: postGemini().
     */
    private function isPerDayQuota(string $quotaId): bool
    {
        return stripos($quotaId, 'PerDay') !== false;
    }

    /**
     * User-facing transport messages per status (validation misses keep their
     * own existing texts — this voice is transport-only).
     *
     * Called by: postGemini().
     */
    private function transportMessage(int $status, ?string $quotaId = null): string
    {
        if ($status === 503) {
            return 'The AI service is busy (overloaded). Please wait a minute and try again.';
        }
        if ($status === 429) {
            if ($quotaId !== null && $this->isPerDayQuota($quotaId)) {
                return "Today's AI allowance is used up. Please try again tomorrow.";
            }

            return 'The AI service quota is exhausted for now. Please try again later.';
        }
        if ($status === 404) {
            return 'The configured AI model is unavailable. Please contact the administrator.';
        }

        return 'The AI service could not be reached. Please try again in a moment.';
    }

    /**
     * Read project fields from page images.
     *
     * Accepts a list of ['data' => base64, 'mimeType' => 'image/png'] slices.
     * Returns the 11-key shape (values or null per field), or null when nothing
     * usable comes back.
     *
     * Called by: DocumentParserController::parseWithGemini()
     */
    public function parseProjectSlice(array $images): ?array
    {
        $images = array_values(array_filter($images, function ($img) {
            return is_array($img) && !empty($img['data']);
        }));

        if (count($images) === 0) {
            return null;
        }

        $images = array_slice($images, 0, self::MAX_IMAGES);

        // Normal-doc tries the version ladder FIRST, then keeps its one-shot Lite
        // rescue as the last rung (user decision 2026-09-13). Autofill is reviewed
        // field by field, so a rough fill still beats no fill — but there is no
        // reason to reach for the weakest model while a computing one is available.
        // With no ladder configured this is byte-identical to the 2026-09-10
        // behaviour: Lite, once.
        $raw = $this->callGeminiWithImages(
            $this->buildPrompt(),
            $images,
            [...$this->parserFallbackModels(), $this->backupModel()]
        );

        if ($raw === '') {
            return null;
        }

        $decoded = $this->parseJsonObject($raw);

        if ($decoded === null) {
            return null;
        }

        return $this->validateParsed($decoded);
    }

    /**
     * Read S-curve months from serialized workbook text (E2 Excel fallback).
     *
     * Accepts compact CSV text (header block + data rows, produced by
     * SCurveImportService::serializeForAi — never the whole workbook) and returns
     * ['months' => [{month_index, planned_progress_percent}], 'meta' => [
     * 'contractAmount' => ?float, 'warnings' => [...]]] with the same sanitization
     * the rule importer applies (monotonic clamp + force-last-100), or null.
     * Null on: empty text/key, HTTP failure, malformed JSON, <2 usable months
     * AND <2 usable summary points.
     *
     * E2 parity (2026-09-09): mirrors the rule importer's 2b reference+warn —
     * the prompt asks for TWO series (activity-derived months + the author's own
     * summary row); the activity series is kept, summary gaps past 1.0 warn with
     * the same plain wording, and the summary is adopted ONLY when no usable
     * activity values exist (same fallback the rules have). Previously the AI
     * returned a single series that could silently be the summary row.
     *
     * Native grid (2026-09-10, user-approved): $workbookFile carries the native
     * .xlsx bytes (inline_data) alongside the tagged CSV — the webapp proved the
     * same model computes correctly from the native sheet while flat CSV elicited
     * copies. S-curves are public-bidding schedule data (transparency class), so
     * the whole workbook is proportionate; Excel-only, one explicit click, never
     * auto-applied. Null file = text-only path (oversize fallback).
     *
     * Called by: SCurveImportController::parseWithGemini() (planned E2 endpoint).
     */
    public function parseExcelSlice(string $csv, ?array $workbookFile = null): ?array
    {
        $this->lastRejectedLayout = null;
        $csv = trim($csv);

        if ($csv === '') {
            return null;
        }

        $filePart = null;
        if (is_array($workbookFile) && !empty($workbookFile['data'])) {
            $filePart = $workbookFile;
        }

        $raw = $this->callGeminiText($this->buildSCurvePrompt($csv, $filePart !== null), $filePart);

        if ($raw === '') {
            return null;
        }

        $decoded = $this->parseJsonObject($raw);

        if ($decoded === null) {
            return null;
        }

        // Debug breadcrumb (evidence, not inference): what the model actually
        // returned, so silent single-series replies are diagnosable in logs.
        try {
            Log::debug('E2 excel slice response keys', [
                'keys' => is_array($decoded) ? array_keys($decoded) : [],
                'months' => is_array($decoded['months'] ?? null) ? count($decoded['months']) : 0,
                // Per-month key, so the top-level list above can never reveal it —
                // and "did the model send names at all?" cost a whole round trip to
                // answer by code reading once. It is one number; log it.
                'monthsWithActivities' => count(array_filter(
                    (array) ($decoded['months'] ?? []),
                    fn ($m) => is_array($m) && !empty($m['activities'])
                )),
                'summary' => is_array($decoded['summary'] ?? null) ? count($decoded['summary']) : 0,
                'amounts' => is_array($decoded['monthlyAmounts'] ?? null) ? count($decoded['monthlyAmounts']) : 0,
                'hasSummaryRow' => $decoded['hasSummaryRow'] ?? null,
                'layoutKeys' => is_array($decoded) ? array_values(array_intersect(
                    array_keys($decoded),
                    ['headerRow', 'monthColumns', 'descCol', 'contractCol', 'weightCol', 'codeCol']
                )) : [],
            ]);
        } catch (\Throwable $e) {
            // Logging never breaks imports.
        }

        // The CSV is the sheet's own text — it is what activity names are checked
        // against, exactly as page words are on the PDF path.
        return $this->validateSCurveMonths($decoded, $this->extractSheetNumbers($csv), $csv);
    }

    /**
     * Read S-curve months from PDF page images (F-A PDF AI fallback).
     *
     * Accepts 1-3 page-image slices (client-rendered ~150 DPI PNGs of the
     * weakest pages, same shape as parseProjectSlice) plus the machine-read
     * words per page ($pageWords, from client pdf.js text — may be empty for
     * scanned pages). Returns ['months' => [{month_index,
     * planned_progress_percent, activities: [names]}], 'meta' => [
     * 'contractAmount' => ?float, 'warnings' => [...]], 'monthLabels' => {m:
     * exact header text}] with the same sanitization the Excel path applies
     * (monotonic clamp + force-last-100), or null.
     * Null on: empty images/key, HTTP failure, malformed JSON, <2 usable
     * months AND <2 usable summary points, fabricated homework.
     *
     * Names policy (user decision 2026-09-11: drop, keep generic): activity
     * names must match words printed on the page — hallucinated names are
     * dropped server-side and the client falls back to generic labels.
     * 'monthLabels' rides along as F-D vocabulary fuel (controller strips
     * before responding; never trusted without approval).
     *
     * Called by: SCurveImportController::parsePdfWithGemini() (planned F-B).
     */
    public function parseSCurvePdfSlice(array $images, array $pageWords = []): ?array
    {
        $images = array_values(array_filter($images, function ($img) {
            return is_array($img) && !empty($img['data']);
        }));

        if (count($images) === 0) {
            return null;
        }

        $images = array_slice($images, 0, self::MAX_IMAGES);

        $pageText = implode("\n", array_filter(array_map(function ($w) {
            return is_string($w) ? $w : '';
        }, $pageWords)));

        // NEVER Lite (user decision 2026-09-11): Lite copies author summaries
        // instead of computing (E3 verdict), the exact failure Excel excluded it
        // for, and a copied summary in a BASELINE is not a reviewable convenience.
        // E4 (2026-09-13) adds a VERSION ladder instead — 3.5/3.6 do compute, and
        // their one observed error on this family (cost-weighted arithmetic over
        // printed whole-number percentages) is caught and corrected by the
        // AI-vs-file cross-check. Empty unless configured, so the default is still
        // Flash-or-nothing; a ladder that also fails surfaces the real error.
        $raw = $this->callGeminiWithImages($this->buildSCurvePdfPrompt($pageWords), $images, $this->parserFallbackModels());

        if ($raw === '') {
            return null;
        }

        $decoded = $this->parseJsonObject($raw);

        if ($decoded === null) {
            return null;
        }

        // Debug breadcrumb (evidence, not inference): what the model actually
        // returned, so silent single-series replies are diagnosable in logs.
        try {
            Log::debug('F-A pdf slice response keys', [
                'keys' => is_array($decoded) ? array_keys($decoded) : [],
                'months' => is_array($decoded['months'] ?? null) ? count($decoded['months']) : 0,
                'summary' => is_array($decoded['summary'] ?? null) ? count($decoded['summary']) : 0,
                'amounts' => is_array($decoded['monthlyAmounts'] ?? null) ? count($decoded['monthlyAmounts']) : 0,
                'hasSummaryRow' => $decoded['hasSummaryRow'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Logging never breaks imports.
        }

        return $this->validatePdfCurve($decoded, $pageText);
    }

    /**
     * Build the strict-JSON PDF S-curve extraction prompt around page images.
     *
     * Same two-series homework as the Excel prompt (activity-derived months +
     * monthly peso amounts and divisor, recomputed server-side; author's own
     * summary row copied as printed, never merged) plus per-month activity
     * names copied EXACTLY as printed — invented names are dropped downstream,
     * so the prompt forbids them outright. The machine-read words (when the
     * pages carry selectable text) ride along as alignment reference.
     *
     * Called by: parseSCurvePdfSlice()
     */
    private function buildSCurvePdfPrompt(array $pageWords = []): string
    {
        $words = trim(implode("\n", array_filter(array_map(function ($w) {
            return is_string($w) ? trim($w) : '';
        }, $pageWords))));

        $reference = $words !== ''
            ? ' The machine-read words from these pages (may be partial or empty'
            . ' on scanned pages) follow for alignment — trust the pixels over'
            . ' this text when they differ.'
            : ' No machine-read text is available (scanned pages) — read the pixels.';

        return 'You are reading a contractor S-curve schedule table for a Philippine'
            . ' government construction project, shown as page images (1-2 pages of the'
            . ' same table; later pages may continue the months without repeating the'
            . ' headers).' . $reference
            . ' Month columns may be labeled "Month 1..N", "M1..N", "P1..N", "STAGE 1..N",'
            . ' month names, bare numbers, or ordinals like "1st."; a month may span two'
            . ' adjacent columns whose values must be ADDED once, never double-counted.'
            . ' Return ONLY a JSON object {"months": [{"month_index": 1,'
            . ' "planned_progress_percent": 12.5, "activities": ["Mobilization Works"]}, ...],'
            . ' "monthlyAmounts": [{"month_index": 1, "pesos": 50000}, ...],'
            . ' "summary": [{"month_index": 1, "percent": 12.0}, ...],'
            . ' "hasSummaryRow": true, "contractAmount": 32588531.43,'
            . ' "monthLabels": [{"month_index": 1, "label": "P1"}]}. Rules:'
            . ' "months" MUST be computed from the activity rows (add each month\'s amounts,'
            . ' divide by the contract total — the TOTAL figure the activity costs sum to,'
            . ' NOT the divisor the summary row may use — cumulative 0-100 per month,'
            . ' month_index sequential from 1); "monthlyAmounts" are those per-month peso'
            . ' totals and "contractAmount" is the divisor you used (omit monthlyAmounts only'
            . ' for percent/weight-only tables with no peso figures); "summary" is the'
            . ' author\'s own summary-row values copied as printed (no recomputation — omit'
            . ' the key when no summary row exists); "hasSummaryRow" tells whether one'
            . ' exists. DO NOT copy summary values into "months".'
            . ' "activities" lists each month\'s work names copied EXACTLY as printed'
            . ' (use ONLY words visible on the page — never invent names; omit the key or'
            . ' use [] when a month\'s names are unreadable). "monthLabels" records the'
            . ' exact header text per month as printed (e.g. "P1", "STAGE 1", "JANUARY").'
            . ' Return ONLY the JSON object, no explanations, no markdown fences.'
            . ($words !== '' ? "\n\nMACHINE-READ WORDS:\n" . mb_substr($words, 0, 6000) : '');
    }

    /**
     * Validate + sanitize AI PDF months into the importer shape.
     *
     * Same guarantees as validateSCurveMonths (activity series kept when
     * usable, summary only warns past 1.0, summary adopted ONLY when no
     * activity values exist, recompute check declines fabrication) minus the
     * sheet-truth presence audit (no CSV exists for image slices) and layout
     * keys (PDFs have no columns — F-D learns vocabulary instead). Activity
     * names pass the printed-words check; failures drop to [] for the
     * client's generic fallback (user decision 2026-09-11).
     *
     * Called by: parseSCurvePdfSlice()
     */
    private function validatePdfCurve(mixed $decoded, string $pageText = ''): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        $months = $this->sanitizeCurvePoints($decoded['months'] ?? null);
        $summary = $this->sanitizeSummaryPoints($decoded['summary'] ?? null);
        $amounts = $this->sanitizeAmountPoints($decoded['monthlyAmounts'] ?? null);
        $contract = $this->sanitizeContractAmount($decoded['contractAmount'] ?? null);
        $hasSummaryRow = ($decoded['hasSummaryRow'] ?? null) === true;

        // Attach printed-words-checked names to each kept month (drops never
        // move the curve — names ride, numbers decide).
        $namesByMonth = [];
        foreach ((array) ($decoded['months'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['month_index'])) {
                continue;
            }
            $namesByMonth[(int) $row['month_index']] = $this->sanitizeActivityNames(
                $row['activities'] ?? null,
                $pageText
            );
        }

        // Activity series usable — keep it, summary only warns.
        if (count($months) >= 2) {
            // Recompute check on PRE-sanitizer values (same rationale as Excel).
            $rawMonths = $this->extractRawPoints($decoded['months'] ?? null);
            $amountsCoverAll = $contract !== null && $contract > 0
                && count($amounts) > 0
                && $this->amountsCoverMonths($rawMonths, $amounts);
            if ($amountsCoverAll && !$this->monthsFollowAmounts($rawMonths, $amounts, $contract)) {
                return null;
            }

            $warnings = ['AI-assisted curve — verify before locking the baseline.'];
            // Universal verdict: full homework recomputed cleanly.
            if ($amountsCoverAll) {
                $warnings[] = 'Checked: these months follow from the stated monthly amounts.';
            }

            // Figures-vs-page check (2026-09-12). The Excel path DECLINES an answer
            // whose pesos are printed nowhere; PDFs only warn, because a month that
            // sums several activity rows often has no printed total and throwing the
            // read away would cost more than it saves. Silent when the page carries
            // no numbers at all (scanned slice): missing evidence is not evidence.
            if ($pageText !== '' && count($amounts) > 0) {
                $printed = $this->extractPrintedNumbers($pageText);
                $pool = array_merge($printed['values'], $printed['pairSums']);
                if (count($pool) > 0) {
                    $missing = array_values(array_filter(
                        $this->unmatchedAmountMonths($amounts, $pool),
                        fn ($m) => $m > 0
                    ));
                    if (count($missing) > 0) {
                        $named = array_slice($missing, 0, 3);
                        $warnings[] = 'Heads-up: the monthly figures for Month '
                            . implode(', Month ', $named)
                            . (count($missing) > count($named) ? ' (and others)' : '')
                            . ' could not be found printed on the page, so they may have been'
                            . ' inferred rather than read. Please review before locking.';
                    }
                }
            }

            $byMonth = [];
            foreach ($months as $m) {
                $byMonth[$m['month_index']] = $m['planned_progress_percent'];
            }
            $compared = 0;
            $mirrored = 0;
            foreach ($summary as $s) {
                if (!isset($byMonth[$s['month_index']])) continue;
                $compared++;
                $gap = abs($s['percent'] - $byMonth[$s['month_index']]);
                if ($gap <= 0.01) {
                    $mirrored++;
                } elseif ($gap > 1.0) {
                    $warnings[] = "Heads-up (Month {$s['month_index']}): the file's own summary"
                        . " says {$s['percent']}%, but its activity rows add up to"
                        . " {$byMonth[$s['month_index']]}%. Your baseline below uses the"
                        . ' activity math — no action needed.';
                }
            }
            // Months mirror an attached summary — say so instead of passing silently.
            if ($compared > 0 && $mirrored === $compared) {
                $warnings[] = 'Heads-up: these months match the file\'s own summary exactly —'
                    . ' they could not be independently verified from the activity rows.'
                    . ' Please review before locking.';
            } elseif ($hasSummaryRow && count($summary) === 0) {
                // Claimed summary row, nothing shown — unverified by construction.
                $warnings[] = 'Heads-up: the file has a summary row that could not be compared —'
                    . ' treat these months as unverified. Please review before locking.';
            } elseif (!$amountsCoverAll && count($summary) === 0) {
                // Single-series reply with nothing to check against — never silent.
                $warnings[] = 'Heads-up: only one series could be read — these months could not'
                    . ' be cross-checked. Please review before locking.';
            }

            $outMonths = [];
            foreach ($months as $m) {
                $outMonths[] = [
                    'month_index' => $m['month_index'],
                    'planned_progress_percent' => $m['planned_progress_percent'],
                    'activities' => $namesByMonth[$m['month_index']] ?? [],
                ];
            }

            return [
                'months' => array_values($outMonths),
                'meta' => [
                    'contractAmount' => $contract,
                    'warnings' => $warnings,
                ],
                // F-D vocabulary fuel (controller strips before responding):
                // exact header labels + surviving activity words per month.
                'monthLabels' => $this->sanitizeMonthLabels($decoded['monthLabels'] ?? null),
            ];
        }

        // No usable activity values — adopt the summary as the curve (flagged).
        if (count($summary) >= 2) {
            $adopted = [];
            foreach ($summary as $s) {
                $adopted[] = [
                    'month_index' => $s['month_index'],
                    'planned_progress_percent' => $s['percent'],
                ];
            }
            $adopted = $this->sanitizeCurvePoints($adopted);

            $outAdopted = [];
            foreach ($adopted as $m) {
                $outAdopted[] = [
                    'month_index' => $m['month_index'],
                    'planned_progress_percent' => $m['planned_progress_percent'],
                    'activities' => [],
                ];
            }

            return [
                'months' => array_values($outAdopted),
                'meta' => [
                    'contractAmount' => $contract,
                    'warnings' => [
                        'Heads-up: no usable activity values were found, so the baseline below'
                        . " is built from the file's own summary column. Please verify carefully"
                        . ' before locking.',
                        'AI-assisted curve — verify before locking the baseline.',
                    ],
                ],
                'monthLabels' => $this->sanitizeMonthLabels($decoded['monthLabels'] ?? null),
            ];
        }

        return null;
    }

    /**
     * Keep only activity names built from words printed on the page.
     *
     * Normalizes the page text once (lowercase, punctuation to spaces) and
     * requires every significant word (3+ chars) of a name to appear in it.
     * Hallucinated names drop to nothing — the client falls back to generic
     * labels (user decision 2026-09-11). Cap + dedupe bound the shape.
     *
     * Called by: validatePdfCurve().
     */
    private function sanitizeActivityNames(mixed $names, string $pageText): array
    {
        if (!is_array($names)) {
            return [];
        }

        $haystack = mb_strtolower($pageText);
        $haystack = preg_replace('/[^a-z0-9]+/u', ' ', $haystack);
        $haystack = ' ' . trim(preg_replace('/\s+/', ' ', $haystack)) . ' ';

        $out = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                continue;
            }
            $name = trim(preg_replace('/\s+/', ' ', $name));
            if ($name === '' || mb_strlen($name) < 3 || mb_strlen($name) > 120) {
                continue;
            }
            $needle = mb_strtolower($name);
            $needle = preg_replace('/[^a-z0-9]+/u', ' ', $needle);
            $words = array_values(array_filter(
                explode(' ', $needle),
                fn ($w) => mb_strlen($w) >= 3
            ));
            if (count($words) === 0) {
                continue;
            }
            $printed = true;
            foreach ($words as $w) {
                // Full-word hit always counts; longer words also allow a
                // prefix hit (OCR truncation tolerance) — short words never do
                // ("act" must not ride on "activity").
                $full = mb_strpos($haystack, ' ' . $w . ' ') !== false;
                $prefix = mb_strlen($w) >= 5 && mb_strpos($haystack, ' ' . $w) !== false;
                if (!$full && !$prefix) {
                    $printed = false;
                    break;
                }
            }
            if (!$printed) {
                continue;
            }
            if (!in_array($name, $out, true) && count($out) < 6) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Sanitize exact month-header labels ({month_index, label}) for F-D fuel.
     *
     * Shape only (month 1..60, label 1..40 chars) — trust (approval) happens
     * in the review desk, never here.
     *
     * Called by: validatePdfCurve().
     */
    private function sanitizeMonthLabels(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['month_index'], $row['label'])) {
                continue;
            }
            $idx = (int) $row['month_index'];
            $label = is_string($row['label']) ? trim($row['label']) : '';
            if ($idx < 1 || $idx > 60 || isset($out[$idx])) {
                continue;
            }
            if ($label === '' || mb_strlen($label) > 40) {
                continue;
            }
            $out[$idx] = $label;
        }

        return $out;
    }

    /**
     * Build the strict-JSON S-curve extraction prompt around workbook text.
     *
     * Asks for strict homework so the server can verify instead of trusting:
     * activity-derived months + the monthly peso amounts and divisor behind
     * them (recomputed server-side; mismatch = declined fabrication) + the
     * author's own summary-row values as printed + whether a summary row
     * exists at all. Copying the summary row into months is explicitly
     * forbidden — it must arrive separately under "summary".
     *
     * Called by: parseExcelSlice()
     */
    private function buildSCurvePrompt(string $csv, bool $hasWorkbookFile = false): string
    {
        $source = $hasWorkbookFile
            ? 'An attached native workbook copy accompanies this text (authoritative grid — trust'
            . ' its columns, merged spans, and values over the text when they differ); the tagged rows'
            . ' below guide its reading.'
            : 'Only the serialized text below is available (workbook too large to attach).';
        return 'You are reading a contractor S-curve schedule table for a Philippine'
            . ' government construction project. ' . $source
            . ' The text is the workbook serialized as tagged'
            . ' rows: a COLUMNS legend (A,B,C... letter per position) then one line per row as'
            . ' "R<n> [ROLE]: values". Roles: META = title block (ignore for math); HEADER = column'
            . ' labels (month columns may be "Month 1..N", "M1..N", "P1..N", month names, bare numbers,'
            . ' ordinals like "1st.", or day-group headers; a month may span two adjacent columns whose'
            . ' values must be ADDED once, never double-counted); DATA = activity rows (compute months'
            . ' ONLY from these); SUMMARY = the author\'s own total rows (labels like CUMULATIVE %,'
            . ' % ACCOMPLISHED, COST, Cash Flow, Project Duration — reference only).'
            . ' Return ONLY a JSON object {"months": [{"month_index": 1,'
            . ' "planned_progress_percent": 12.5, "activities": ["Mobilization Works"]}, ...],'
            . ' "monthlyAmounts": [{"month_index": 1,'
            . ' "pesos": 50000}, ...], "summary": [{"month_index": 1, "percent": 12.0},'
            . ' ...], "hasSummaryRow": true, "contractAmount": 32588531.43}. Rules:'
            . ' "months" MUST be computed from the DATA rows (add each month\'s amounts using the'
            . ' HEADER month columns, divide by the contract total — the TOTAL figure the activity'
            . ' costs sum to, NOT the divisor the SUMMARY row may use — cumulative 0-100 per month,'
            . ' month_index sequential from 1); "monthlyAmounts" are those per-month peso totals and'
            . ' "contractAmount" is the divisor you used (omit monthlyAmounts only for'
            . ' percent/weight-only files with no peso figures); "summary" is the SUMMARY lines\' values'
            . ' copied as printed (no recomputation — omit the key when no SUMMARY row exists);'
            . ' "hasSummaryRow" tells whether one exists. DO NOT copy SUMMARY values into "months" —'
            . ' the two series must be reported independently even when they agree.'
            . ' "activities" lists that month\'s work names from the DATA rows\' description column,'
            . ' copied EXACTLY as printed — only rows that actually carry a value in that month,'
            . ' never invented, never a SUMMARY or TOTAL label; omit the key when you cannot read them.'
            . ' For layout learning, ALSO report where you read the table (use the COLUMNS letters'
            . ' and R-numbers shown): "headerRow" is the R-number holding the column labels (single'
            . ' header row only — omit when labels span two rows). Report it from what you SEE:'
            . ' the labels often sit on a row tagged DATA because the tagger missed the table,'
            . ' and that row still counts as the header row.'
            . ' "monthColumns" lists each month\'s'
            . ' column letter(s) (1-2 letters, e.g. single P1 as ["F"], paired Month 1 as ["I","J"]);'
            . ' "descCol" is the work-names column letter, "contractCol" the peso-total letter'
            . ' (plus "weightCol"/"codeCol" when present). Omit layout keys you cannot determine.'
            . ' Example with a'
            . ' disagreeing file: {"months": [{"month_index": 1, "planned_progress_percent": 5,'
            . ' "activities": ["Mobilization Works"]},'
            . ' {"month_index": 2, "planned_progress_percent": 10,'
            . ' "activities": ["Mobilization Works", "Earthworks and Excavation"]}], "monthlyAmounts":'
            . ' [{"month_index": 1, "pesos": 50000}, {"month_index": 2, "pesos": 50000}],'
            . ' "summary": [{"month_index": 1, "percent": 5}, {"month_index": 2, "percent": 9}],'
            . ' "hasSummaryRow": true, "contractAmount": 1000000,'
            . ' "headerRow": 2, "monthColumns": [{"month_index": 1, "columns": ["F"]},'
            . ' {"month_index": 2, "columns": ["G"]}], "descCol": "B", "contractCol": "E"}.'
            . ' Return ONLY the JSON object, no explanations, no markdown fences.'
            . "\n\nSHEET:\n" . mb_substr($csv, 0, 12000);
    }

    /**
     * Validate + sanitize AI months into the importer shape.
     *
     * Keeps the activity-derived series whenever usable (≥2 months); summary
     * points become reference warnings past a 1.0 gap (same threshold/wording
     * family as the rule importer). The summary is adopted as the curve ONLY
     * when no usable activity values exist (mirrors the rules' summary
     * fallback, flagged never silent).
     *
     * Verification without layout knowledge (strict homework):
     * - monthlyAmounts + contractAmount let the server RECOMPUTE the curve;
     *   months that don't follow from stated amounts are declined as
     *   fabrication. Absent/partial amounts skip the check silently
     *   (percent/weight-only files legitimately have no peso figures).
     * - months mirroring an attached summary are labeled as unverified copies
     *   instead of passing silently; a claimed-but-unshown summary row earns
     *   an explicit could-not-verify note.
     * - slice-truth presence audit ($sheetNumbers): every CLAIMED monthly peso
     *   must appear among the sheet's real numbers (or an adjacent-pair sum
     *   for paired layouts, rounding-tolerant) — fabricated-consistent
     *   homework (e.g. the phantom 150,000 behind a copied summary) is
     *   declined outright. Pollution (budget costs) can only false-pass,
     *   never false-decline — the safe direction.
     *
     * Activity names (2026-09-12): reported per month and kept only when their
     * words actually appear in the serialized sheet — the SAME discipline the PDF
     * path applies to page words. Names ride, numbers decide: a dropped name never
     * moves the curve, it just leaves the client on its generic label. Before
     * this, the Excel prompt never asked for names at all, so AI-assisted Excel
     * imports produced bare percentages while PDF ones carried real names.
     *
     * Called by: parseExcelSlice()
     */
    private function validateSCurveMonths(
        mixed $decoded,
        array $sheetNumbers = ['values' => [], 'pairSums' => []],
        string $sheetText = ''
    ): ?array {
        if (!is_array($decoded)) {
            return null;
        }

        $months = $this->sanitizeCurvePoints($decoded['months'] ?? null);
        $summary = $this->sanitizeSummaryPoints($decoded['summary'] ?? null);
        $amounts = $this->sanitizeAmountPoints($decoded['monthlyAmounts'] ?? null);
        $contract = $this->sanitizeContractAmount($decoded['contractAmount'] ?? null);
        $hasSummaryRow = ($decoded['hasSummaryRow'] ?? null) === true;

        // Sheet-printed names per month (empty when the slice text is unavailable,
        // which reproduces the previous nameless behavior exactly).
        $namesByMonth = [];
        if ($sheetText !== '') {
            foreach ((array) ($decoded['months'] ?? []) as $row) {
                if (!is_array($row) || !isset($row['month_index'])) {
                    continue;
                }
                $namesByMonth[(int) $row['month_index']] = $this->sanitizeActivityNames(
                    $row['activities'] ?? null,
                    $sheetText
                );
            }
        }

        // Activity series usable — keep it, summary only warns.
        if (count($months) >= 2) {
            // Recompute check on PRE-sanitizer values (clamp/force-last-100 are
            // ours, not the model's — checking sanitized months would punish
            // honest output for our own adjustments).
            $rawMonths = $this->extractRawPoints($decoded['months'] ?? null);
            $amountsCoverAll = $contract !== null && $contract > 0
                && count($amounts) > 0
                && $this->amountsCoverMonths($rawMonths, $amounts);
            if ($amountsCoverAll && !$this->monthsFollowAmounts($rawMonths, $amounts, $contract)) {
                // The numbers are refused, but the COLUMNS this answer named may
                // still be right — hand them out so the caller can read the sheet
                // itself rather than giving up (column trust ≠ month trust).
                $this->lastRejectedLayout = $this->sanitizeLayoutPoints($decoded);

                return null;
            }

            // Slice-truth presence audit: don't claim sheet numbers you can't
            // point to. Each claimed monthly peso must appear among the sheet's
            // real numbers (or an adjacent-pair sum for paired layouts).
            if (count($amounts) > 0 && !$this->amountsPresentInSheet($amounts, $sheetNumbers)) {
                $this->lastRejectedLayout = $this->sanitizeLayoutPoints($decoded);

                return null;
            }

            $warnings = ['AI-assisted curve — verify before locking the baseline.'];
            // Universal verdict (2026-09-09): full homework recomputed cleanly.
            if ($amountsCoverAll) {
                $warnings[] = 'Checked: these months follow from the stated monthly amounts.';
            }
            $byMonth = [];
            foreach ($months as $m) {
                $byMonth[$m['month_index']] = $m['planned_progress_percent'];
            }
            $compared = 0;
            $mirrored = 0;
            foreach ($summary as $s) {
                if (!isset($byMonth[$s['month_index']])) continue;
                $compared++;
                $gap = abs($s['percent'] - $byMonth[$s['month_index']]);
                if ($gap <= 0.01) {
                    $mirrored++;
                } elseif ($gap > 1.0) {
                    $warnings[] = "Heads-up (Month {$s['month_index']}): the file's own summary"
                        . " says {$s['percent']}%, but its activity rows add up to"
                        . " {$byMonth[$s['month_index']]}%. Your baseline below uses the"
                        . ' activity math — no action needed.';
                }
            }
            // Months mirror an attached summary — say so instead of passing silently.
            if ($compared > 0 && $mirrored === $compared) {
                $warnings[] = 'Heads-up: these months match the file\'s own summary exactly —'
                    . ' they could not be independently verified from the activity rows.'
                    . ' Please review before locking.';
            } elseif ($hasSummaryRow && count($summary) === 0) {
                // Claimed summary row, nothing shown — unverified by construction.
                $warnings[] = 'Heads-up: the file has a summary row that could not be compared —'
                    . ' treat these months as unverified. Please review before locking.';
            } elseif (!$amountsCoverAll && count($summary) === 0) {
                // Single-series reply with nothing to check against — the exact
                // shape that passed the 45-series silently. Never silent again.
                $warnings[] = 'Heads-up: only one series could be read — these months could not'
                    . ' be cross-checked. Please review before locking.';
            }

            // Names ride along with each kept month (drops never move the curve).
            $outMonths = [];
            foreach ($months as $m) {
                $outMonths[] = [
                    'month_index' => $m['month_index'],
                    'planned_progress_percent' => $m['planned_progress_percent'],
                    'activities' => $namesByMonth[$m['month_index']] ?? [],
                ];
            }

            return [
                'months' => array_values($outMonths),
                'meta' => [
                    'contractAmount' => $contract,
                    'warnings' => $warnings,
                ],
                // B-1 learn-from-AI passthrough (controller strips before responding):
                // activity-backed months plus the layout keys for derivation. The
                // mirrored flag rides along so derivation skips copies.
                'layout' => array_merge(
                    $this->sanitizeLayoutPoints($decoded),
                    [
                        'activityBased' => true,
                        'mirrored' => $compared > 0 && $mirrored === $compared,
                    ]
                ),
            ];
        }

        // No usable activity values — adopt the summary as the curve (flagged).
        if (count($summary) >= 2) {
            $adopted = [];
            foreach ($summary as $s) {
                $adopted[] = [
                    'month_index' => $s['month_index'],
                    'planned_progress_percent' => $s['percent'],
                ];
            }
            $adopted = $this->sanitizeCurvePoints($adopted);

            return [
                'months' => array_values($adopted),
                'meta' => [
                    'contractAmount' => $contract,
                    'warnings' => [
                        'Heads-up: no usable activity values were found, so the baseline below'
                        . " is built from the file's own summary column. Please verify carefully"
                        . ' before locking.',
                        'AI-assisted curve — verify before locking the baseline.',
                    ],
                ],
                // Adopted curves carry no learnable layout (months ARE the summary).
                'layout' => array_merge(
                    $this->sanitizeLayoutPoints($decoded),
                    ['activityBased' => false, 'mirrored' => false]
                ),
            ];
        }

        return null;
    }

    /**
     * B-1 — sanitize AI-reported layout keys (shape only, no semantics).
     *
     * Returns {headerRow: ?int, monthColumns: {m: [letters]}, descCol/contractCol/
     * weightCol/codeCol: ?letter}. Semantics (do these letters hold numbers? does
     * the header row exist?) are proven later against real bytes by derivation —
     * never trusted here.
     *
     * Called by: validateSCurveMonths().
     */
    private function sanitizeLayoutPoints(mixed $decoded): array
    {
        $out = [
            'headerRow' => null,
            'monthColumns' => [],
            'descCol' => null,
            'contractCol' => null,
            'weightCol' => null,
            'codeCol' => null,
        ];
        if (!is_array($decoded)) {
            return $out;
        }

        $cleanLetter = function ($v): ?string {
            if (!is_string($v)) {
                return null;
            }
            $v = strtoupper(trim($v));
            if (!preg_match('/^[A-Z]{1,3}$/', $v)) {
                return null;
            }

            return $v;
        };

        $hr = $decoded['headerRow'] ?? null;
        if (is_numeric($hr) && (int) $hr >= 1 && (int) $hr <= 200) {
            $out['headerRow'] = (int) $hr;
        }

        $mc = $decoded['monthColumns'] ?? null;
        if (is_array($mc)) {
            foreach ($mc as $entry) {
                if (!is_array($entry) || !isset($entry['month_index'], $entry['columns'])) {
                    continue;
                }
                $mi = (int) $entry['month_index'];
                if ($mi < 1 || $mi > 60 || isset($out['monthColumns'][$mi])) {
                    continue;
                }
                $cols = [];
                foreach ((array) $entry['columns'] as $letter) {
                    $clean = $cleanLetter($letter);
                    if ($clean !== null && count($cols) < 4 && !in_array($clean, $cols, true)) {
                        $cols[] = $clean;
                    }
                }
                if (count($cols) > 0) {
                    $out['monthColumns'][$mi] = $cols;
                }
            }
        }

        foreach (['descCol', 'contractCol', 'weightCol', 'codeCol'] as $key) {
            $out[$key] = $cleanLetter($decoded[$key] ?? null);
        }

        return $out;
    }

    /**
     * Sanitize month points into a cumulative curve (monotonic clamp +
     * force-last-100 — the same guarantees BaselineScheduleController demands).
     * Unknown keys dropped; requires no minimum (callers decide).
     *
     * Called by: validateSCurveMonths() for activity months and summary adoption.
     */
    private function sanitizeCurvePoints(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $months = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['month_index'], $row['planned_progress_percent'])) {
                continue;
            }
            $idx = (int) $row['month_index'];
            $pct = is_numeric($row['planned_progress_percent']) ? (float) $row['planned_progress_percent'] : null;
            if ($idx < 1 || $idx > 60 || $pct === null || !is_finite($pct) || $pct < 0) {
                continue;
            }
            $months[] = ['month_index' => $idx, 'planned_progress_percent' => min($pct, 100.0)];
        }

        usort($months, fn ($a, $b) => $a['month_index'] <=> $b['month_index']);

        // Resequence + sanitize like the rule importer (monotonic clamp, force-last-100).
        $prev = 0.0;
        foreach ($months as $i => $m) {
            $v = max($m['planned_progress_percent'], $prev);
            $v = min($v, 100.0);
            $months[$i]['planned_progress_percent'] = round($v, 2);
            $prev = $v;
        }
        if (count($months) > 0) {
            $months[count($months) - 1]['planned_progress_percent'] = 100.0;
        }

        return $months;
    }

    /**
     * Sanitize author-summary points into reference data (sorted, rounded —
     * never forced: reference must stay exactly as printed for honest gaps).
     *
     * Called by: validateSCurveMonths().
     */
    private function sanitizeSummaryPoints(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $points = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['month_index'], $row['percent'])) {
                continue;
            }
            $idx = (int) $row['month_index'];
            $pct = is_numeric($row['percent']) ? (float) $row['percent'] : null;
            if ($idx < 1 || $idx > 60 || $pct === null || !is_finite($pct) || $pct < 0 || $pct > 100) {
                continue;
            }
            if (isset($seen[$idx])) continue;
            $seen[$idx] = true;
            $points[] = ['month_index' => $idx, 'percent' => round($pct, 2)];
        }

        usort($points, fn ($a, $b) => $a['month_index'] <=> $b['month_index']);

        return $points;
    }

    /**
     * Sanitize monthly peso amounts into homework data (sorted, deduped —
     * no forcing: amounts are evidence, never the curve itself).
     *
     * Called by: validateSCurveMonths().
     */
    private function sanitizeAmountPoints(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $points = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['month_index'], $row['pesos'])) {
                continue;
            }
            $idx = (int) $row['month_index'];
            $pesos = is_numeric($row['pesos']) ? (float) $row['pesos'] : null;
            if ($idx < 1 || $idx > 60 || $pesos === null || !is_finite($pesos) || $pesos < 0) {
                continue;
            }
            if (isset($seen[$idx])) continue;
            $seen[$idx] = true;
            $points[] = ['month_index' => $idx, 'pesos' => round($pesos, 2)];
        }

        usort($points, fn ($a, $b) => $a['month_index'] <=> $b['month_index']);

        return $points;
    }

    /**
     * Bounds-checked raw month points (sorted, NOT clamped/forced) for the
     * recompute check — verification must see the model's numbers, not ours.
     *
     * Called by: validateSCurveMonths().
     */
    private function extractRawPoints(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $points = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['month_index'], $row['planned_progress_percent'])) {
                continue;
            }
            $idx = (int) $row['month_index'];
            $pct = is_numeric($row['planned_progress_percent']) ? (float) $row['planned_progress_percent'] : null;
            if ($idx < 1 || $idx > 60 || $pct === null || !is_finite($pct) || $pct < 0) {
                continue;
            }
            $points[] = ['month_index' => $idx, 'planned_progress_percent' => min($pct, 100.0)];
        }

        usort($points, fn ($a, $b) => $a['month_index'] <=> $b['month_index']);

        return $points;
    }

    /**
     * Do the stated amounts cover every stated month? Full coverage earns the
     * recompute check (and the verified confirmation); anything less skips it
     * silently — percent/weight-only files legitimately report no peso figures.
     *
     * Called by: validateSCurveMonths().
     */
    private function amountsCoverMonths(array $months, array $amounts): bool
    {
        if (count($months) === 0 || count($amounts) === 0) {
            return false;
        }

        $covered = [];
        foreach ($amounts as $a) {
            $covered[$a['month_index']] = true;
        }
        foreach ($months as $m) {
            if (!isset($covered[$m['month_index']])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recompute check: stated months must follow from stated amounts.
     *
     * Pure arithmetic on the AI's own reported numbers (cumulative prior +
     * pesos/total×100 per month) — needs no layout knowledge. Months that
     * don't follow are declined as fabrication. Requires amounts covering
     * every stated month; partial/absent amounts skip the check silently
     * (percent/weight-only files legitimately report no peso figures).
     *
     * Called by: validateSCurveMonths().
     */
    private function monthsFollowAmounts(array $months, array $amounts, float $contract): bool
    {
        $byAmount = [];
        foreach ($amounts as $a) {
            $byAmount[$a['month_index']] = $a['pesos'];
        }

        $running = 0.0;
        foreach ($months as $m) {
            if (!isset($byAmount[$m['month_index']])) {
                return true;
            }
            $running += ($byAmount[$m['month_index']] / $contract) * 100;
            if (abs($running - $m['planned_progress_percent']) > 1.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract sheet numbers from the serialized slice for the presence audit.
     *
     * Parses the R-lines (skipping the COLUMNS legend), collecting every
     * numeric cell plus every adjacent-pair sum (paired layouts store halves).
     * Labels/ordinals/month names never parse as numeric, so text can't pollute.
     *
     * Called by: parseExcelSlice()
     */
    private function extractSheetNumbers(string $csv): array
    {
        $values = [];
        $pairSums = [];

        foreach (explode("\n", $csv) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'COLUMNS:')) {
                continue;
            }
            // Strip the "Rn [ROLE]:" prefix; tolerate the legacy bare "Rn:" shape.
            $text = preg_replace('/^R\d+\s*(\[[A-Z]+\]\s*)?:\s*/', '', $line);
            $cells = str_getcsv((string) $text);
            $nums = [];
            foreach ($cells as $cell) {
                $t = str_replace([',', ' ', '%'], '', trim((string) $cell));
                if ($t !== '' && is_numeric($t) && is_finite((float) $t)) {
                    $nums[] = (float) $t;
                } else {
                    $nums[] = null;
                }
            }
            foreach ($nums as $i => $v) {
                if ($v === null) continue;
                $values[] = $v;
                if (isset($nums[$i + 1]) && $nums[$i + 1] !== null) {
                    $pairSums[] = $v + $nums[$i + 1];
                }
            }
        }

        return ['values' => $values, 'pairSums' => $pairSums];
    }

    /**
     * Slice-truth presence audit: every claimed monthly peso must appear among
     * the sheet's real numbers (or an adjacent-pair sum), rounding-tolerant
     * (±1.0 absolute). Fabricated-consistent homework fails here even when its
     * internal arithmetic is perfect.
     *
     * Called by: validateSCurveMonths().
     */
    private function amountsPresentInSheet(array $amounts, array $sheetNumbers): bool
    {
        $pool = array_merge(
            (array) ($sheetNumbers['values'] ?? []),
            (array) ($sheetNumbers['pairSums'] ?? [])
        );
        if (count($pool) === 0) {
            return false;
        }

        return count($this->unmatchedAmountMonths($amounts, $pool)) === 0;
    }

    /**
     * Which claimed months' pesos appear nowhere in a pool of real numbers
     * (±1.0, rounding-tolerant).
     *
     * The matching rule lives here so the Excel audit (which DECLINES on any
     * miss) and the PDF check (which only WARNS — a month summing several
     * activity rows often has no printed total, and a false decline would cost
     * the user the whole answer) can never drift apart.
     *
     * Called by: amountsPresentInSheet(), validatePdfCurve().
     *
     * @return array<int, int>  month indices with no match
     */
    private function unmatchedAmountMonths(array $amounts, array $pool): array
    {
        $missing = [];
        foreach ($amounts as $a) {
            $found = false;
            foreach ($pool as $v) {
                if (abs($v - $a['pesos']) <= 1.0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = (int) ($a['month_index'] ?? 0);
            }
        }

        return $missing;
    }

    /**
     * Numbers printed on a PDF page, as the page-text sibling of
     * extractSheetNumbers() (which is CSV-shaped and cannot be reused).
     *
     * Splits on whitespace, strips peso signs, commas and percent marks, and
     * pairs consecutive numerics the way the Excel audit pairs adjacent cells.
     * Page words arrive joined per page, so "consecutive" can straddle rows —
     * that only ever ADDS matches, which for a warning errs toward silence
     * rather than crying wolf.
     *
     * Called by: validatePdfCurve().
     */
    private function extractPrintedNumbers(string $text): array
    {
        $values = [];
        $pairSums = [];
        $previous = null;

        foreach (preg_split('/\s+/', $text) ?: [] as $token) {
            $clean = str_replace(['₱', 'P', ',', '%'], '', trim((string) $token));
            if ($clean === '' || !is_numeric($clean) || !is_finite((float) $clean)) {
                $previous = null;

                continue;
            }
            $value = (float) $clean;
            $values[] = $value;
            if ($previous !== null) {
                $pairSums[] = $previous + $value;
            }
            $previous = $value;
        }

        return ['values' => $values, 'pairSums' => $pairSums];
    }

    /**
     * Sanitize a contract-amount figure (last-separator-wins decimal handles
     * both US figures and OCR period-thousands).
     *
     * Called by: validateSCurveMonths().
     */
    private function sanitizeContractAmount(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $contract = null;
        $clean = preg_replace('/[^0-9.,]/', '', (string) $value);
        if ($clean !== '' && preg_match('/([.,])(\d{2})$/', $clean, $m)) {
            $int = str_replace([',', '.'], '', substr($clean, 0, -3));
            $clean = $int . '.' . $m[2];
        } else {
            $clean = str_replace([',', '.'], '', (string) $clean);
        }
        if (is_numeric($clean) && (float) $clean > 0) {
            $contract = round((float) $clean, 2);
        }

        return $contract;
    }

    /**
     * Send a text prompt to Gemini and return the raw text.
     *
     * Same shape as callGeminiWithImages() without image parts. Schema-enforced
     * (2026-09-09): the Excel homework format (months + hasSummaryRow required)
     * is declared at the protocol level so minimal months-only replies fail
     * closed instead of passing silently. Temperature 0 (2026-09-10):
     * deterministic extraction — the 45-then-55 run variance was dice, not
     * reasoning, and comparisons/tests need reproducibility. Costs nothing
     * (same tokens). This sender serves ONLY the Excel slice path — the 11-field
     * project slice keeps its own sender below.
     *
     * FALLBACK LADDER (E4b, 2026-09-13): this path now climbs the SAME parser
     * ladder the PDF path uses. It never did before, which meant an Excel import
     * died on a per-model quota that a PDF import survived — one feature failing
     * differently depending on which file the contractor happened to send.
     * Lite is still unreachable here: the ladder is a VERSION list and Lite is
     * excluded from it by configuration and by documentation. The E3 verdict this
     * path was protected by was about Lite COPYING author summaries, not about
     * older Flash versions, which compute.
     * Worth knowing: this path is the best-guarded of the three — the controller
     * re-reads the sheet with the AI's own reported columns and lets the FILE win
     * (applyDerivedLayout), the presence audit DECLINES rather than warns, and the
     * response schema is enforced at the protocol level.
     *
     * Called by: parseExcelSlice()
     * Uses: config/services.php (gemini parser key/model + parser fallback list)
     */
    private function callGeminiText(string $prompt, ?array $filePart = null): string
    {
        // Reset here as well as in the images sender: without it a rescue recorded
        // during an earlier PDF call on the same instance would be reported as the
        // provenance of THIS answer.
        $this->lastFallbackModel = null;
        $this->startTimeBudget();
        $apiKey = $this->parserKey();
        $model = $this->parserModel();

        if (empty($apiKey)) {
            return '';
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $parts = [['text' => $prompt]];
        if (is_array($filePart) && !empty($filePart['data'])) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $filePart['mimeType'] ?? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'data' => $filePart['data'],
                ],
            ];
        }

        $payload = [
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.0,
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->scurveResponseSchema(),
            ],
        ];

        try {
            $result = $this->postGemini($url, $payload, $model, 'text');
        } catch (GeminiTransportException $e) {
            // A rung that cannot honour the responseSchema answers 400, which is not
            // ladder-eligible — the walk stops there and the primary's voice speaks,
            // rather than trying every remaining version against the same refusal.
            return $this->climbFallbackLadder($payload, $apiKey, $this->parserFallbackModels(), $e, $model, 'text');
        }

        $data = $result['payload'];

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    /**
     * Response schema for the Excel homework format.
     *
     * Requires months + hasSummaryRow (a reply without the verification
     * scaffolding is a protocol miss, not a curve); amounts/summary stay
     * optional since weight-only and summary-less files are legitimate. Layout
     * keys (B-1 learn-from-AI) stay optional — older or terse replies without
     * them still validate; derivation simply skips.
     *
     * WATCH THIS WHEN THE PROMPT GROWS (lesson, 2026-09-12): this schema is the
     * contract, not the prompt. Asking for a key the schema does not declare is
     * silently futile — activity names were requested for a day, and dropped every
     * time, before anyone read this method. Any new key the prompt asks for must
     * be added here too.
     *
     * Called by: callGeminiText()
     */
    private function scurveResponseSchema(): array
    {
        $point = function (string $valueKey, string $valueType): array {
            return [
                'type' => 'OBJECT',
                'properties' => [
                    'month_index' => ['type' => 'INTEGER'],
                    $valueKey => ['type' => $valueType],
                ],
                'required' => ['month_index', $valueKey],
            ];
        };

        // Months carry an extra, OPTIONAL key the other two series must not have.
        // Structured output drops anything undeclared, so before this the Excel
        // reply could never contain activity names however the prompt asked —
        // the PDF sender ships no schema at all, which is why it always could.
        $monthPoint = $point('planned_progress_percent', 'NUMBER');
        $monthPoint['properties']['activities'] = [
            'type' => 'ARRAY',
            'items' => ['type' => 'STRING'],
        ];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'months' => ['type' => 'ARRAY', 'items' => $monthPoint],
                'monthlyAmounts' => ['type' => 'ARRAY', 'items' => $point('pesos', 'NUMBER')],
                'summary' => ['type' => 'ARRAY', 'items' => $point('percent', 'NUMBER')],
                'hasSummaryRow' => ['type' => 'BOOLEAN'],
                'contractAmount' => ['type' => 'NUMBER'],
                'headerRow' => ['type' => 'INTEGER'],
                'monthColumns' => ['type' => 'ARRAY', 'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'month_index' => ['type' => 'INTEGER'],
                        'columns' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                    ],
                    'required' => ['month_index', 'columns'],
                ]],
                'descCol' => ['type' => 'STRING'],
                'contractCol' => ['type' => 'STRING'],
                'weightCol' => ['type' => 'STRING'],
                'codeCol' => ['type' => 'STRING'],
            ],
            'required' => ['months', 'hasSummaryRow'],
        ];
    }
    /**
     * Build the strict-JSON extraction prompt.
     *
     * Demands ONLY JSON with the exact 11 keys so the response merges directly
     * into the preview flow without shape translation.
     *
     * Called by: parseProjectSlice()
     */
    private function buildPrompt(): string
    {
        $keys = implode(', ', self::FIELDS);

        return 'You are reading Philippine construction project document pages (bidding papers,'
            . ' contracts, accomplishment reports, estimates, statements — any layout). '
            . 'Extract the project fields and return ONLY a JSON object with exactly these keys: '
            . $keys . '. Rules: dates as YYYY-MM-DD; amounts as plain numbers with two decimals '
            . 'and no commas or currency symbols (e.g. 4834297.59); duration as integer days '
            . '(e.g. 185); use null for any field you cannot find. Return ONLY the JSON object, '
            . 'no explanations, no markdown fences.';
    }

    /**
     * Send prompt + image slices to Gemini and return the raw text.
     *
     * Same shape as AIRecommendationService::callGemini(), plus inline_data image
     * parts (base64 + mimeType) after the text part.
     *
     * FALLBACK IS A LIST, NOT A FLAG (E4, 2026-09-13). $fallbackModels names the
     * models to try, in order, when the parser-model call fails ELIGIBLE
     * (404/429/502/503/504 + throws — never 400/401/403, where the key itself is
     * broken and every rung would fail identically). Each rung gets ONE attempt;
     * the parser model has already spent the retry budget. The first rung to
     * answer wins and its answer flows through the same validation as any other.
     *
     * Callers choose their own policy, which is the point of the list:
     *   - parseProjectSlice()    -> [backupModel()], the Lite rescue as before.
     *   - parseSCurvePdfSlice()  -> parserFallbackModels(), a VERSION ladder that
     *     never includes Lite. Empty unless AI_PARSER_FALLBACK_MODELS is set, so
     *     an unconfigured install keeps Flash-or-nothing exactly as before.
     * Passing [] disables fallback entirely.
     *
     * THE TRANSPORT-FAILURE DISCIPLINE IS PRESERVED (this is the lesson the
     * 2026-09-11 Lite revert was really about): when every rung fails, the
     * ORIGINAL exception is rethrown, so a quota or overload still reaches the
     * user in its own words instead of being masked as "the AI found nothing".
     *
     * lastFallbackModel records WHICH rung answered, for the provenance note.
     *
     * Called by: parseProjectSlice(), parseSCurvePdfSlice()
     * Uses: config/services.php (gemini parser key/model, parser fallback list,
     *       and the main model when a caller asks for the Lite rescue)
     */
    private function callGeminiWithImages(string $prompt, array $images, array $fallbackModels = []): string
    {
        $this->lastFallbackModel = null;
        $this->startTimeBudget();
        $apiKey = $this->parserKey();
        $model = $this->parserModel();

        if (empty($apiKey)) {
            return '';
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $parts = [['text' => $prompt]];

        foreach ($images as $img) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $img['mimeType'] ?? 'image/png',
                    'data' => $img['data'],
                ],
            ];
        }

        $payload = [
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
        ];

        try {
            $result = $this->postGemini($url, $payload, $model, 'images');
        } catch (GeminiTransportException $e) {
            return $this->climbFallbackLadder($payload, $apiKey, $fallbackModels, $e, $model, 'images');
        }

        $data = $result['payload'];

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    /**
     * E4b (2026-09-13): walk the fallback ladder after a primary-model failure.
     *
     * Extracted from callGeminiWithImages so BOTH senders share one copy. The
     * Excel S-curve rides callGeminiText and had no fallback at all, which meant
     * an Excel import still died on a quota a PDF import now survives — the same
     * feature failing differently depending on the file the contractor sent. One
     * copy is also what stops the transport-failure discipline drifting between
     * the two paths, the same reason finalizeHeaderBands is shared by the strict
     * and relaxed PDF detectors.
     *
     * The rules, all load-bearing:
     *  - a rung is tried only while the failure in hand is one another MODEL could
     *    survive (meters are per model, so a per-day 429 is exactly that case;
     *    400/401/403 never are — the key is broken and every rung fails alike);
     *  - ONE attempt per rung: the primary already spent the retry budget, and a
     *    ladder that retried every rung would keep the user waiting minutes;
     *  - rungs go through postGemini, not a bare Http::post, so each keeps its
     *    status log, quota-meter read and mapped voice (the reverted Lite rescue
     *    had none of the three);
     *  - a rung's own status decides whether the NEXT rung is worth trying, but
     *    the MESSAGE the user finally sees stays the primary's;
     *  - when the ladder is exhausted, the ORIGINAL exception is rethrown. Masking
     *    a quota as "the AI found nothing" is the actual lesson of the 2026-09-11
     *    Lite revert, and it is the one thing a ladder could easily undo.
     *
     * Never returns null: it either returns a rung's answer or throws.
     *
     * Called by: callGeminiWithImages(), callGeminiText().
     */
    private function climbFallbackLadder(
        array $payload,
        string $apiKey,
        array $fallbackModels,
        GeminiTransportException $primaryError,
        string $primaryModel,
        string $where
    ): string {
        $error = $primaryError;
        // E6 — is the primary gone for the DAY, or merely busy? The distinction
        // decides how hard to try the rung behind it, and the code already knows
        // how to tell them apart.
        $primaryIsSpent = $this->primaryIsSpentForToday($primaryError);
        $promotionAvailable = $primaryIsSpent;

        foreach ($fallbackModels as $rung) {
            if (!$this->isFallbackEligible($error->status)) {
                break;
            }
            // E6 — PROMOTE THE FIRST RUNG when the primary will not be back today
            // (per-day quota, or a dead model id). It is no longer a detour on the
            // way back to the primary; it IS the model this install is using for
            // the rest of the day, so it earns the primary's attempt budget.
            // Only the FIRST rung: the ones behind it are still detours.
            // Affordable exactly when it applies — a per-day 429 fails fast with no
            // retry, so most of the time budget is still unspent — and every
            // attempt still passes canAffordAttempt(), so promotion can never
            // reintroduce the overrun E5 was built to stop.
            // A 503 promotes nothing: the primary may recover, and the measured
            // record says retrying a model that just refused is the worst use of
            // the budget (0 successes in 8 attempts across four live runs).
            $attempts = 1;
            if ($promotionAvailable) {
                $attempts = 2;
                $promotionAvailable = false;
                try {
                    Log::info('E6 parser rung promoted — primary is out for the day', [
                        'where' => $where,
                        'from' => $primaryModel,
                        'to' => $rung,
                        'reason' => $primaryError->status === 404 ? 'dead-model' : 'per-day-quota',
                    ]);
                } catch (\Throwable $ignored) {
                }
            }
            // E5 — stop the walk while there is still time to ANSWER. Starting a
            // rung that cannot finish inside the budget is how the request reached
            // PHP's uncatchable fatal and the user was told the file was
            // unreadable. Said out loud so a short ladder is diagnosable rather
            // than looking like a chain that was never configured.
            if (!$this->canAffordAttempt($where . '-fallback')) {
                try {
                    Log::info('E5 parser ladder stopped — time budget spent', [
                        'where' => $where,
                        'from' => $primaryModel,
                        'skipped' => $rung,
                    ]);
                } catch (\Throwable $ignored) {
                }
                break;
            }
            try {
                Log::info('E4 parser fallback', ['where' => $where, 'from' => $primaryModel, 'to' => $rung]);
            } catch (\Throwable $ignored) {
            }
            $rungUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$rung}:generateContent?key={$apiKey}";
            try {
                $rungResult = $this->postGemini($rungUrl, $payload, $rung, $where . '-fallback', $attempts);
            } catch (GeminiTransportException $rungError) {
                $error = new GeminiTransportException(
                    $primaryError->getMessage(),
                    $rungError->status,
                    $rungError->quotaId,
                    $primaryError
                );
                continue;
            }
            $text = $rungResult['payload']['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (!is_string($text) || trim($text) === '') {
                continue;
            }
            $this->lastFallbackModel = $rung;

            return $text;
        }

        throw $error;
    }

    /**
     * E6: will the primary model be unavailable for the rest of the day?
     *
     * TRUE for a per-day quota (the meter refills at midnight Pacific at best)
     * and for a 404 (a retired or misspelled model id never becomes valid on its
     * own — the E3 saga with the pinned gemini-2.5-flash is the precedent).
     * FALSE for 503/502/504 and connection throws, which are capacity problems
     * that clear on their own.
     *
     * Called by: climbFallbackLadder(), to decide whether to promote a rung.
     */
    private function primaryIsSpentForToday(GeminiTransportException $error): bool
    {
        if ($error->status === 404) {
            return true;
        }

        return $error->status === 429
            && $error->quotaId !== null
            && $this->isPerDayQuota($error->quotaId);
    }

    /**
     * Fallback eligibility: overload/quota/dead-model/gateway failures and throws.
     * Auth/key failures (400/401/403) are excluded — the credential is the problem,
     * so every rung would fail identically and each attempt is pure latency. A
     * rung that rejects the request itself (400, e.g. a model that cannot honour
     * the Excel responseSchema) therefore ends the walk rather than continuing.
     *
     * Called by: climbFallbackLadder().
     */
    private function isFallbackEligible(int $status): bool
    {
        return $status === 0
            || in_array($status, [404, 429, 502, 503, 504], true);
    }

    /**
     * Backup model id for the normal-doc fallback (the admin-chosen main model —
     * Lite family in practice; never hardcoded so alias moves need no rebuild).
     *
     * Called by: callGeminiWithImages().
     */
    private function backupModel(): string
    {
        $model = config('services.gemini.model');

        return is_string($model) && $model !== '' ? $model : 'gemini-1.5-flash';
    }

    /**
     * Strip fences and decode a JSON object.
     *
     * Same fence-strip idiom as AIRecommendationService::parseResponse().
     * Returns null unless the payload is an object shape.
     *
     * Called by: parseProjectSlice()
     */
    private function parseJsonObject(string $text): ?array
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*\n?/', '', $text);
            $text = preg_replace('/\n?```\s*$/', '', $text);
            $text = trim($text);
        }

        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Validate + normalize the decoded payload into the 11-key shape.
     *
     * Unknown keys are dropped. Each field runs its own normalizer (mirrors the
     * JS learnedNormalizers bounds). All-null results become null (honest miss).
     *
     * Called by: parseProjectSlice()
     */
    private function validateParsed(array $data): ?array
    {
        $out = [
            'title' => $this->normalizeText($data['title'] ?? null, 5, 200),
            'location' => $this->normalizeText($data['location'] ?? null, 3, 200),
            'description' => $this->normalizeText($data['description'] ?? null, 3, 2000),
            'budget' => $this->normalizeMoney($data['budget'] ?? null),
            'abc' => $this->normalizeMoney($data['abc'] ?? null),
            'budgetSource' => $this->normalizeText($data['budgetSource'] ?? null, 2, 100),
            'category' => $this->normalizeText($data['category'] ?? null, 3, 50),
            'startDate' => $this->normalizeDate($data['startDate'] ?? null),
            'endDate' => $this->normalizeDate($data['endDate'] ?? null),
            'duration' => $this->normalizeDuration($data['duration'] ?? null),
            'contractor' => $this->normalizeText($data['contractor'] ?? null, 3, 150),
        ];

        foreach ($out as $v) {
            if ($v !== null && $v !== '') {
                return $out;
            }
        }

        return null;
    }

    /**
     * Keep a trimmed string within length bounds, else null.
     *
     * Called by: validateParsed()
     */
    private function normalizeText(mixed $v, int $min, int $max): ?string
    {
        if (!is_string($v) && !is_numeric($v)) {
            return null;
        }

        $s = trim((string) $v);
        $len = mb_strlen($s);

        if ($len < $min || $len > $max) {
            return null;
        }

        return $s;
    }

    /**
     * Normalize a money figure to "DDDDDDD.CC" or null.
     *
     * Accepts numerics and marked strings ("Php 4,834,297.59", "4,834,297.59").
     * Must be positive and finite.
     *
     * Called by: validateParsed()
     */
    private function normalizeMoney(mixed $v): ?string
    {
        if (is_numeric($v)) {
            $num = (float) $v;
        } elseif (is_string($v)) {
            $s = preg_replace('/[^0-9.,]/', '', $v);

            if ($s === '' || $s === null) {
                return null;
            }

            // Decimal is the trailing separator with exactly 2 digits ("4,323,196.77" and
            // OCR period-thousands "6.495,049.29" both resolve correctly); anything else is
            // a thousands separator and gets stripped.
            if (preg_match('/([.,])(\d{2})$/', $s, $m)) {
                $int = str_replace([',', '.'], '', substr($s, 0, -3));
                $s = $int . '.' . $m[2];
            } else {
                $s = str_replace([',', '.'], '', $s);
            }

            if (!is_numeric($s)) {
                return null;
            }

            $num = (float) $s;
        } else {
            return null;
        }

        if (!is_finite($num) || $num <= 0) {
            return null;
        }

        return number_format($num, 2, '.', '');
    }

    /**
     * Normalize a date to YYYY-MM-DD or null.
     *
     * Accepts ISO strings and anything strtotime() reads (e.g. "June 9, 2025",
     * "07/14/2025"). Sanity-bounded to plausible project years.
     *
     * Called by: validateParsed()
     */
    private function normalizeDate(mixed $v): ?string
    {
        if (!is_string($v) && !is_numeric($v)) {
            return null;
        }

        $s = trim((string) $v);

        if ($s === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $s : null;
        }

        $ts = strtotime($s);

        if ($ts === false) {
            return null;
        }

        $year = (int) date('Y', $ts);

        if ($year < 1900 || $year > 2100) {
            return null;
        }

        return date('Y-m-d', $ts);
    }

    /**
     * Normalize a duration to integer days (string) or null.
     *
     * Accepts plain ints ("185") and phrases ("185 calendar days" → 185).
     * Bounded like the JS wordsToNumber day cap (1-3650).
     *
     * Called by: validateParsed()
     */
    private function normalizeDuration(mixed $v): ?string
    {
        if (is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', trim($v)))) {
            $days = (int) $v;
        } elseif (is_numeric($v)) {
            $days = (int) $v;
        } elseif (is_string($v) && preg_match('/(\d[\d,]*)/', $v, $m)) {
            $days = (int) str_replace(',', '', $m[1]);
        } else {
            return null;
        }

        if ($days < 1 || $days > 3650) {
            return null;
        }

        return (string) $days;
    }
}
