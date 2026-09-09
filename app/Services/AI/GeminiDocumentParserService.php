<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

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
     * Called by: SCurveImportController::parseWithGemini() (planned E2 endpoint).
     */
    public function parseExcelSlice(string $csv): ?array
    {
        $csv = trim($csv);

        if ($csv === '') {
            return null;
        }

        $raw = $this->callGeminiText($this->buildSCurvePrompt($csv));

        if ($raw === '') {
            return null;
        }

        $decoded = $this->parseJsonObject($raw);

        if ($decoded === null) {
            return null;
        }

        return $this->validateSCurveMonths($decoded);
    }

    /**
     * Build the strict-JSON S-curve extraction prompt around workbook text.
     *
     * Asks for two series so the server can apply the same reference+warn the
     * rules use: activity-derived months (the baseline) plus the author's own
     * summary-row values as printed (reference only, never recomputed).
     *
     * Called by: parseExcelSlice()
     */
    private function buildSCurvePrompt(string $csv): string
    {
        return 'You are reading a contractor S-curve schedule table (CSV text) for a Philippine'
            . ' government construction project. Months may be labeled "Month 1..N", "M1..N", "P1..N", month'
            . ' names, bare numbers, ordinals ("1st."), or day-group headers; values may be pesos'
            . ' (possibly duplicated across paired columns — count each month once), weights, or'
            . ' cumulative percents. Return ONLY a JSON object {"months": [{"month_index": 1,'
            . ' "planned_progress_percent": 12.5}, ...], "summary": [{"month_index": 1, "percent": 12.0},'
            . ' ...], "contractAmount": 32588531.43}. Rules: "months" MUST be computed from the'
            . ' activity/schedule rows (add each month\'s amounts, divide by the contract total,'
            . ' cumulative 0-100 per month, month_index sequential from 1); "summary" is the file\'s'
            . ' OWN summary/cumulative row values copied as printed (no recomputation — omit the key'
            . ' when the file has no summary row); contractAmount the total contract figure or null;'
            . ' use null fields you cannot find.'
            . ' Return ONLY the JSON object, no explanations, no markdown fences.'
            . "\n\nCSV:\n" . mb_substr($csv, 0, 12000);
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
     * Called by: parseExcelSlice()
     */
    private function validateSCurveMonths(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        $months = $this->sanitizeCurvePoints($decoded['months'] ?? null);
        $summary = $this->sanitizeSummaryPoints($decoded['summary'] ?? null);
        $contract = $this->sanitizeContractAmount($decoded['contractAmount'] ?? null);

        // Activity series usable — keep it, summary only warns.
        if (count($months) >= 2) {
            $warnings = ['AI-assisted curve — verify before locking the baseline.'];
            $byMonth = [];
            foreach ($months as $m) {
                $byMonth[$m['month_index']] = $m['planned_progress_percent'];
            }
            foreach ($summary as $s) {
                if (!isset($byMonth[$s['month_index']])) continue;
                $gap = abs($s['percent'] - $byMonth[$s['month_index']]);
                if ($gap > 1.0) {
                    $warnings[] = "Heads-up (Month {$s['month_index']}): the file's own summary"
                        . " says {$s['percent']}%, but its activity rows add up to"
                        . " {$byMonth[$s['month_index']]}%. Your baseline below uses the"
                        . ' activity math — no action needed.';
                }
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
     * Same shape as callGeminiWithImages() without image parts.
     *
     * Called by: parseExcelSlice()
     * Uses: config/services.php (gemini key & model)
     */
    private function callGeminiText(string $prompt): string
    {
        $apiKey = config('services.gemini.key');
        $model = config('services.gemini.model', 'gemini-1.5-flash');

        if (empty($apiKey)) {
            return '';
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(30)->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
            ]);

            if (!$response->successful()) {
                return '';
            }

            $data = $response->json();

            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } catch (\Exception $e) {
            return '';
        }
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
     * Called by: parseProjectSlice()
     * Uses: config/services.php (gemini key & model)
     */
    private function callGeminiWithImages(string $prompt, array $images): string
    {
        $apiKey = config('services.gemini.key');
        $model = config('services.gemini.model', 'gemini-1.5-flash');

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

        try {
            $response = Http::timeout(30)->post($url, [
                'contents' => [
                    [
                        'parts' => $parts,
                    ],
                ],
            ]);

            if (!$response->successful()) {
                return '';
            }

            $data = $response->json();

            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } catch (\Exception $e) {
            return '';
        }
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
