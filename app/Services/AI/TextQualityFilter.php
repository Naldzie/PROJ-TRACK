<?php

namespace App\Services\AI;

/**
 * ============================================================================
 * TextQualityFilter
 * ============================================================================
 *
 * Purpose
 * -------
 * Detects gibberish, placeholder, or low-quality text in open-text fields
 * (issue names, descriptions, extension reasons) so that AI prompts only
 * receive meaningful data.
 *
 * Heuristics
 * ----------
 * 1. Minimum length: text < 5 characters is low-quality
 * 2. Vowel ratio: gibberish like "dffdg" or "ghjhgjghjhgj" has < 15% vowels
 * 3. Bigram repetition: keyboard mashing ("asdasd", "qeqeqeqwe") has high
 *    consecutive character repetition
 * 4. Placeholder patterns: known test/lorem/keyboard-mash words
 *
 * Each text passes all checks; any single failure marks it as low-quality.
 * Used by AIRecommendationService to build cleaner prompts.
 */
class TextQualityFilter
{
    /**
     * Placeholder words and patterns (case-insensitive).
     * These appear in dev/test data and carry no real meaning.
     */
    private const PLACEHOLDER_PATTERNS = [
        '/^(test|asdf|lorem|ipsum|placeholder|sample|dummy|xxx|aaa|bbb|ccc|fff|ggg|hhh|jjj|kkk|lll|mmm|nnn|ppp|qqq|rrr|sss|ttt|vvv|www|zzz)$/i',
        '/^[a-z]{2,6}\d{2,6}$/i',
        '/^.{0,2}$/',
    ];

    /**
     * Minimum vowel ratio for real text.
     * Most real English/Filipino words have 20%+ vowels.
     * Gibberish like "dffdg" or "ghjhgj" has < 16%.
     * Threshold set to 0.16 to catch "ijokljkjhbghk" (15.38%).
     */
    private const MIN_VOWEL_RATIO = 0.16;

    /**
     * Minimum number of unique alphabetic characters for a text to be
     * considered meaningful. Gibberish like "adasd" or "sdadfsdafadfsdsa"
     * repeats the same 3-4 characters over and over.
     * Real text of 6+ chars always has 5+ unique characters.
     */
    private const MIN_UNIQUE_CHARS = 5;

    /**
     * Maximum allowed consecutive repeating characters as a ratio.
     * If > 40% of character pairs are identical (e.g. "aa", "bb"), it's
     * likely keyboard mashing.
     */
    private const MAX_CONSECUTIVE_RATIO = 0.40;

    /**
     * Maximum bigram diversity ratio.
     * Real text has many unique bigrams. Gibberish has few.
     * If unique_bigrams / total_bigrams < 0.30, it's likely gibberish.
     */
    private const MIN_BIGRAM_DIVERSITY = 0.30;

    /**
     * ------------------------------------------------------------------------
     * Is Text Meaningful
     * ------------------------------------------------------------------------
     *
     * Returns true if the text appears to be real, meaningful human input.
     * Returns false for gibberish, placeholder, or keyboard-mashing text.
     *
     * @param  string|null  $text  The text to evaluate
     * @return bool  true if meaningful, false if gibberish
     */
    public function isMeaningful(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        $text = trim($text);

        // Empty or very short
        if (mb_strlen($text) < 3) {
            return false;
        }

        // Check placeholder patterns
        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return false;
            }
        }

        // Unique character count check — gibberish repeats the same 3-4 chars
        $alphaOnly = preg_replace('/[^a-zA-Z]/', '', $text);
        $uniqueCount = count(array_unique(str_split($alphaOnly)));
        if (mb_strlen($alphaOnly) >= 5 && $uniqueCount < self::MIN_UNIQUE_CHARS) {
            return false;
        }

        // Vowel ratio check
        if ($this->vowelRatio($text) < self::MIN_VOWEL_RATIO) {
            return false;
        }

        // Consecutive character repetition check
        if ($this->consecutiveRatio($text) > self::MAX_CONSECUTIVE_RATIO) {
            return false;
        }

        // Bigram diversity check
        if ($this->bigramDiversity($text) < self::MIN_BIGRAM_DIVERSITY) {
            return false;
        }

        return true;
    }

    /**
     * ------------------------------------------------------------------------
     * Vowel Ratio
     * ------------------------------------------------------------------------
     *
     * Returns the ratio of vowels to total alphabetic characters.
     * Ignores non-alpha characters (numbers, spaces, punctuation).
     *
     * @param  string  $text
     * @return float  0.0 to 1.0
     */
    private function vowelRatio(string $text): float
    {
        $alphaOnly = preg_replace('/[^a-zA-Z]/', '', $text);
        $length = mb_strlen($alphaOnly);

        if ($length === 0) {
            return 0.0;
        }

        $vowels = preg_match_all('/[aeiou]/i', $alphaOnly);
        return $vowels / $length;
    }

    /**
     * ------------------------------------------------------------------------
     * Consecutive Character Ratio
     * ------------------------------------------------------------------------
     *
     * Returns the ratio of consecutive identical characters.
     * "asdasd" → pairs "as","sd","da","as","sd" → 0 identical → low ratio
     * "aabb"   → pairs "aa","ab","bb" → 2 identical out of 3 → high ratio
     *
     * @param  string  $text
     * @return float  0.0 to 1.0
     */
    private function consecutiveRatio(string $text): float
    {
        $lower = mb_strtolower($text);
        $pairs = mb_strlen($lower) - 1;

        if ($pairs <= 0) {
            return 0.0;
        }

        $identical = 0;
        for ($i = 0; $i < $pairs; $i++) {
            if ($lower[$i] === $lower[$i + 1]) {
                $identical++;
            }
        }

        return $identical / $pairs;
    }

    /**
     * ------------------------------------------------------------------------
     * Bigram Diversity
     * ------------------------------------------------------------------------
     *
     * Returns the ratio of unique bigrams to total bigrams.
     * Real English text: "delay material" → many unique pairs → high diversity
     * Gibberish: "asdasdasd" → only "as","sd","da" → low diversity
     *
     * @param  string  $text
     * @return float  0.0 to 1.0
     */
    private function bigramDiversity(string $text): float
    {
        $lower = mb_strtolower(preg_replace('/[^a-zA-Z]/', '', $text));
        $length = mb_strlen($lower);

        if ($length < 2) {
            return 0.0;
        }

        $bigrams = [];
        for ($i = 0; $i < $length - 1; $i++) {
            $bigrams[] = $lower[$i] . $lower[$i + 1];
        }

        $total = count($bigrams);
        $unique = count(array_unique($bigrams));

        return $unique / $total;
    }
}
