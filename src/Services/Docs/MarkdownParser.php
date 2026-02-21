<?php

declare(strict_types=1);

namespace BrainCLI\Services\Docs;

use BrainCLI\Enums\Docs\CodeLanguage;
use Illuminate\Support\Str;

/**
 * Parses markdown documents for headers, code blocks, and content snippets.
 *
 * Handles ATX headers (# H1) and setext headers (underline with === or ---).
 * Code-block-aware: headers inside fenced code blocks are correctly ignored.
 */
class MarkdownParser
{
    public function __construct(
        protected LanguageDetector $languageDetector,
    ) {}

    /**
     * Parse markdown headers with optional level filtering.
     *
     * Returns headers with text, start_line, end_line, and optional snippet.
     * Headers inside fenced code blocks are excluded.
     *
     * @param string $content Raw markdown content
     * @param int $maxLevel Maximum header level to include (1-6)
     * @param bool $withSnippets Include content snippets for each header
     * @return array<int, array{text: string, start_line: int, end_line: int, snippet?: string}>
     */
    public function parseHeaders(string $content, int $maxLevel, bool $withSnippets = false): array
    {
        $lines = $this->splitLines($content);
        $totalLines = count($lines);
        $codeBlockRanges = $this->findCodeBlockRanges($lines);
        $allHeaders = [];

        // ATX headers: # H1, ## H2, etc.
        foreach ($lines as $index => $line) {
            if ($this->isInCodeBlock($index, $codeBlockRanges)) {
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
                $level = strlen($matches[1]);
                $header = [
                    'text' => trim($matches[2]),
                    'level' => $level,
                    'start_line' => $index + 1,
                ];

                if ($withSnippets) {
                    $snippet = $this->extractHeaderSnippet($lines, $index + 1, $totalLines, $codeBlockRanges);
                    if (!empty($snippet)) {
                        $header['snippet'] = $snippet;
                    }
                }

                $allHeaders[] = $header;
            }
        }

        // Find YAML front matter range (lines between opening --- and closing ---)
        $yamlRange = $this->findYamlFrontMatterRange($lines);

        // Setext headers: Title\n==== (H1) and Title\n---- (H2)
        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue;
            }

            if ($this->isInCodeBlock($index, $codeBlockRanges)) {
                continue;
            }

            // Skip lines inside YAML front matter
            if ($yamlRange !== null && $index >= $yamlRange[0] && $index <= $yamlRange[1]) {
                continue;
            }

            $previousLine = $lines[$index - 1];
            $trimmedPrevious = trim($previousLine);
            $trimmedCurrent = trim($line);

            // Skip if previous line is empty or looks like YAML/table/header
            if (
                $trimmedPrevious === '' ||
                str_starts_with($trimmedPrevious, '#') ||
                str_starts_with($trimmedPrevious, '|') ||
                str_starts_with($trimmedPrevious, '-') ||
                str_starts_with($trimmedPrevious, '=')
            ) {
                continue;
            }

            // Also skip if previous line is in a code block or inside YAML front matter
            if ($this->isInCodeBlock($index - 1, $codeBlockRanges)) {
                continue;
            }
            if ($yamlRange !== null && ($index - 1) >= $yamlRange[0] && ($index - 1) <= $yamlRange[1]) {
                continue;
            }

            $level = null;
            if (preg_match('/^={3,}\s*$/', $trimmedCurrent)) {
                $level = 1;
            } elseif (preg_match('/^-{3,}\s*$/', $trimmedCurrent)) {
                $level = 2;
            }

            if ($level !== null) {
                // Check this wasn't already captured as an ATX header line
                $alreadyCaptured = false;
                foreach ($allHeaders as $h) {
                    if ($h['start_line'] === $index) { // $index is 0-based, start_line is 1-based, previous line = $index
                        $alreadyCaptured = true;
                        break;
                    }
                }

                if (!$alreadyCaptured) {
                    $header = [
                        'text' => $trimmedPrevious,
                        'level' => $level,
                        'start_line' => $index, // 1-based: previous line = $index-1 (0-based) => $index (1-based)
                    ];

                    if ($withSnippets) {
                        // Snippet starts after the underline
                        $snippet = $this->extractHeaderSnippet($lines, $index + 1, $totalLines, $codeBlockRanges);
                        if (!empty($snippet)) {
                            $header['snippet'] = $snippet;
                        }
                    }

                    $allHeaders[] = $header;
                }
            }
        }

        // Sort all headers by start_line
        usort($allHeaders, fn(array $a, array $b) => $a['start_line'] <=> $b['start_line']);

        return $this->calculateHeaderEndLines($allHeaders, $totalLines, $maxLevel);
    }

    /**
     * Extract code blocks with language detection.
     *
     * If a language is declared, trust it as-is (resolved via CodeLanguage enum).
     * If undeclared and auto-detected, include detected language.
     * If undeclared and not auto-detected, include block WITHOUT language key.
     *
     * @param string $content Raw markdown content
     * @return array<int, array{language?: string, start_line: int, end_line: int}>
     */
    public function extractCodeBlocks(string $content): array
    {
        preg_match_all('/```(\w*)\n(.*?)```/s', $content, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return [];
        }

        $lineOffsets = $this->buildLineOffsets($content);
        $blocks = [];

        foreach ($matches[0] as $index => $match) {
            $declaredLang = $matches[1][$index][0];
            $codeContent = $matches[2][$index][0];
            $startLine = $this->offsetToLine($match[1], $lineOffsets);
            $endLine = $this->offsetToLine($match[1] + strlen($match[0]), $lineOffsets);

            $language = $this->languageDetector->detect($declaredLang, $codeContent);

            $block = [
                'start_line' => $startLine,
                'end_line' => $endLine,
            ];

            if ($language !== null) {
                $block['language'] = $language->value;
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * Extract auto-name from first header in content.
     *
     * @param string $content Content after YAML front matter
     * @return array{text: string, level: int}|null
     */
    public function extractAutoName(string $content): ?array
    {
        $lines = $this->splitLines($content);
        $codeBlockRanges = $this->findCodeBlockRanges($lines);

        // Try ATX headers first
        foreach ($lines as $index => $line) {
            if ($this->isInCodeBlock($index, $codeBlockRanges)) {
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
                $text = trim($matches[2]);
                if ($text !== '') {
                    return ['text' => $text, 'level' => strlen($matches[1])];
                }
            }
        }

        // Try setext headers
        foreach ($lines as $index => $line) {
            if ($index === 0 || $this->isInCodeBlock($index, $codeBlockRanges)) {
                continue;
            }

            $prevLine = trim($lines[$index - 1]);
            $currLine = trim($line);

            if ($prevLine === '' || str_starts_with($prevLine, '#') || str_starts_with($prevLine, '|') || str_starts_with($prevLine, '-') || str_starts_with($prevLine, '=')) {
                continue;
            }

            if ($this->isInCodeBlock($index - 1, $codeBlockRanges)) {
                continue;
            }

            if (preg_match('/^={3,}\s*$/', $currLine)) {
                return ['text' => $prevLine, 'level' => 1];
            }

            if (preg_match('/^-{3,}\s*$/', $currLine)) {
                return ['text' => $prevLine, 'level' => 2];
            }
        }

        return null;
    }

    /**
     * Extract auto-description from first paragraph in content.
     *
     * @param string $content Content after YAML front matter
     * @return string|null Description text or null if not found/too short
     */
    public function extractAutoDescription(string $content): ?string
    {
        $lines = $this->splitLines($content);
        $paragraph = [];
        $inParagraph = false;
        $inCodeBlock = false;

        foreach ($lines as $line) {
            if (str_starts_with($line, '```')) {
                $inCodeBlock = !$inCodeBlock;
                continue;
            }

            if ($inCodeBlock) {
                continue;
            }

            if (str_starts_with($line, '#')) {
                if ($inParagraph && count($paragraph) >= 2) {
                    break;
                }
                $paragraph = [];
                $inParagraph = false;
                continue;
            }

            $trimmed = trim($line);

            if ($trimmed === '' || $trimmed === '---' || str_starts_with($trimmed, '|')) {
                if ($inParagraph && count($paragraph) >= 2) {
                    break;
                }
                continue;
            }

            // Skip setext underlines
            if (preg_match('/^[=\-]{3,}\s*$/', $trimmed)) {
                continue;
            }

            $paragraph[] = $trimmed;
            $inParagraph = true;

            if (count($paragraph) >= 3) {
                break;
            }
        }

        if (empty($paragraph)) {
            return null;
        }

        $text = implode(' ', $paragraph);
        $wordCount = str_word_count($text);

        if ($wordCount < 5) {
            return null;
        }

        return Str::limit($text, 200);
    }

    /**
     * Build byte-offset-to-line mapping for content.
     *
     * @return array<int, int> Array of byte offsets for each line start
     */
    public function buildLineOffsets(string $content): array
    {
        $offsets = [];
        $currentOffset = 0;
        $lines = preg_split('/\r\n|\r|\n/', $content);

        foreach ($lines as $line) {
            $offsets[] = $currentOffset;
            $currentOffset += strlen($line) + 1;
        }

        return $offsets;
    }

    /**
     * Convert byte offset to line number (1-based).
     *
     * @param array<int, int> $lineOffsets
     */
    public function offsetToLine(int $offset, array $lineOffsets): int
    {
        $line = 1;
        foreach ($lineOffsets as $lineNumber => $lineOffset) {
            if ($offset >= $lineOffset) {
                $line = $lineNumber + 1;
            } else {
                break;
            }
        }
        return $line;
    }

    /**
     * Extract content snippet for a header section.
     *
     * @param array<int, string> $lines All document lines
     * @param int $startIndex 0-based index of the line AFTER the header
     * @param int $totalLines Total number of lines in the document
     * @param array<int, array{int, int}> $codeBlockRanges Fenced code block ranges
     * @return string Cleaned snippet text
     */
    protected function extractHeaderSnippet(array $lines, int $startIndex, int $totalLines, array $codeBlockRanges): string
    {
        $snippetLines = [];

        for ($i = $startIndex; $i < $totalLines && count($snippetLines) < 50; $i++) {
            if ($this->isInCodeBlock($i, $codeBlockRanges)) {
                continue;
            }

            $line = $lines[$i] ?? '';

            if (str_starts_with($line, '```')) {
                continue;
            }

            if (str_starts_with($line, '#') && count($snippetLines) > 0) {
                break;
            }

            if (str_starts_with($line, '#')) {
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed === '---' || str_starts_with($trimmed, '|')) {
                continue;
            }

            // Skip setext underlines
            if (preg_match('/^[=\-]{3,}\s*$/', $trimmed)) {
                continue;
            }

            $snippetLines[] = $trimmed;

            if (count($snippetLines) >= 5) {
                break;
            }
        }

        $snippet = implode(' ', $snippetLines);
        $snippet = preg_replace('/\s+/', ' ', $snippet);

        return Str::limit(trim($snippet), 200);
    }

    /**
     * Calculate end lines for each header based on next sibling/parent header.
     *
     * @param array<int, array{text: string, level: int, start_line: int, snippet?: string}> $allHeaders
     * @return array<int, array{text: string, start_line: int, end_line: int, snippet?: string}>
     */
    protected function calculateHeaderEndLines(array $allHeaders, int $totalLines, int $maxLevel): array
    {
        $result = [];
        $headerCount = count($allHeaders);

        foreach ($allHeaders as $index => $header) {
            if ($header['level'] > $maxLevel) {
                continue;
            }

            $endLine = $totalLines;

            for ($nextIndex = $index + 1; $nextIndex < $headerCount; $nextIndex++) {
                if ($allHeaders[$nextIndex]['level'] <= $header['level']) {
                    $endLine = $allHeaders[$nextIndex]['start_line'] - 1;
                    break;
                }
            }

            $item = [
                'text' => $header['text'],
                'start_line' => $header['start_line'],
                'end_line' => $endLine,
            ];

            if (isset($header['snippet'])) {
                $item['snippet'] = $header['snippet'];
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Find YAML front matter range (0-based line indices).
     *
     * YAML front matter starts with --- on line 0 and ends with --- on a subsequent line.
     *
     * @param array<int, string> $lines
     * @return array{int, int}|null [start, end] pair (inclusive) or null
     */
    protected function findYamlFrontMatterRange(array $lines): ?array
    {
        if (empty($lines) || trim($lines[0]) !== '---') {
            return null;
        }

        for ($i = 1, $count = count($lines); $i < $count; $i++) {
            if (trim($lines[$i]) === '---') {
                return [0, $i];
            }
        }

        return null;
    }

    /**
     * Find all fenced code block ranges (0-based line indices).
     *
     * @param array<int, string> $lines
     * @return array<int, array{int, int}> Array of [start, end] pairs (inclusive)
     */
    protected function findCodeBlockRanges(array $lines): array
    {
        $ranges = [];
        $inBlock = false;
        $blockStart = 0;

        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '```')) {
                if (!$inBlock) {
                    $inBlock = true;
                    $blockStart = $index;
                } else {
                    $ranges[] = [$blockStart, $index];
                    $inBlock = false;
                }
            }
        }

        // Handle unclosed code block
        if ($inBlock) {
            $ranges[] = [$blockStart, count($lines) - 1];
        }

        return $ranges;
    }

    /**
     * Check if a line index falls within any fenced code block.
     *
     * @param int $lineIndex 0-based line index
     * @param array<int, array{int, int}> $codeBlockRanges
     */
    protected function isInCodeBlock(int $lineIndex, array $codeBlockRanges): bool
    {
        foreach ($codeBlockRanges as [$start, $end]) {
            if ($lineIndex >= $start && $lineIndex <= $end) {
                return true;
            }
        }
        return false;
    }

    /**
     * Split content into lines.
     *
     * @return array<int, string>
     */
    protected function splitLines(string $content): array
    {
        return preg_split('/\r\n|\r|\n/', $content);
    }
}
