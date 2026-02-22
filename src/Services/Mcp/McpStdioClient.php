<?php

declare(strict_types=1);

namespace BrainCLI\Services\Mcp;

/**
 * Lightweight JSON-RPC 2.0 client over stdio pipes.
 *
 * Spawns an MCP server process via proc_open() and communicates
 * using newline-delimited JSON on stdin/stdout.
 */
class McpStdioClient
{
    protected const PROTOCOL_VERSION = '2024-11-05';

    protected const READ_TIMEOUT_SECONDS = 30;

    /** @var resource|null */
    protected $process = null;

    /** @var resource|null */
    protected $stdin = null;

    /** @var resource|null */
    protected $stdout = null;

    protected int $requestId = 0;

    /**
     * @param  string  $command  Executable to spawn (e.g. 'uvx')
     * @param  list<string>  $args  Arguments for the command
     * @param  string|null  $cwd  Working directory for the subprocess
     */
    public function __construct(
        protected string $command,
        protected array $args = [],
        protected ?string $cwd = null,
    ) {
    }

    /**
     * Spawn the MCP server process and perform the initialize handshake.
     *
     * @throws McpClientException
     */
    public function connect(): void
    {
        $cmd = array_merge([$this->command], $this->args);

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'r'], // stdout
            2 => ['pipe', 'w'], // stderr (discard)
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $this->cwd);

        if (! is_resource($process)) {
            throw McpClientException::connectionFailed(
                "Failed to spawn: {$this->command} " . implode(' ', $this->args)
            );
        }

        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];

        // Configure read timeout
        stream_set_timeout($this->stdout, self::READ_TIMEOUT_SECONDS);

        // Close stderr — we don't need it
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        $this->handshake();
    }

    /**
     * Call an MCP tool and return the parsed result.
     *
     * @param  string  $toolName  MCP tool name (e.g. 'search_memories')
     * @param  array<string, mixed>  $arguments  Tool arguments
     * @return array<string, mixed> Parsed tool result
     *
     * @throws McpClientException
     */
    public function call(string $toolName, array $arguments = []): array
    {
        $response = $this->sendRequest('tools/call', [
            'name' => $toolName,
            'arguments' => (object) $arguments,
        ]);

        if (isset($response['error'])) {
            throw McpClientException::toolCallFailed(
                $toolName,
                $response['error']['message'] ?? 'Unknown error'
            );
        }

        $content = $response['result']['content'] ?? [];

        if (empty($content)) {
            return [];
        }

        // MCP tools return content as array of {type, text} blocks
        $text = $content[0]['text'] ?? '';

        if ($text === '') {
            return [];
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            return ['raw' => $text];
        }

        return $decoded;
    }

    /**
     * Close pipes and terminate the subprocess.
     */
    public function close(): void
    {
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
            $this->stdin = null;
        }

        if (is_resource($this->stdout)) {
            fclose($this->stdout);
            $this->stdout = null;
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    /**
     * Perform MCP initialize handshake.
     *
     * @throws McpClientException
     */
    protected function handshake(): void
    {
        $response = $this->sendRequest('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'brain-cli',
                'version' => '1.0.0',
            ],
        ]);

        if (isset($response['error'])) {
            throw McpClientException::handshakeFailed(
                $response['error']['message'] ?? 'Unknown error'
            );
        }

        $serverVersion = $response['result']['protocolVersion'] ?? null;

        if ($serverVersion === null) {
            throw McpClientException::handshakeFailed('Server did not return protocolVersion');
        }

        // Send initialized notification (no response expected)
        $this->sendNotification('notifications/initialized');
    }

    /**
     * Send a JSON-RPC request and wait for the matching response.
     *
     * @param  string  $method  JSON-RPC method
     * @param  array<string, mixed>  $params  Method parameters
     * @return array<string, mixed> Parsed response
     *
     * @throws McpClientException
     */
    protected function sendRequest(string $method, array $params): array
    {
        $id = ++$this->requestId;

        $message = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ];

        $this->writeLine(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $this->readResponse($id);
    }

    /**
     * Send a JSON-RPC notification (no response expected).
     *
     * @param  string  $method  JSON-RPC method
     * @param  array<string, mixed>  $params  Method parameters
     */
    protected function sendNotification(string $method, array $params = []): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => $method,
        ];

        if (! empty($params)) {
            $message['params'] = $params;
        }

        $this->writeLine(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Read lines from stdout until a response matching the given ID is found.
     *
     * Skips notifications (messages without an 'id' field).
     *
     * @param  int  $id  Expected response ID
     * @return array<string, mixed> Parsed response
     *
     * @throws McpClientException
     */
    protected function readResponse(int $id): array
    {
        while (true) {
            $line = $this->readLine();
            $data = json_decode($line, true);

            if (! is_array($data)) {
                continue;
            }

            // Skip notifications (no id field)
            if (! isset($data['id'])) {
                continue;
            }

            if ($data['id'] === $id) {
                return $data;
            }
        }
    }

    /**
     * Write a line to the subprocess stdin.
     *
     * @throws McpClientException
     */
    protected function writeLine(string $data): void
    {
        if (! is_resource($this->stdin)) {
            throw McpClientException::connectionFailed('stdin pipe is closed');
        }

        $written = fwrite($this->stdin, $data . "\n");

        if ($written === false) {
            throw McpClientException::connectionFailed('Failed to write to stdin');
        }

        fflush($this->stdin);
    }

    /**
     * Read a line from the subprocess stdout.
     *
     * @throws McpClientException
     */
    protected function readLine(): string
    {
        if (! is_resource($this->stdout)) {
            throw McpClientException::connectionFailed('stdout pipe is closed');
        }

        $line = fgets($this->stdout);

        if ($line === false) {
            $meta = stream_get_meta_data($this->stdout);

            if ($meta['timed_out'] ?? false) {
                throw McpClientException::timeout(self::READ_TIMEOUT_SECONDS);
            }

            throw McpClientException::connectionFailed('Unexpected end of stdout');
        }

        return trim($line);
    }
}
