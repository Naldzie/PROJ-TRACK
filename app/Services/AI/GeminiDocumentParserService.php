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
     * Normal-doc Lite fallback marker (user-approved 2026-09-10: images sender
     * only, Excel never). Holds the backup model id when the last call was
     * answered by Lite, else null. Reset at the start of every images call so
     * stale flags can never leak across runs; read by DocumentParserController
     * to provenance-tag the response.
     */
    public ?string $lastFallbackModel = null;

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
     * Called by: callGeminiText(), callGeminiWithImages().
     */
    private function postGemini(string $url, array $payload, string $model, string $where): array
    {
        $maxAttempts = 2;
        $attempts = 0;
        $lastStatus = 0;
        $lastQuotaId = null;

        while ($attempts < $maxAttempts) {
            $attempts++;
            try {
                $response = Http::timeout(30)->post($url, $payload);
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

        $raw = $this->callGeminiWithImages($this->buildPrompt(), $images);

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
                'summary' => is_array($decoded['summary'] ?? null) ? count($decoded['summary']) : 0,
                'amounts' => is_array($decoded['monthlyAmounts'] ?? null) ? count($decoded['monthlyAmounts']) : 0,
                'hasSummaryRow' => $decoded['hasSummaryRow'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Logging never breaks imports.
        }

        return $this->validateSCurveMonths($decoded, $this->extractSheetNumbers($csv));
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
            . ' "planned_progress_percent": 12.5}, ...], "monthlyAmounts": [{"month_index": 1,'
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
            . ' the two series must be reported independently even when they agree. Example with a'
            . ' disagreeing file: {"months": [{"month_index": 1, "planned_progress_percent": 5},'
            . ' {"month_index": 2, "planned_progress_percent": 10}], "monthlyAmounts":'
            . ' [{"month_index": 1, "pesos": 50000}, {"month_index": 2, "pesos": 50000}],'
            . ' "summary": [{"month_index": 1, "percent": 5}, {"month_index": 2, "percent": 9}],'
            . ' "hasSummaryRow": true, "contractAmount": 1000000}.'
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
     * Called by: parseExcelSlice()
     */
    private function validateSCurveMonths(mixed $decoded, array $sheetNumbers = ['values' => [], 'pairSums' => []]): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        $months = $this->sanitizeCurvePoints($decoded['months'] ?? null);
        $summary = $this->sanitizeSummaryPoints($decoded['summary'] ?? null);
        $amounts = $this->sanitizeAmountPoints($decoded['monthlyAmounts'] ?? null);
        $contract = $this->sanitizeContractAmount($decoded['contractAmount'] ?? null);
        $hasSummaryRow = ($decoded['hasSummaryRow'] ?? null) === true;

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
                return null;
            }

            // Slice-truth presence audit: don't claim sheet numbers you can't
            // point to. Each claimed monthly peso must appear among the sheet's
            // real numbers (or an adjacent-pair sum for paired layouts).
            if (count($amounts) > 0 && !$this->amountsPresentInSheet($amounts, $sheetNumbers)) {
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

            return [
                'months' => array_values($months),
                'meta' => [
                    'contractAmount' => $contract,
                    'warnings' => $warnings,
                ],
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
            ];
        }

        return null;
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

        foreach ($amounts as $a) {
            $found = false;
            foreach ($pool as $v) {
                if (abs($v - $a['pesos']) <= 1.0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
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
     * Called by: parseExcelSlice()
     * Uses: config/services.php (gemini key & model)
     */
    private function callGeminiText(string $prompt, ?array $filePart = null): string
    {
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

        $result = $this->postGemini($url, [
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
        ], $model, 'text');

        $data = $result['payload'];

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    /**
     * Response schema for the Excel homework format.
     *
     * Requires months + hasSummaryRow (a reply without the verification
     * scaffolding is a protocol miss, not a curve); amounts/summary stay
     * optional since weight-only and summary-less files are legitimate.
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

        return [
            'type' => 'OBJECT',
            'properties' => [
                'months' => ['type' => 'ARRAY', 'items' => $point('planned_progress_percent', 'NUMBER')],
                'monthlyAmounts' => ['type' => 'ARRAY', 'items' => $point('pesos', 'NUMBER')],
                'summary' => ['type' => 'ARRAY', 'items' => $point('percent', 'NUMBER')],
                'hasSummaryRow' => ['type' => 'BOOLEAN'],
                'contractAmount' => ['type' => 'NUMBER'],
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
     * parts (base64 + mimeType) after the text part. Normal-doc Lite fallback
     * (user-approved 2026-09-10, images sender ONLY): when the parser-model call
     * fails eligible (404/429/502/503/504 + throws — never 400/401/403 where the
     * key itself is broken), ONE attempt goes to Lite, whose answer flows through
     * the same validation. lastFallbackModel records the rescue for provenance.
     *
     * Called by: parseProjectSlice()
     * Uses: config/services.php (gemini parser key/model + main model for backup)
     */
    private function callGeminiWithImages(string $prompt, array $images): string
    {
        $this->lastFallbackModel = null;
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
            if (!$this->isFallbackEligible($e->status)) {
                throw $e;
            }
            // One Lite attempt, no retry loop (the parser model already spent it).
            $liteModel = $this->backupModel();
            try {
                Log::info('E3 parser fallback to Lite', ['where' => 'images', 'from' => $model]);
            } catch (\Throwable $ignored) {
            }
            $liteUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$liteModel}:generateContent?key={$apiKey}";
            try {
                $response = Http::timeout(30)->post($liteUrl, $payload);
                if (!$response->successful()) {
                    throw $e;
                }
                $liteData = $response->json();
                $text = is_array($liteData)
                    ? ($liteData['candidates'][0]['content']['parts'][0]['text'] ?? '')
                    : '';
                if (!is_string($text) || trim($text) === '') {
                    throw $e;
                }
                $this->lastFallbackModel = $liteModel;

                return $text;
            } catch (GeminiTransportException $liteError) {
                throw $e;
            } catch (\Exception $liteError) {
                throw $e;
            }
        }

        $data = $result['payload'];

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    /**
     * Lite fallback eligibility (normal-doc only): overload/quota/dead-model/
     * gateway failures and throws. Auth/key failures (400/401/403) are excluded —
     * Lite shares the project and would fail identically.
     *
     * Called by: callGeminiWithImages().
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
