<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox('MCP tools/list schema contract')]
final class McpToolsListSchemaTest extends TestCase
{
    private array $toolsResponse;

    protected function setUp(): void
    {
        parent::setUp();

        $cwd = getcwd();
        $projectRoot = str_ends_with($cwd, '/cli') ? dirname($cwd) : $cwd;

        $json = shell_exec(
            'echo \'{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}\' | BRAIN_TEST_MODE=1 php ' . escapeshellarg($projectRoot . '/cli/bin/brain') . ' mcp:serve 2>/dev/null'
        );

        $this->assertNotFalse($json, 'mcp:serve must return output');

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'mcp:serve must return valid JSON');

        $this->toolsResponse = $decoded;
    }

    #[Test]
    #[TestDox('tools/list has exactly 3 tools')]
    public function toolsList_has_exactly_3_tools(): void
    {
        $this->assertArrayHasKey('result', $this->toolsResponse);
        $this->assertArrayHasKey('tools', $this->toolsResponse['result']);
        $this->assertCount(3, $this->toolsResponse['result']['tools']);
    }

    #[Test]
    #[TestDox('tools are sorted alphabetically')]
    public function tools_are_sorted_alphabetically(): void
    {
        $names = array_map(
            fn(array $tool): string => $tool['name'],
            $this->toolsResponse['result']['tools']
        );

        $sorted = $names;
        sort($sorted);

        $this->assertSame($sorted, $names);
    }

    #[Test]
    #[TestDox('tool names are exactly diagnose, docs_search, list_masters')]
    public function tool_names_are_exact(): void
    {
        $names = array_map(
            fn(array $tool): string => $tool['name'],
            $this->toolsResponse['result']['tools']
        );

        $this->assertSame(['diagnose', 'docs_search', 'list_masters'], $names);
    }

    #[Test]
    #[TestDox('all tools have additionalProperties=false')]
    public function all_tools_have_additionalProperties_false(): void
    {
        foreach ($this->toolsResponse['result']['tools'] as $tool) {
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertArrayHasKey('additionalProperties', $tool['inputSchema']);
            $this->assertFalse(
                $tool['inputSchema']['additionalProperties'],
                "Tool {$tool['name']} must have additionalProperties=false"
            );
        }
    }

    #[Test]
    #[TestDox('all tools have inputSchema type=object')]
    public function all_tools_have_inputSchema_type_object(): void
    {
        foreach ($this->toolsResponse['result']['tools'] as $tool) {
            $this->assertSame(
                'object',
                $tool['inputSchema']['type'],
                "Tool {$tool['name']} must have inputSchema type=object"
            );
        }
    }

    #[Test]
    #[TestDox('all tools have required array sorted')]
    public function all_tools_have_required_array_sorted(): void
    {
        foreach ($this->toolsResponse['result']['tools'] as $tool) {
            $required = $tool['inputSchema']['required'] ?? [];
            $sorted = $required;
            sort($sorted);

            $this->assertSame(
                $sorted,
                $required,
                "Tool {$tool['name']} required array must be sorted"
            );
        }
    }

    #[Test]
    #[TestDox('all tools have properties with sorted keys')]
    public function all_tools_have_properties_with_sorted_keys(): void
    {
        foreach ($this->toolsResponse['result']['tools'] as $tool) {
            $properties = array_keys($tool['inputSchema']['properties'] ?? []);
            $sorted = $properties;
            sort($properties, SORT_STRING);
            sort($sorted, SORT_STRING);

            $this->assertSame(
                $sorted,
                $properties,
                "Tool {$tool['name']} properties must be sorted"
            );
        }
    }

    #[Test]
    #[TestDox('docs_search has 25 properties')]
    public function docsSearch_has_25_properties(): void
    {
        $docsTool = null;
        foreach ($this->toolsResponse['result']['tools'] as $tool) {
            if ($tool['name'] === 'docs_search') {
                $docsTool = $tool;
                break;
            }
        }

        $this->assertNotNull($docsTool);
        $this->assertCount(
            25,
            $docsTool['inputSchema']['properties'],
            'docs_search must have exactly 25 properties'
        );
    }

    #[Test]
    #[TestDox('docs_search has query and keywords properties')]
    public function docsSearch_has_query_and_keywords(): void
    {
        $docsTool = null;
        foreach ($this->toolsResponse['result']['tools'] as $tool) {
            if ($tool['name'] === 'docs_search') {
                $docsTool = $tool;
                break;
            }
        }

        $this->assertNotNull($docsTool);
        $this->assertArrayHasKey('query', $docsTool['inputSchema']['properties']);
        $this->assertArrayHasKey('keywords', $docsTool['inputSchema']['properties']);
    }

    #[Test]
    #[TestDox('list_masters has empty inputSchema (agent is server-side)')]
    public function listMasters_has_empty_inputSchema(): void
    {
        $listTool = null;
        foreach ($this->toolsResponse['result']['tools'] as $tool) {
            if ($tool['name'] === 'list_masters') {
                $listTool = $tool;
                break;
            }
        }

        $this->assertNotNull($listTool);
        $this->assertCount(
            0,
            $listTool['inputSchema']['properties'],
            'list_masters must have empty properties (agent resolved server-side via --agent option)'
        );
        $this->assertCount(
            0,
            $listTool['inputSchema']['required'],
            'list_masters must have empty required'
        );
    }

    #[Test]
    #[TestDox('list_masters rejects agent in tool input')]
    public function listMasters_rejects_agent_in_input(): void
    {
        $cwd = getcwd();
        $projectRoot = str_ends_with($cwd, '/cli') ? dirname($cwd) : $cwd;

        $json = shell_exec(
            'echo \'{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"list_masters","arguments":{"agent":"test"}}}\' | BRAIN_TEST_MODE=1 php ' . escapeshellarg($projectRoot . '/cli/bin/brain') . ' mcp:serve 2>/dev/null'
        );

        $this->assertNotFalse($json, 'mcp:serve must return output');

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'mcp:serve must return valid JSON');

        $this->assertArrayHasKey('error', $decoded, 'list_masters must reject agent in input');
        $this->assertSame(-32602, $decoded['error']['code'], 'Error code must be INVALID_INPUT');
    }

    #[Test]
    #[TestDox('diagnose has empty properties and required')]
    public function diagnose_has_empty_properties(): void
    {
        $diagTool = null;
        foreach ($this->toolsResponse['result']['tools'] as $tool) {
            if ($tool['name'] === 'diagnose') {
                $diagTool = $tool;
                break;
            }
        }

        $this->assertNotNull($diagTool);
        $this->assertCount(0, $diagTool['inputSchema']['properties']);
        $this->assertCount(0, $diagTool['inputSchema']['required']);
    }

    #[Test]
    #[TestDox('tool descriptions are product-level without CLI/Artisan mentions')]
    public function tool_descriptions_are_product_level(): void
    {
        $expected = [
            'diagnose' => 'Return structured JSON diagnostics about the Brain environment and runtime configuration. Deterministic output; stderr is always empty.',
            'docs_search' => "Search and analyze this project's documentation (.docs/) and return structured JSON results. Supports rich filters and metadata extraction. Deterministic output; stderr is always empty.",
            'list_masters' => 'List available master sub-agents for the current agent context. Returns JSON. Deterministic output; stderr is always empty.',
        ];

        foreach ($this->toolsResponse['result']['tools'] as $tool) {
            $name = $tool['name'];
            $this->assertArrayHasKey($name, $expected, "Unexpected tool: {$name}");
            $this->assertSame(
                $expected[$name],
                $tool['description'],
                "Tool {$name} description must match expected product-level text"
            );
        }
    }

    #[Test]
    #[TestDox('descriptions contain no internal implementation references')]
    public function descriptions_contain_no_internal_references(): void
    {
        $forbiddenPatterns = [
            '/\bCLI\b/',
            '/\bArtisan\b/',
            '/\bLaravel\b/',
            '/\badapter\b/',
            '/\bthin\b/',
            '/\bwrapper\b/',
            '/\bbridge\b/i',
        ];

        foreach ($this->toolsResponse['result']['tools'] as $tool) {
            foreach ($forbiddenPatterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $tool['description'],
                    "Tool {$tool['name']} description must not mention internal implementation"
                );
            }
        }
    }
}
