<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Docs;

use BrainCLI\Enums\Docs\CodeLanguage;
use BrainCLI\Services\Docs\LanguageDetector;
use BrainCLI\Services\Docs\MarkdownParser;
use PHPUnit\Framework\TestCase;

class MarkdownParserTest extends TestCase
{
    protected MarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MarkdownParser(new LanguageDetector());
    }

    public function test_parse_atx_headers_basic(): void
    {
        $content = "# Title\n\nSome text\n\n## Section 1\n\nContent\n\n## Section 2\n\nMore content\n";

        $headers = $this->parser->parseHeaders($content, 2);

        $this->assertCount(3, $headers);
        $this->assertSame('Title', $headers[0]['text']);
        $this->assertSame(1, $headers[0]['start_line']);
        $this->assertSame('Section 1', $headers[1]['text']);
        $this->assertSame('Section 2', $headers[2]['text']);
    }

    public function test_parse_headers_respects_max_level(): void
    {
        $content = "# H1\n## H2\n### H3\n#### H4\n";

        $h1Only = $this->parser->parseHeaders($content, 1);
        $this->assertCount(1, $h1Only);
        $this->assertSame('H1', $h1Only[0]['text']);

        $h1h2 = $this->parser->parseHeaders($content, 2);
        $this->assertCount(2, $h1h2);
    }

    public function test_headers_inside_code_blocks_are_ignored(): void
    {
        $content = "# Real Header\n\n```markdown\n# Fake Header Inside Code\n## Another Fake\n```\n\n## Real Section\n";

        $headers = $this->parser->parseHeaders($content, 3);

        $this->assertCount(2, $headers);
        $this->assertSame('Real Header', $headers[0]['text']);
        $this->assertSame('Real Section', $headers[1]['text']);
    }

    public function test_headers_inside_nested_code_blocks(): void
    {
        $content = "# Top\n\n```php\nclass Foo {\n    // # Not a header\n}\n```\n\n# Bottom\n";

        $headers = $this->parser->parseHeaders($content, 1);

        $this->assertCount(2, $headers);
        $this->assertSame('Top', $headers[0]['text']);
        $this->assertSame('Bottom', $headers[1]['text']);
    }

    public function test_setext_h1_header(): void
    {
        $content = "Title Here\n==========\n\nSome content\n";

        $headers = $this->parser->parseHeaders($content, 1);

        $this->assertCount(1, $headers);
        $this->assertSame('Title Here', $headers[0]['text']);
    }

    public function test_setext_h2_header(): void
    {
        $content = "# Main Title\n\nSubtitle\n--------\n\nContent\n";

        $headers = $this->parser->parseHeaders($content, 2);

        $this->assertCount(2, $headers);
        $this->assertSame('Main Title', $headers[0]['text']);
        $this->assertSame('Subtitle', $headers[1]['text']);
    }

    public function test_setext_does_not_match_yaml_separator(): void
    {
        $content = "---\nname: Test\n---\n\n# Actual Header\n";

        $headers = $this->parser->parseHeaders($content, 2);

        // YAML --- should NOT be treated as setext header
        $this->assertCount(1, $headers);
        $this->assertSame('Actual Header', $headers[0]['text']);
    }

    public function test_setext_does_not_match_after_empty_line(): void
    {
        $content = "Some text\n\n---\n\n# Header\n";

        $headers = $this->parser->parseHeaders($content, 2);

        // --- after empty line should NOT be setext
        $this->assertCount(1, $headers);
        $this->assertSame('Header', $headers[0]['text']);
    }

    public function test_setext_does_not_match_table_separator(): void
    {
        $content = "| Col 1 | Col 2 |\n|-------|-------|\n| Val 1 | Val 2 |\n\n# Real Header\n";

        $headers = $this->parser->parseHeaders($content, 2);

        $this->assertCount(1, $headers);
        $this->assertSame('Real Header', $headers[0]['text']);
    }

    public function test_header_end_lines_calculated_correctly(): void
    {
        $content = "# H1\nline2\nline3\n## H2\nline5\nline6\n## H2b\nline8\n";
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $totalLines = count($lines);

        $headers = $this->parser->parseHeaders($content, 2);

        // H1 has no sibling H1, so it extends to end of file
        $this->assertSame($totalLines, $headers[0]['end_line']);
        // H2 ends before H2b (same level)
        $this->assertSame(6, $headers[1]['end_line']);
        // H2b extends to end of file
        $this->assertSame($totalLines, $headers[2]['end_line']);
    }

    public function test_parse_headers_with_snippets(): void
    {
        $content = "# Title\n\nFirst paragraph line.\nSecond paragraph line.\n\n## Section\n";

        $headers = $this->parser->parseHeaders($content, 1, withSnippets: true);

        $this->assertCount(1, $headers);
        $this->assertArrayHasKey('snippet', $headers[0]);
        $this->assertStringContainsString('First paragraph', $headers[0]['snippet']);
    }

    public function test_snippets_skip_code_blocks(): void
    {
        $content = "# Title\n\n```php\n\$code = true;\n```\n\nActual text here.\n";

        $headers = $this->parser->parseHeaders($content, 1, withSnippets: true);

        $this->assertCount(1, $headers);
        $this->assertStringContainsString('Actual text', $headers[0]['snippet']);
        $this->assertStringNotContainsString('$code', $headers[0]['snippet']);
    }

    public function test_extract_code_blocks_with_declared_language(): void
    {
        $content = "# Title\n\n```php\n\$x = 1;\n```\n\n```json\n{\"key\": \"value\"}\n```\n";

        $blocks = $this->parser->extractCodeBlocks($content);

        $this->assertCount(2, $blocks);
        $this->assertSame('php', $blocks[0]['language']);
        $this->assertSame('json', $blocks[1]['language']);
    }

    public function test_extract_code_blocks_with_auto_detection(): void
    {
        $content = "# Title\n\n```\n<?php\necho 'hello';\n```\n";

        $blocks = $this->parser->extractCodeBlocks($content);

        $this->assertCount(1, $blocks);
        $this->assertSame('php', $blocks[0]['language']);
    }

    public function test_extract_code_blocks_undeclared_undetected_included_without_language(): void
    {
        $content = "# Title\n\n```\nsome random text content\nno clear language\n```\n";

        $blocks = $this->parser->extractCodeBlocks($content);

        $this->assertCount(1, $blocks);
        $this->assertArrayNotHasKey('language', $blocks[0]);
        $this->assertArrayHasKey('start_line', $blocks[0]);
        $this->assertArrayHasKey('end_line', $blocks[0]);
    }

    public function test_extract_code_blocks_line_numbers(): void
    {
        $content = "line1\nline2\n```php\ncode\n```\nline6\n";

        $blocks = $this->parser->extractCodeBlocks($content);

        $this->assertCount(1, $blocks);
        $this->assertSame(3, $blocks[0]['start_line']);
        $this->assertSame(5, $blocks[0]['end_line']);
    }

    public function test_extract_auto_name_atx(): void
    {
        $content = "Some intro text\n\n# The Title\n\nMore text\n";

        $result = $this->parser->extractAutoName($content);

        $this->assertNotNull($result);
        $this->assertSame('The Title', $result['text']);
        $this->assertSame(1, $result['level']);
    }

    public function test_extract_auto_name_setext(): void
    {
        $content = "The Title\n=========\n\nSome text\n";

        $result = $this->parser->extractAutoName($content);

        $this->assertNotNull($result);
        $this->assertSame('The Title', $result['text']);
        $this->assertSame(1, $result['level']);
    }

    public function test_extract_auto_name_skips_code_block_headers(): void
    {
        $content = "```markdown\n# Fake Header\n```\n\n# Real Header\n";

        $result = $this->parser->extractAutoName($content);

        $this->assertNotNull($result);
        $this->assertSame('Real Header', $result['text']);
    }

    public function test_extract_auto_name_returns_null_on_empty(): void
    {
        $content = "Just some text without any headers.\n";

        $this->assertNull($this->parser->extractAutoName($content));
    }

    public function test_extract_auto_description(): void
    {
        $content = "# Title\n\nThis is the first paragraph of the document that explains something important about the topic.\n";

        $result = $this->parser->extractAutoDescription($content);

        $this->assertNotNull($result);
        $this->assertStringContainsString('first paragraph', $result);
    }

    public function test_extract_auto_description_skips_short_text(): void
    {
        $content = "# Title\n\nShort.\n";

        $this->assertNull($this->parser->extractAutoDescription($content));
    }

    public function test_extract_auto_description_skips_code_blocks(): void
    {
        $content = "# Title\n\n```\ncode content that should not be description\n```\n\nThis is actual description text that should be picked up.\n";

        $result = $this->parser->extractAutoDescription($content);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('code content', $result);
    }

    public function test_build_line_offsets(): void
    {
        $content = "abc\ndef\nghi";
        $offsets = $this->parser->buildLineOffsets($content);

        $this->assertSame(0, $offsets[0]);
        $this->assertSame(4, $offsets[1]);
        $this->assertSame(8, $offsets[2]);
    }

    public function test_offset_to_line(): void
    {
        $offsets = [0, 10, 20, 30];

        $this->assertSame(1, $this->parser->offsetToLine(0, $offsets));
        $this->assertSame(1, $this->parser->offsetToLine(5, $offsets));
        $this->assertSame(2, $this->parser->offsetToLine(10, $offsets));
        $this->assertSame(3, $this->parser->offsetToLine(25, $offsets));
    }

    public function test_mdx_jsx_tags_do_not_break_header_parsing(): void
    {
        $content = "# MDX Title\n\n<Component prop=\"value\" />\n\n## Section\n\nText with <Inline /> component.\n";

        $headers = $this->parser->parseHeaders($content, 2);

        $this->assertCount(2, $headers);
        $this->assertSame('MDX Title', $headers[0]['text']);
        $this->assertSame('Section', $headers[1]['text']);
    }

    public function test_mdx_code_blocks_with_jsx_detected(): void
    {
        $content = "# Title\n\n```jsx\nfunction App() {\n  return <div>Hello</div>;\n}\n```\n";

        $blocks = $this->parser->extractCodeBlocks($content);

        $this->assertCount(1, $blocks);
        // jsx is an alias for javascript in CodeLanguage enum
        $this->assertSame('javascript', $blocks[0]['language']);
    }

    public function test_mixed_atx_and_setext_headers(): void
    {
        $content = "Title\n=====\n\n## ATX Header\n\nSubtitle\n--------\n\n### H3\n";

        $headers = $this->parser->parseHeaders($content, 3);

        $this->assertCount(4, $headers);
        $this->assertSame('Title', $headers[0]['text']);
        $this->assertSame('ATX Header', $headers[1]['text']);
        $this->assertSame('Subtitle', $headers[2]['text']);
        $this->assertSame('H3', $headers[3]['text']);
    }
}
