<?php

namespace Tests\Unit\Services;

use App\Services\AiReportParser;
use Tests\TestCase;

/**
 * Validates the AI report section parser, in particular that section
 * headings (Report, Possible Risk, Mitigation, Performance, AI Comment)
 * render blue-highlighted — matching the project AI-analysis tab — and that
 * hand-written text stays plain.
 *
 * Run With
 * --------
 *   php artisan test --filter=AiReportParserTest
 */
class AiReportParserTest extends TestCase
{
    private AiReportParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AiReportParser();
    }

    public function test_section_headings_render_blue_highlighted(): void
    {
        $html = $this->parser->parseToHtml(
            "[SECTION:Report]All good.[/SECTION]\n" .
            "[SECTION:Possible Risk]Rain delays.[/SECTION]\n" .
            "[SECTION:Mitigation]Add cover.[/SECTION]\n" .
            "[SECTION:Performance]On track.[/SECTION]\n" .
            "[SECTION:AI Comment]Keep going.[/SECTION]"
        );

        foreach (['Report', 'Possible Risk', 'Mitigation', 'Performance', 'AI Comment'] as $heading) {
            $this->assertStringContainsString($heading, $html);
        }

        // Blue highlight must be inline so email clients that strip
        // <style> blocks still show it (same look as the project AI tab).
        $this->assertStringContainsString('color:#2563eb', $html);
        $this->assertStringContainsString('border-bottom:2px solid #2563eb', $html);
        // Raw markers must never leak into output.
        $this->assertStringNotContainsString('[SECTION:', $html);
    }

    public function test_render_for_display_parses_marked_ai_text(): void
    {
        $html = $this->parser->renderForDisplay('[SECTION:Report]Done well.[/SECTION]');

        $this->assertStringContainsString('Report', $html);
        $this->assertStringContainsString('color:#2563eb', $html);
    }

    public function test_render_for_display_keeps_handwritten_text_plain_and_escaped(): void
    {
        $html = $this->parser->renderForDisplay("Hello contractor,\nplease review <b>this</b>.");

        // No bogus section heading for manual messages…
        $this->assertStringNotContainsString('report-section-title', $html);
        $this->assertStringNotContainsString('color:#2563eb', $html);
        // …but newlines and escaping still work.
        $this->assertStringContainsString('<br />', $html);
        $this->assertStringContainsString('&lt;b&gt;this&lt;/b&gt;', $html);
    }
}
