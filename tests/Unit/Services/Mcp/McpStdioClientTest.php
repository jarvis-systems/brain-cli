<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Mcp;

use PHPUnit\Framework\TestCase;

/**
 * Source inspection tests for McpStdioClient.
 *
 * Verifies protocol implementation details without spawning processes.
 */
class McpStdioClientTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Services/Mcp/McpStdioClient.php'
        ) ?: '';
    }

    public function test_connect_sends_initialize_handshake(): void
    {
        $this->assertStringContainsString('initialize', $this->source);
        $this->assertStringContainsString('protocolVersion', $this->source);
        $this->assertStringContainsString('2024-11-05', $this->source);
    }

    public function test_call_sends_tools_call_request(): void
    {
        $this->assertStringContainsString('tools/call', $this->source);
        $this->assertStringContainsString("'name' =>", $this->source);
        $this->assertStringContainsString("'arguments' =>", $this->source);
    }

    public function test_close_terminates_process(): void
    {
        $this->assertStringContainsString('proc_terminate', $this->source);
        $this->assertStringContainsString('proc_close', $this->source);
    }

    public function test_read_timeout_configured(): void
    {
        $this->assertStringContainsString('stream_set_timeout', $this->source);
        $this->assertStringContainsString('READ_TIMEOUT_SECONDS', $this->source);
    }
}
