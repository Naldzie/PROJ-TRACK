<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\TextQualityFilter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TextQualityFilter — gibberish/placeholder detection.
 *
 * Tests cover the three heuristics:
 * 1. Vowel ratio (gibberish like "dffdg" has < 15% vowels)
 * 2. Consecutive character repetition ("asdasd" has repeating pairs)
 * 3. Bigram diversity ("qeqeqeqwe" has very few unique pairs)
 * Plus placeholder patterns and edge cases.
 */
class TextQualityFilterTest extends TestCase
{
    private TextQualityFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new TextQualityFilter();
    }

    // ========================================================================
    // Meaningful text (should pass)
    // ========================================================================

    public function test_meaningful_real_issue_name(): void
    {
        $this->assertTrue($this->filter->isMeaningful('Delay Material Delivery'));
    }

    public function test_meaningful_real_description(): void
    {
        $this->assertTrue($this->filter->isMeaningful(
            'Missing or not enough materials needed to continue progress, materials are delayed due to the recent typhoon'
        ));
    }

    public function test_meaningful_short_but_real(): void
    {
        $this->assertTrue($this->filter->isMeaningful('Rain delay'));
    }

    public function test_meaningful_with_numbers(): void
    {
        $this->assertTrue($this->filter->isMeaningful('Section 3 concrete pour delayed 2 weeks'));
    }

    public function test_meaningful_filipino_english_mix(): void
    {
        $this->assertTrue($this->filter->isMeaningful('Hindi pa纳flush ang pipeline, waiting for materials'));
    }

    // ========================================================================
    // Gibberish (should fail) — from actual project 142 data
    // ========================================================================

    public function test_gibberish_asdasd(): void
    {
        $this->assertFalse($this->filter->isMeaningful('adasd'));
    }

    public function test_gibberish_addsa(): void
    {
        $this->assertFalse($this->filter->isMeaningful('addsa'));
    }

    public function test_gibberish_dffdg(): void
    {
        $this->assertFalse($this->filter->isMeaningful('dffdg'));
    }

    public function test_gibberish_dgdfgdf(): void
    {
        $this->assertFalse($this->filter->isMeaningful('dgdfgdf'));
    }

    public function test_gibberish_sdadfsdafadfsdsa(): void
    {
        $this->assertFalse($this->filter->isMeaningful('sdadfsdafadfsdsa'));
    }

    public function test_gibberish_adasdasdaadsasd(): void
    {
        $this->assertFalse($this->filter->isMeaningful('adasdasdaadsasd'));
    }

    public function test_gibberish_qeqeqeqweqwe(): void
    {
        $this->assertFalse($this->filter->isMeaningful('qeqeqeqweqwe'));
    }

    public function test_gibberish_aadsasdasdasd(): void
    {
        $this->assertFalse($this->filter->isMeaningful('aadsasdasdasd'));
    }

    public function test_gibberish_asdasdasddas(): void
    {
        $this->assertFalse($this->filter->isMeaningful('asdasdasddas'));
    }

    public function test_gibberish_asddasaasddas(): void
    {
        $this->assertFalse($this->filter->isMeaningful('asddasaasddas'));
    }

    public function test_gibberish_ghjhgjghjhgj(): void
    {
        $this->assertFalse($this->filter->isMeaningful('ghjhgjghjhgj'));
    }

    public function test_gibberish_ijokljkjhbghk(): void
    {
        $this->assertFalse($this->filter->isMeaningful('ijokljkjhbghk'));
    }

    public function test_gibberish_adsads(): void
    {
        $this->assertFalse($this->filter->isMeaningful('adsads'));
    }

    public function test_gibberish_adasdasdads(): void
    {
        $this->assertFalse($this->filter->isMeaningful('adasdasdads'));
    }

    public function test_gibberish_qewqweqweqwe(): void
    {
        $this->assertFalse($this->filter->isMeaningful('qewqweqweqwe'));
    }

    // ========================================================================
    // Placeholder patterns
    // ========================================================================

    public function test_placeholder_test(): void
    {
        $this->assertFalse($this->filter->isMeaningful('test'));
    }

    public function test_placeholder_lorem(): void
    {
        $this->assertFalse($this->filter->isMeaningful('lorem'));
    }

    public function test_placeholder_asdf(): void
    {
        $this->assertFalse($this->filter->isMeaningful('asdf'));
    }

    public function test_placeholder_xxx(): void
    {
        $this->assertFalse($this->filter->isMeaningful('xxx'));
    }

    public function test_placeholder_aaa(): void
    {
        $this->assertFalse($this->filter->isMeaningful('aaa'));
    }

    // ========================================================================
    // Edge cases
    // ========================================================================

    public function test_null_returns_false(): void
    {
        $this->assertFalse($this->filter->isMeaningful(null));
    }

    public function test_empty_string_returns_false(): void
    {
        $this->assertFalse($this->filter->isMeaningful(''));
    }

    public function test_single_char_returns_false(): void
    {
        $this->assertFalse($this->filter->isMeaningful('a'));
    }

    public function test_two_chars_returns_false(): void
    {
        $this->assertFalse($this->filter->isMeaningful('ab'));
    }

    public function test_whitespace_only_returns_false(): void
    {
        $this->assertFalse($this->filter->isMeaningful('   '));
    }

    public function test_numbers_only_returns_false(): void
    {
        $this->assertFalse($this->filter->isMeaningful('12345'));
    }

    public function test_mixed_numbers_and_gibberish(): void
    {
        $this->assertFalse($this->filter->isMeaningful('abc123'));
    }
}
