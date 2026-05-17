<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Services\Mcp\McpToolSchema;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class McpServeCommand extends Command
{
    protected $signature = 'mcp:serve
        {--agent= : Agent ID for routing (e.g., claude, codex, gemini, qwen)}
        {--working-dir= : Optional working directory for commands (defaults to current dir)}
    ';

    protected $description = 'Start brain-tools MCP server over stdio (stdin/stdout JSON-RPC)';

    private const VERSION = '1.0.0';

    private const ENV_AGENT_ID = 'BRAIN_AGENT_ID';

    private const ENV_TRACE = 'BRAIN_MCP_SERVE_TRACE';

    private const ENV_TRACE_TICK = 'BRAIN_MCP_SERVE_TRACE_TICK';

    private const TRACE_FILE = 'memory/mcp-serve-trace.log';

    private const TICK_INTERVAL = 10.0;

    private const TICK_INTERVAL_TEST = 0.1;

    private const TOOL_NAMES = ['docs_search', 'diagnose', 'list_masters'];

    private const TOOLS = [
        'docs_search' => [
            'command' => 'docs',
            'description' => 'Search and analyze this project\'s documentation (.docs/) and return structured JSON results. Supports rich filters and metadata extraction. Deterministic output; stderr is always empty.',
        ],
        'diagnose' => [
            'command' => 'diagnose',
            'description' => 'Return structured JSON diagnostics about the Brain environment and runtime configuration. Deterministic output; stderr is always empty.',
        ],
        'list_masters' => [
            'command' => 'list:masters',
            'description' => 'List available master sub-agents for the current agent context. Returns JSON. Deterministic output; stderr is always empty.',
        ],
    ];

    private ?string $effectiveAgentId = null;

    private bool $traceEnabled = false;

    private bool $tickEnabled = false;

    private string $tracePath = '';

    private float $lastTick = 0.0;

    private bool $lifecycleRegistered = false;

    public function handle(): int
    {
        if ($this->option('working-dir')) {
            $dir = $this->option('working-dir');
            if (is_string($dir) && is_dir($dir)) {
                chdir($dir);
            } else {
                $this->writeError(null, -32602, 'INVALID_INPUT', 'Invalid working directory specified.');
                return 1;
            }
        }

        $resolveResult = $this->resolveEffectiveAgentId();
        if ($resolveResult !== null) {
            $this->writeError(null, $resolveResult['code'], $resolveResult['reason'], $resolveResult['message']);
            return 1;
        }

        $this->traceEnabled = getenv(self::ENV_TRACE) === '1' || getenv(self::ENV_TRACE) === 'true';
        $this->tickEnabled = getenv(self::ENV_TRACE_TICK) === '1' || getenv(self::ENV_TRACE_TICK) === 'true';
        $this->tracePath = dirname(__DIR__, 4) . '/' . self::TRACE_FILE;

        $this->registerLifecycleHandlers();

        $stdin = fopen('php://stdin', 'r');
        if ($stdin === false) {
            $this->trace('stdin', false, null, 0, 'fopen failed');
            return 1;
        }

        stream_set_blocking($stdin, false);
        $this->lastTick = microtime(true);

        $this->trace('start', false, null, 0, 'server started');

        while (true) {
            $this->checkTick();

            $read = [$stdin];
            $write = [];
            $except = [];
            $tickInterval = $this->isTestMode() ? self::TICK_INTERVAL_TEST : self::TICK_INTERVAL;
            $timeout = $this->tickEnabled ? $tickInterval : 1.0;

            $ready = @stream_select($read, $write, $except, (int) $timeout, (int) (($timeout - (int) $timeout) * 1000000));

            if ($ready === false) {
                if (feof($stdin)) {
                    $this->trace('stdin_eof', false, null, 0, 'feof after stream_select');
                    break;
                }
                $this->trace('stream_select', false, null, 0, 'error');
                usleep(100000);
                continue;
            }

            if ($ready === 0) {
                continue;
            }

            $line = fgets($stdin);
            if ($line === false) {
                if (feof($stdin)) {
                    $this->trace('stdin_eof', false, null, 0, 'clean eof');
                    break;
                }
                $this->trace('stdin_read', false, null, 0, 'fgets false, not eof');
                usleep(100000);
                continue;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $bytes = strlen($line);
            $request = json_decode($line, true);

            if ($request === null) {
                $id = null;
                $hasId = false;
                $this->trace('<parse_error>', $hasId, $id, $bytes, 'json_decode failed');

                if ($this->canSafelyRespondOnError($line)) {
                    $extractedId = $this->extractIdFromInvalidJson($line);
                    $this->writeError($extractedId, -32700, 'PARSE_ERROR', 'Parse error');
                }
                continue;
            }

            $method = $request['method'] ?? null;
            $id = $request['id'] ?? null;
            $hasId = array_key_exists('id', $request);

            $this->trace((string) $method, $hasId, $id, $bytes, 'received');

            if ($this->isNotification($request)) {
                continue;
            }

            $this->handleRequest($request);
        }

        fclose($stdin);
        $this->trace('exit', false, null, 0, 'clean shutdown');
        return 0;
    }

    private function registerLifecycleHandlers(): void
    {
        if ($this->lifecycleRegistered) {
            return;
        }
        $this->lifecycleRegistered = true;

        if ($this->traceEnabled) {
            register_shutdown_function(function (): void {
                $error = error_get_last();
                if ($error !== null) {
                    $msg = substr($error['message'] ?? 'unknown', 0, 120);
                    $this->trace('shutdown', false, null, 0, "type={$error['type']} msg={$msg}");
                } else {
                    $this->trace('shutdown', false, null, 0, 'normal');
                }
            });

            $prevHandler = set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use (&$prevHandler): bool {
                $msg = substr($errstr, 0, 120);
                $this->trace('error', false, null, 0, "errno={$errno} msg={$msg}");
                if ($prevHandler !== null) {
                    return (bool) $prevHandler($errno, $errstr, $errfile, $errline);
                }
                return false;
            });
            restore_error_handler();
        }

        if (function_exists('pcntl_signal')) {
            $handler = function (int $signal): void {
                $name = match ($signal) {
                    SIGTERM => 'SIGTERM',
                    SIGINT => 'SIGINT',
                    SIGHUP => 'SIGHUP',
                    default => "signal_{$signal}",
                };
                $this->trace('signal', false, null, 0, $name);
                exit(0);
            };

            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
            pcntl_signal(SIGHUP, $handler);
            pcntl_async_signals(true);
        }
    }

    private function checkTick(): void
    {
        if (!$this->tickEnabled) {
            return;
        }

        $now = microtime(true);
        $interval = $this->isTestMode() ? self::TICK_INTERVAL_TEST : self::TICK_INTERVAL;

        if (($now - $this->lastTick) >= $interval) {
            $this->trace('tick', false, null, 0, 'alive');
            $this->lastTick = $now;
        }
    }

    private function isTestMode(): bool
    {
        return getenv('BRAIN_TEST_MODE') === '1' || getenv('BRAIN_TEST_MODE') === 'true';
    }

    private function isNotification(array $request): bool
    {
        return !array_key_exists('id', $request) || $request['id'] === null;
    }

    private function canSafelyRespondOnError(string $line): bool
    {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            return array_key_exists('id', $decoded);
        }

        if (preg_match('/"id"\s*:\s*(?:"[^"]*"|[\d.]+|true|false|null)/', $line)) {
            return true;
        }

        return false;
    }

    private function extractIdFromInvalidJson(string $line): mixed
    {
        if (preg_match('/"id"\s*:\s*("[^"]*"|[\d.]+|true|false|null)/', $line, $matches)) {
            $value = $matches[1];

            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                return substr($value, 1, -1);
            }

            if (is_numeric($value)) {
                return str_contains($value, '.') ? (float) $value : (int) $value;
            }

            return match ($value) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => null,
            };
        }

        return null;
    }

    private function trace(string $method, bool $hasId, mixed $id, int $bytes, string $note): void
    {
        if (!$this->traceEnabled) {
            return;
        }

        $entry = [
            'ts' => date('Y-m-d\TH:i:s.u'),
            'method' => $method,
            'has_id' => $hasId,
            'id' => is_scalar($id) ? $id : null,
            'bytes' => $bytes,
            'note' => $note,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        $tracePath = $this->tracePath ?: dirname(__DIR__, 4) . '/' . self::TRACE_FILE;
        $traceDir = dirname($tracePath);
        if (!is_dir($traceDir)) {
            @mkdir($traceDir, 0755, true);
        }

        @file_put_contents($tracePath, $line, FILE_APPEND | LOCK_EX);
    }

    private function resolveEffectiveAgentId(): ?array
    {
        $option = $this->option('agent');
        if (is_string($option) && $option !== '') {
            $this->effectiveAgentId = $option;
            return null;
        }

        $env = getenv(self::ENV_AGENT_ID);
        if (is_string($env) && $env !== '' && $env !== false) {
            $this->effectiveAgentId = $env;
            return null;
        }

        $testMode = getenv('BRAIN_TEST_MODE') === '1' || getenv('BRAIN_TEST_MODE') === 'true';
        if ($testMode) {
            $this->effectiveAgentId = 'claude';
            return null;
        }

        return [
            'code' => -32600,
            'reason' => 'INVALID_REQUEST',
            'message' => 'Agent ID required. Use --agent option or BRAIN_AGENT_ID environment variable.',
        ];
    }

    private function handleRequest(array $request): void
    {
        if (!isset($request['method']) || !is_string($request['method'])) {
            $this->writeError($request['id'] ?? null, -32600, 'INVALID_REQUEST', 'Invalid Request');
            return;
        }

        $method = $request['method'];
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        $result = match ($method) {
            'initialize' => $this->handleInitialize(),
            'tools/list' => $this->handleToolsList(),
            'tools/call' => $this->handleToolsCall($params, $id),
            default => null,
        };

        if ($result === null) {
            $this->writeError($id, -32601, 'METHOD_NOT_FOUND', 'Method not found');
            return;
        }

        if (isset($result['error'])) {
            $this->writeError($id, $result['error']['code'], $result['error']['reason'], $result['error']['message']);
            return;
        }

        $this->writeResponse($id, $result);
    }

    private function handleInitialize(): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => [
                'name' => 'brain-tools',
                'version' => self::VERSION,
            ],
        ];
    }

    private function handleToolsList(): array
    {
        $tools = [];

        $toolNames = array_keys(self::TOOLS);
        sort($toolNames);

        foreach ($toolNames as $name) {
            $config = self::TOOLS[$name];
            $schema = $this->getToolSchema($name);
            $properties = [];
            $required = [];

            foreach ($schema as $argName => $argConfig) {
                $prop = ['type' => $argConfig['type']];

                if (isset($argConfig['description'])) {
                    $prop['description'] = $argConfig['description'];
                }

                if (isset($argConfig['default'])) {
                    $prop['default'] = $argConfig['default'];
                }

                if (isset($argConfig['enum'])) {
                    $prop['enum'] = $argConfig['enum'];
                }

                $properties[$argName] = $prop;

                if ($argConfig['required'] ?? false) {
                    $required[] = $argName;
                }
            }

            uksort($properties, 'strcasecmp');
            sort($required);

            if (empty($properties)) {
                $properties = new \stdClass();
            }

            $tools[] = [
                'name' => $name,
                'description' => $config['description'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                    'additionalProperties' => false,
                ],
            ];
        }

        return ['tools' => $tools];
    }

    private function getToolSchema(string $toolName): array
    {
        return match ($toolName) {
            'docs_search' => McpToolSchema::docsSearch(),
            'diagnose' => McpToolSchema::diagnose(),
            'list_masters' => [],
            default => [],
        };
    }

    private function handleToolsCall(array $params, mixed $id): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset(self::TOOLS[$toolName])) {
            return [
                'error' => [
                    'code' => -32602,
                    'reason' => 'INVALID_INPUT',
                    'message' => 'Invalid params',
                ],
            ];
        }

        if ($this->isDisabled()) {
            return [
                'error' => [
                    'code' => -32001,
                    'reason' => 'MCP_DISABLED',
                    'message' => 'MCP operations are disabled. Set BRAIN_DISABLE_MCP=false to enable.',
                ],
            ];
        }

        $config = self::TOOLS[$toolName];
        $commandName = $config['command'];

        return $this->executeCommand($commandName, $arguments, $toolName);
    }

    private function isDisabled(): bool
    {
        return getenv('BRAIN_DISABLE_MCP') === 'true' || getenv('BRAIN_DISABLE_MCP') === '1';
    }

    private function executeCommand(string $commandName, array $arguments, string $toolName): array
    {
        $inputArgs = [];

        switch ($commandName) {
            case 'docs':
                $buildResult = $this->buildDocsArgs($arguments);
                if (isset($buildResult['error'])) {
                    return $buildResult;
                }
                $inputArgs = $buildResult['args'];
                break;
            case 'diagnose':
                $validation = $this->validateArgs($arguments, McpToolSchema::diagnose());
                if ($validation !== null) {
                    return $validation;
                }
                $inputArgs = ['--json' => true];
                break;
            case 'list:masters':
                if (!empty($arguments)) {
                    return [
                        'error' => [
                            'code' => -32602,
                            'reason' => 'INVALID_INPUT',
                            'message' => 'One or more arguments are not supported.',
                        ],
                    ];
                }
                if (! $this->effectiveAgentId) {
                    return [
                        'error' => [
                            'code' => -32602,
                            'reason' => 'INVALID_INPUT',
                            'message' => 'Agent ID is required for this tool.',
                        ],
                    ];
                }
                $inputArgs = [
                    'agent' => $this->effectiveAgentId,
                    '--json' => true,
                ];
                break;
            default:
                return [
                    'error' => [
                        'code' => -32602,
                        'reason' => 'TOOL_NOT_IMPLEMENTED',
                        'message' => 'Internal error: command mapping missing.',
                    ],
                ];
        }

        try {
            $output = new BufferedOutput();
            $command = $this->instantiateCommand($commandName);

            if ($command === null) {
                return [
                    'error' => [
                        'code' => -32603,
                        'reason' => 'COMMAND_NOT_RESOLVED',
                        'message' => 'Internal error: command could not be resolved.',
                    ],
                ];
            }

            $input = new ArrayInput($inputArgs);
            $input->setInteractive(false);

            $command->run($input, $output);

            $result = $output->fetch();

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $result,
                    ],
                ],
            ];
        } catch (\Throwable $t) {
            return [
                'error' => [
                    'code' => -32603,
                    'reason' => 'EXECUTION_ERROR',
                    'message' => 'Execution failed. Error: ' . $t->getMessage(),
                ],
            ];
        }
    }

    private function buildDocsArgs(array $arguments): array
    {
        $args = [];
        $validKeys = McpToolSchema::docsSearchOptionNames();

        foreach ($arguments as $key => $value) {
            if (!in_array($key, $validKeys, true)) {
                return [
                    'error' => [
                        'code' => -32602,
                        'reason' => 'INVALID_INPUT',
                        'message' => 'One or more arguments are not supported.',
                    ],
                ];
            }
        }

        if (isset($arguments['keywords']) && is_array($arguments['keywords'])) {
            $args['keywords'] = $arguments['keywords'];
        } elseif (isset($arguments['query']) && is_string($arguments['query'])) {
            $args['keywords'] = [$arguments['query']];
        }

        $optionMap = [
            'limit' => '--limit',
            'exact' => '--exact',
            'strict' => '--strict',
            'headers' => '--headers',
            'stats' => '--stats',
            'code' => '--code',
            'snippets' => '--snippets',
            'links' => '--links',
            'extract-keywords' => '--keywords',
            'matches' => '--matches',
            'undocumented' => '--undocumented',
            'download' => '--download',
            'as' => '--as',
            'update' => '--update',
            'validate' => '--validate',
            'scaffold' => '--scaffold',
            'global' => '--global',
            'freshness' => '--freshness',
            'trust' => '--trust',
            'cache' => '--cache',
            'cache-stats' => '--cache-stats',
            'cache-health' => '--cache-health',
            'clear-cache' => '--clear-cache',
        ];

        $boolOptions = ['strict', 'stats', 'code', 'snippets', 'links', 'extract-keywords', 'matches', 'undocumented', 'update', 'validate', 'global', 'cache-stats', 'cache-health', 'clear-cache'];
        $intOptions = ['limit', 'headers', 'freshness'];
        $enumOptions = [
            'trust' => ['low', 'med', 'high'],
            'cache' => ['on', 'off'],
        ];

        foreach ($optionMap as $argKey => $optionKey) {
            if (!array_key_exists($argKey, $arguments)) {
                continue;
            }

            $value = $arguments[$argKey];

            if (in_array($argKey, $boolOptions, true)) {
                if ($value === true) {
                    $args[$optionKey] = true;
                }
                continue;
            }

            if (in_array($argKey, $intOptions, true)) {
                if (is_int($value)) {
                    $args[$optionKey] = $value;
                }
                continue;
            }

            if (isset($enumOptions[$argKey])) {
                $allowed = $enumOptions[$argKey];
                if (is_string($value) && in_array($value, $allowed, true)) {
                    $args[$optionKey] = $value;
                }
                continue;
            }

            if (is_string($value) && $value !== '') {
                $args[$optionKey] = $value;
            }
        }

        return ['args' => $args];
    }

    private function validateArgs(array $arguments, array $allowedArgs): ?array
    {
        $validKeys = array_keys($allowedArgs);

        foreach ($arguments as $key => $value) {
            if (!in_array($key, $validKeys, true)) {
                return [
                    'error' => [
                        'code' => -32602,
                        'reason' => 'INVALID_INPUT',
                        'message' => 'One or more arguments are not supported.',
                    ],
                ];
            }
        }

        return null;
    }

    private function instantiateCommand(string $name): ?\Illuminate\Console\Command
    {
        $laravel = Container::getInstance();

        if (!$laravel) {
            return null;
        }

        try {
            $command = $laravel->make($this->getCommandClass($name));
            $command->setLaravel($laravel);
            return $command;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getCommandClass(string $name): string
    {
        return match ($name) {
            'docs' => DocsCommand::class,
            'diagnose' => DiagnoseCommand::class,
            'list:masters' => ListMastersCommand::class,
            default => '',
        };
    }

    private function writeResponse(mixed $id, array $result): void
    {
        $response = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        flush();
    }

    private function writeError(mixed $id, int $code, string $reason, string $message): void
    {
        $response = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
                'data' => [
                    'reason' => $reason,
                    'hint' => 'Check input parameters and try again.',
                ],
            ],
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        flush();
    }
}
