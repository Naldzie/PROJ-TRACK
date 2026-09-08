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

        return 'You are reading scanned Philippine government construction bidding pages. '
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
