<?php

declare(strict_types=1);

namespace BrainCLI\Services\Mcp;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Bridge for calling Brain MCP tools from CLI clients.
 *
 * When BRAIN_CLIENT_MCP=1, clients can use this bridge to call
 * docs_search, diagnose, and list_masters via JSON-RPC stdio.
 */
class BrainMcpBridge
{
    public const ENV_ENABLED = 'BRAIN_CLIENT_MCP';

    private const PHP_BINARY = 'php';

    private const MCP_SERVE_CMD = 'cli/bin/brain';

    private const METHOD_TOOLS_CALL = 'tools/call';

    private const TOOL_DOCS_SEARCH = 'docs_search';

    private const TOOL_DIAGNOSE = 'diagnose';

    private const TOOL_LIST_MASTERS = 'list_masters';

    private int $requestId = 0;

    /**
     * Check if MCP bridge is enabled via environment.
     */
    public static function isEnabled(): bool
    {
        return getenv(self::ENV_ENABLED) === '1'
            || ($_ENV[self::ENV_ENABLED] ?? '') === '1'
            || ($_SERVER[self::ENV_ENABLED] ?? '') === '1';
    }

    /**
     * Search Brain documentation via MCP docs_search tool.
     *
     * @param  array<string, mixed>  $arguments  Tool arguments (query, limit, etc.)
     * @return array<string, mixed> Decoded JSON response
     *
     * @throws RuntimeException If MCP call fails
     */
    public function docsSearch(array $arguments): array
    {
        return $this->callTool(self::TOOL_DOCS_SEARCH, $arguments);
    }

    /**
     * Get Brain environment diagnostics via MCP diagnose tool.
     *
     * @return array<string, mixed> Decoded JSON response
     *
     * @throws RuntimeException If MCP call fails
     */
    public function diagnose(): array
    {
        return $this->callTool(self::TOOL_DIAGNOSE, []);
    }

    /**
     * List available subagent masters via MCP list_masters tool.
     *
     * Note: Agent filtering is determined by the server's effectiveAgentId (via --agent or BRAIN_AGENT_ID).
     *
     * @return array<string, mixed> Decoded JSON response
     *
     * @throws RuntimeException If MCP call fails
     */
    public function listMasters(?string $agent = null): array
    {
        return $this->callTool(self::TOOL_LIST_MASTERS, []);
    }

    /**
     * Call an MCP tool and return the decoded result.
     *
     * @param  string  $toolName  MCP tool name
     * @param  array<string, mixed>  $arguments  Tool arguments
     * @return array<string, mixed> Decoded result
     *
     * @throws RuntimeException If call fails or stderr is not empty
     */
    private function callTool(string $toolName, array $arguments): array
    {
        $request = [
            'jsonrpc' => '2.0',
            'id' => ++$this->requestId,
            'method' => self::METHOD_TOOLS_CALL,
            'params' => [
                'name' => $toolName,
                'arguments' => (object) $arguments,
            ],
        ];

        $requestJson = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $projectRoot = dirname(__DIR__, 4);
        $brainPath = $projectRoot . '/' . self::MCP_SERVE_CMD;

        $process = new Process(
            [self::PHP_BINARY, $brainPath, 'mcp:serve'],
            $projectRoot
        );

        $process->setEnv(['BRAIN_TEST_MODE' => '1']);
        $process->setInput($requestJson);
        $process->setTimeout(60);
        $process->run();

        $stderr = $process->getErrorOutput();

        if (strlen($stderr) > 0) {
            throw new RuntimeException(
                "MCP bridge stderr hygiene violation: {$stderr}"
            );
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "MCP bridge process failed: {$process->getStatus()}"
            );
        }

        $output = $process->getOutput();
        $lines = explode("\n", trim($output));
        $lastLine = end($lines);

        $response = json_decode($lastLine, true);

        if (! is_array($response)) {
            throw new RuntimeException(
                'MCP bridge returned invalid JSON response'
            );
        }

        if (isset($response['error'])) {
            $message = $response['error']['message'] ?? 'Unknown MCP error';
            throw new RuntimeException("MCP tool error: {$message}");
        }

        $content = $response['result']['content'] ?? null;

        if (! is_array($content) || ! isset($content[0]['text'])) {
            return [];
        }

        $text = $content[0]['text'];

        if ($text === '') {
            return [];
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            return ['raw' => $text];
        }

        return $decoded;
    }
}
