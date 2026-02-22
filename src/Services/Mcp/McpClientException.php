<?php

declare(strict_types=1);

namespace BrainCLI\Services\Mcp;

use RuntimeException;

class McpClientException extends RuntimeException
{
    public static function connectionFailed(string $reason): self
    {
        return new self("MCP connection failed: {$reason}");
    }

    public static function handshakeFailed(string $reason): self
    {
        return new self("MCP handshake failed: {$reason}");
    }

    public static function toolCallFailed(string $tool, string $reason): self
    {
        return new self("MCP tool call '{$tool}' failed: {$reason}");
    }

    public static function timeout(int $seconds): self
    {
        return new self("MCP read timeout after {$seconds}s");
    }

    public static function protocolError(string $reason): self
    {
        return new self("MCP protocol error: {$reason}");
    }
}
