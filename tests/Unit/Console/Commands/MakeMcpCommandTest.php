<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Tests for MakeMcpCommand source contracts and stub structure.
 *
 * COMMIT 1 scope: shallow source inspection + all 3 stub validations.
 * Deep coverage (marketplace, variables, Process, Credential) deferred to COMMIT 2.
 */
class MakeMcpCommandTest extends TestCase
{
    private string $source;

    private string $stubsDir;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/MakeMcpCommand.php'
        ) ?: '';

        $this->stubsDir = dirname(__DIR__, 4) . '/src/Console/Commands/stubs';
    }

    // ─── Source Inspection ───────────────────────────────────────────

    public function test_command_signature(): void
    {
        $this->assertStringContainsString("'make:mcp", $this->source);
        $this->assertStringContainsString('{name}', $this->source);
        $this->assertStringContainsString('--source=', $this->source);
        $this->assertStringContainsString('--parameters=', $this->source);
        $this->assertStringContainsString('--composer=', $this->source);
        $this->assertStringContainsString('--force', $this->source);
        $this->assertStringContainsString('--http', $this->source);
        $this->assertStringContainsString('--sse', $this->source);
    }

    public function test_command_extends_illuminate_command(): void
    {
        $this->assertStringContainsString('extends Command', $this->source);
    }

    public function test_uses_stub_generator_trait(): void
    {
        $this->assertStringContainsString('use StubGeneratorTrait', $this->source);
    }

    public function test_uses_helpers_trait(): void
    {
        $this->assertStringContainsString('use HelpersTrait', $this->source);
    }

    public function test_uses_self_dev_gate_trait(): void
    {
        $this->assertStringContainsString('use SelfDevGateTrait', $this->source);
    }

    public function test_requires_self_dev_before_execution(): void
    {
        $this->assertStringContainsString('requireSelfDev()', $this->source);
    }

    public function test_handle_returns_int(): void
    {
        $this->assertStringContainsString('public function handle(): int', $this->source);
    }

    public function test_generates_into_mcp_directory(): void
    {
        $this->assertStringContainsString("node/Mcp/", $this->source);
    }

    public function test_namespace_is_brain_node_mcp(): void
    {
        $this->assertStringContainsString("'BrainNode\\\\Mcp'", $this->source);
    }

    public function test_appends_mcp_suffix(): void
    {
        $this->assertStringContainsString("'Mcp'", $this->source);
        $this->assertStringContainsString("str_ends_with(\$className, 'Mcp')", $this->source);
    }

    public function test_supports_three_transport_types(): void
    {
        // Stub selection based on type: mcp.stdio, mcp.http, mcp.sse
        $this->assertStringContainsString("mcp.{", $this->source);

        // Type resolution: --http, --sse, default stdio
        $this->assertStringContainsString("'http'", $this->source);
        $this->assertStringContainsString("'sse'", $this->source);
        $this->assertStringContainsString("'stdio'", $this->source);
    }

    public function test_has_find_in_market_method(): void
    {
        $this->assertStringContainsString('function findInMarket(', $this->source);
    }

    public function test_has_variables_detect_methods(): void
    {
        $this->assertStringContainsString('function variablesDetectArray(', $this->source);
        $this->assertStringContainsString('function variablesDetectString(', $this->source);
    }

    public function test_has_export_with_dynamic_paths_method(): void
    {
        $this->assertStringContainsString('function exportWithDynamicPaths(', $this->source);
    }

    public function test_has_tabs_multiline_method(): void
    {
        $this->assertStringContainsString('function tabsMultiline(', $this->source);
    }

    // ─── Stub Files: STDIO ───────────────────────────────────────────

    public function test_mcp_stdio_stub_file_exists(): void
    {
        $this->assertFileExists($this->stubsDir . '/mcp.stdio.stub');
    }

    public function test_mcp_stdio_stub_extends_stdio_mcp(): void
    {
        $content = file_get_contents($this->stubsDir . '/mcp.stdio.stub') ?: '';

        $this->assertStringContainsString('extends StdioMcp', $content);
        $this->assertStringContainsString('use BrainCore\Mcp\StdioMcp', $content);
    }

    public function test_mcp_stdio_stub_has_required_placeholders(): void
    {
        $content = file_get_contents($this->stubsDir . '/mcp.stdio.stub') ?: '';

        $this->assertStringContainsString('{{ namespace }}', $content);
        $this->assertStringContainsString('{{ className }}', $content);
        $this->assertStringContainsString('{{ mcpId }}', $content);
        $this->assertStringContainsString('{{ source }}', $content);
        $this->assertStringContainsString('{{ parameters }}', $content);
    }

    public function test_mcp_stdio_stub_has_strict_types(): void
    {
        $content = file_get_contents($this->stubsDir . '/mcp.stdio.stub') ?: '';
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    // ─── Stub Files: HTTP ────────────────────────────────────────────

    public function test_mcp_http_stub_file_exists(): void
    {
        $this->assertFileExists($this->stubsDir . '/mcp.http.stub');
    }

    public function test_mcp_http_stub_extends_http_mcp(): void
    {
        $content = file_get_contents($this->stubsDir . '/mcp.http.stub') ?: '';

        $this->assertStringContainsString('extends HttpMcp', $content);
        $this->assertStringContainsString('use BrainCore\Mcp\HttpMcp', $content);
    }

    public function test_mcp_http_stub_has_required_placeholders(): void
    {
        $content = file_get_contents($this->stubsDir . '/mcp.http.stub') ?: '';

        $this->assertStringContainsString('{{ namespace }}', $content);
        $this->assertStringContainsString('{{ className }}', $content);
        $this->assertStringContainsString('{{ mcpId }}', $content);
        $this->assertStringContainsString('{{ source }}', $content);
        $this->assertStringContainsString('{{ parameters }}', $content);
    }

    public function test_mcp_http_stub_has_strict_types(): void
    {
        $content = file_get_contents($this->stubsDir . '/mcp.http.stub') ?: '';
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    // ─── Stub Files: SSE ─────────────────────────────────────────────

    public function test_mcp_sse_stub_file_exists(): void
    {
        $this->assertFileExists($this->stubsDir . '/mcp.sse.stub');
    }

    public function test_mcp_sse_stub_extends_sse_mcp(): void
    {
        $content = file_get_contents($this->stubsDir . '/mcp.sse.stub') ?: '';

        $this->assertStringContainsString('extends SseMcp', $content);
        $this->assertStringContainsString('use BrainCore\Mcp\SseMcp', $content);
    }

    public function test_mcp_sse_stub_has_required_placeholders(): void
    {
        $content = file_get_contents($this->stubsDir . '/mcp.sse.stub') ?: '';

        $this->assertStringContainsString('{{ namespace }}', $content);
        $this->assertStringContainsString('{{ className }}', $content);
        $this->assertStringContainsString('{{ mcpId }}', $content);
        $this->assertStringContainsString('{{ source }}', $content);
        $this->assertStringContainsString('{{ parameters }}', $content);
    }

    public function test_mcp_sse_stub_has_strict_types(): void
    {
        $content = file_get_contents($this->stubsDir . '/mcp.sse.stub') ?: '';
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }
}
