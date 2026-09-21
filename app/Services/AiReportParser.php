<?php

namespace App\Services;

class AiReportParser
{
    public function parse(string $text): array
    {
        if (empty(trim($text))) {
            return [];
        }

        $sections = [
            ['key' => 'Report', 'label' => 'Report'],
            ['key' => 'Possible Risk', 'label' => 'Possible Risk'],
            ['key' => 'Mitigation', 'label' => 'Mitigation'],
            ['key' => 'Performance', 'label' => 'Performance'],
            ['key' => 'AI Comment', 'label' => 'AI Comment'],
            ['key' => 'Next Steps', 'label' => 'Next Steps'],
            ['key' => 'Progress', 'label' => 'Progress'],
        ];

        $parsedSections = [];
        $remainingText = $text;

        foreach ($sections as $section) {
            $startMarker = '[SECTION:' . $section['key'] . ']';
            $endMarker = '[/SECTION]';

            $startIdx = strpos($remainingText, $startMarker);
            if ($startIdx === false) {
                continue;
            }

            $contentStart = $startIdx + strlen($startMarker);
            $endIdx = strpos($remainingText, $endMarker, $contentStart);
            if ($endIdx === false) {
                continue;
            }

            $content = trim(substr($remainingText, $contentStart, $endIdx - $contentStart));
            if ($content === '') {
                continue;
            }

            $formattedContent = $this->formatContent($content);

            $parsedSections[] = [
                'title' => $section['label'],
                'content' => $formattedContent,
            ];

            $remainingText = substr($remainingText, $endIdx + strlen($endMarker));
        }

        if (empty($parsedSections)) {
            $parsedSections[] = [
                'title' => 'Analysis',
                'content' => $this->formatContent($text),
            ];
        }

        return $parsedSections;
    }

    /**
     * ------------------------------------------------------------------------
     * Parse To Blocks (plain text, for document writers)
     * ------------------------------------------------------------------------
     *
     * Same section extraction as parse(), but each section's content comes back
     * as plain-text blocks instead of HTML, so a Word/PDF writer can lay it out
     * itself:
     *   [['title' => 'Report', 'blocks' => [
     *       ['type' => 'paragraph', 'text' => '…'],
     *       ['type' => 'list', 'ordered' => false, 'items' => ['…', '…']],
     *   ]], …]
     *
     * Text without any [SECTION:] marker becomes one 'Analysis' section, like
     * parse(). Nothing is HTML-escaped here — the writer escapes for its format.
     *
     * Called by: Reports\ProjectReportDataService (project report, Word + PDF)
     */
    public function parseToBlocks(?string $text): array
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return [];
        }

        $sections = [];
        foreach ($this->extractSections($text) as $section) {
            $sections[] = [
                'title'  => $section['title'],
                'blocks' => $this->contentToBlocks($section['content']),
            ];
        }

        if (empty($sections)) {
            $sections[] = ['title' => 'Analysis', 'blocks' => $this->contentToBlocks($text)];
        }

        return $sections;
    }

    /**
     * Raw (unformatted) sections in the fixed order parse() uses.
     * Mirrors parse()'s marker walk exactly; parse() itself is left unchanged.
     */
    private function extractSections(string $text): array
    {
        $keys = ['Report', 'Possible Risk', 'Mitigation', 'Performance', 'AI Comment', 'Next Steps', 'Progress'];

        $found = [];
        $remainingText = $text;

        foreach ($keys as $key) {
            $startMarker = '[SECTION:' . $key . ']';
            $startIdx = strpos($remainingText, $startMarker);
            if ($startIdx === false) {
                continue;
            }

            $contentStart = $startIdx + strlen($startMarker);
            $endIdx = strpos($remainingText, '[/SECTION]', $contentStart);
            if ($endIdx === false) {
                continue;
            }

            $content = trim(substr($remainingText, $contentStart, $endIdx - $contentStart));
            if ($content !== '') {
                $found[] = ['title' => $key, 'content' => $content];
            }

            $remainingText = substr($remainingText, $endIdx + strlen('[/SECTION]'));
        }

        return $found;
    }

    /**
     * Split section text into paragraph / list blocks (plain text).
     * Same bullet and numbering rules as formatContent().
     */
    private function contentToBlocks(string $content): array
    {
        $blocks = [];
        $paragraph = [];
        $list = null;

        $flushParagraph = function () use (&$blocks, &$paragraph) {
            // Blank lines separate paragraphs, as in formatParagraphs()
            foreach (preg_split('/\n\s*\n/', implode("\n", $paragraph)) as $p) {
                $p = trim(preg_replace('/\s*\n\s*/', ' ', $p));
                if ($p !== '') {
                    $blocks[] = ['type' => 'paragraph', 'text' => $p];
                }
            }
            $paragraph = [];
        };
        $flushList = function () use (&$blocks, &$list) {
            if ($list !== null && !empty($list['items'])) {
                $blocks[] = $list;
            }
            $list = null;
        };

        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);
            $isBullet = (bool) preg_match('/^[-*•]\s+/u', $trimmed);
            $isNumbered = (bool) preg_match('/^\d+[.)]\s+/', $trimmed);

            if ($isBullet || $isNumbered) {
                $flushParagraph();
                if ($list === null || $list['ordered'] !== $isNumbered) {
                    $flushList();
                    $list = ['type' => 'list', 'ordered' => $isNumbered, 'items' => []];
                }
                $list['items'][] = trim(preg_replace($isNumbered ? '/^\d+[.)]\s+/' : '/^[-*•]\s+/u', '', $trimmed));
                continue;
            }

            $flushList();
            $paragraph[] = $line;
        }

        $flushParagraph();
        $flushList();

        return $blocks;
    }

    public function parseToHtml(string $text): string
    {
        $sections = $this->parse($text);

        if (empty($sections)) {
            return '<p>' . nl2br(e($text)) . '</p>';
        }

        // Heading/content styling is INLINE (not just classes) so the blue
        // highlight survives email clients that strip <style> blocks. It
        // matches the project AI-analysis tab (.report-section-title).
        $html = '';
        foreach ($sections as $section) {
            $html .= '<div class="report-section">';
            $html .= '<div class="report-section-title" style="font-size:11px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:#2563eb;border-bottom:2px solid #2563eb;padding-bottom:4px;margin-bottom:8px;">' . e($section['title']) . '</div>';
            $html .= '<div class="report-section-content" style="font-size:13px;color:#475569;line-height:1.7;">' . $section['content'] . '</div>';
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Render stored message text for on-screen display (view overlay).
     *
     * AI text carrying [SECTION:] markers becomes blue-highlighted sections;
     * hand-written draft text (no markers) stays plain escaped paragraphs so
     * manual messages never gain a bogus "Analysis" heading.
     */
    public function renderForDisplay(?string $text): string
    {
        $text = $text ?? '';

        if (str_contains($text, '[SECTION:')) {
            return $this->parseToHtml($text);
        }

        return '<p>' . nl2br(e($text)) . '</p>';
    }

    private function formatContent(string $content): string
    {
        $lines = explode("\n", $content);
        $formattedLines = [];
        $inList = false;
        $listType = 'ul';
        $paragraphBuffer = [];

        foreach ($lines as $line) {
            $escapedLine = e($line);
            $trimmedLine = trim($escapedLine);

            $isBullet = preg_match('/^[-*•]\s+/', $trimmedLine);
            $isNumbered = preg_match('/^\d+[.)]\s+/', $trimmedLine);

            if ($isBullet || $isNumbered) {
                if (!empty($paragraphBuffer)) {
                    $formattedLines[] = $this->formatParagraphs(implode("\n", $paragraphBuffer));
                    $paragraphBuffer = [];
                }

                if (!$inList) {
                    $inList = true;
                    $listType = $isNumbered ? 'ol' : 'ul';
                    $formattedLines[] = "<{$listType}>";
                } elseif ($listType === 'ol' && $isBullet) {
                    $formattedLines[] = "</ol><ul>";
                    $listType = 'ul';
                } elseif ($listType === 'ul' && $isNumbered) {
                    $formattedLines[] = "</ul><ol>";
                    $listType = 'ol';
                }

                $itemContent = $isNumbered
                    ? preg_replace('/^\d+[.)]\s+/', '', $trimmedLine)
                    : preg_replace('/^[-*•]\s+/', '', $trimmedLine);
                $formattedLines[] = "<li>{$itemContent}</li>";
            } else {
                if ($inList) {
                    $inList = false;
                    $formattedLines[] = "</{$listType}>";
                }
                $paragraphBuffer[] = $escapedLine;
            }
        }

        if (!empty($paragraphBuffer)) {
            $formattedLines[] = $this->formatParagraphs(implode("\n", $paragraphBuffer));
        }

        if ($inList) {
            $formattedLines[] = "</{$listType}>";
        }

        return implode('', $formattedLines);
    }

    private function formatParagraphs(string $content): string
    {
        $paragraphs = preg_split('/\n\s*\n/', $content);
        $formatted = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $paragraph = str_replace("\n", '<br>', $paragraph);
            $formatted[] = '<p>' . $paragraph . '</p>';
        }

        return implode('', $formatted);
    }
}