<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Support\Brain;
use BrainCore\Contracts\McpCall\McpCallRequest;
use BrainCore\Services\McpCall\McpCallExecutor;
use BrainCore\Services\McpCall\McpInputValidator;
use BrainCore\Services\McpRegistry\FileRegistryResolver;
use BrainCore\Services\McpExternalToolsPolicy\FileExternalToolsPolicyResolver;
use BrainCore\Services\McpToolPolicy\FilePolicyResolver;
use BrainCLI\Services\McpRegistryValidator;
use BrainCLI\Exceptions\CommandTerminatedException;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * MCP Call Command allows executing MCP server tools through Brain.
 */
class McpCallCommand extends McpCommandAbstract
{
    protected $signature = 'mcp:call
        {--server= : The MCP server ID (e.g., context7, sequential-thinking)}
        {--tool= : The name of the tool to execute}
        {--input= : The tool input as a JSON string (default: "{}")}
        {--pretty : Pretty print the JSON output}
        {--trace : Include deterministic request_id and redaction status}
        {--retries=0 : Number of retries for transport-level failures (0..3)}
        {--dry-run : Return the resolved command without executing it}
    ';

    protected $description = 'Call an MCP server tool through Brain (gated, safe)';

    /**
     * Handle the command execution.
     * 
     * @return int
     */
    public function handle(): int
    {
        $serverId = $this->option('server');
        $tool = $this->option('tool');
        $inputRaw = $this->option('input') ?? '{}';
        $trace = $this->option('trace');
        $retries = (int) $this->option('retries');
        $dryRun = $this->option('dry-run');

        if (!$serverId || !$tool) {
            $this->outputError('Missing required options --server and --tool', 'MISSING_ARGUMENTS');
            return 1;
        }

        $projectRoot = Brain::projectDirectory();
        $cliPackageDir = Brain::localDirectory();

        $registryResolver = new FileRegistryResolver($projectRoot, $cliPackageDir);
        $policyResolver = new FileExternalToolsPolicyResolver($projectRoot, $cliPackageDir);
        $toolPolicyResolver = new FilePolicyResolver($projectRoot, $cliPackageDir);

        if (!$toolPolicyResolver->isEnabled()) {
            $this->outputError('MCP operations are disabled via kill-switch.', 'MCP_DISABLED');
            return 1;
        }

        if ($serverId === 'mock-echo' && getenv('BRAIN_TEST_MODE') !== '1') {
            $this->outputError('Mock-echo server is only available in test mode.', 'MCP_CALL_BLOCKED', 'test_server_not_available');
            return 1;
        }

        try {
            $input = json_decode($inputRaw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->outputError('Input must be valid JSON.', 'MCP_CALL_INVALID_INPUT', 'invalid_json', 'Run: brain mcp:list ; brain mcp:describe --server=<server>');
            return 1;
        }

        // 1. Validation before execution
        try {
            $registry = $registryResolver->resolve();
            (new McpRegistryValidator())->validate($registry);

            $policy = $policyResolver->resolve();
            if ($policy->enabled && $policy->resolvedPath) {
                $policyData = json_decode(file_get_contents($policy->resolvedPath), true, flags: JSON_THROW_ON_ERROR);
                $registryData = null;
                if ($registry->resolvedPath) {
                    $registryData = json_decode(file_get_contents($registry->resolvedPath), true, flags: JSON_THROW_ON_ERROR);
                }
                (new \BrainCore\Services\McpExternalToolsPolicy\McpExternalToolsPolicyValidator())->validate($policyData, $registryData);
            }

            $discoveryService = new \BrainCore\Services\McpDiscovery\McpDiscoveryService($registryResolver, $policyResolver);

            // 2. Schema preflight validation
            (new McpInputValidator($discoveryService))->validate($serverId, $tool, $input);

        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'code=MCP_REGISTRY_INVALID') || $e->getMessage() === 'MCP_REGISTRY_MISSING' || str_contains($e->getMessage(), 'code=MCP_POLICY_INVALID')) {
                $this->outputError($e->getMessage(), 'VALIDATION_FAILED');
                return 1;
            }

            if (str_contains($e->getMessage(), 'code=MCP_CALL_INVALID_INPUT')) {
                // Preflight validation failed
                $this->outputResultFromException($e, $serverId, $tool);
                return 1;
            }
            throw $e;
        }

        $executor = new McpCallExecutor(
            $registryResolver,
            $policyResolver,
            $toolPolicyResolver,
            $projectRoot,
            \BrainCore\Services\McpCall\McpCallBudget::create($projectRoot),
            new \BrainCore\Services\McpCall\McpCallRetryPolicy($retries),
            new \BrainCore\Services\McpCall\ErrorNormalizer()
        );

        try {
            $request = new McpCallRequest($serverId, $tool, $input);
            $result = $executor->execute($request, $trace, $dryRun);

            if (is_array($result)) {
                $this->outputResult($result);
                return 0;
            }

            $this->outputResult($result->toStableArray());

            return $result->ok ? 0 : 1;

        } catch (\Throwable $e) {
            $debugMode = getenv('BRAIN_MCP_DEBUG') === '1' || getenv('BRAIN_DEBUG_MCP') === '1';
            $debug = null;
            if ($debugMode) {
                $verboseMode = getenv('BRAIN_MCP_DEBUG_VERBOSE') === '1';
                $debug = [
                    'exception_class' => get_class($e),
                    'normalized_reason' => 'execution_exception',
                ];
                if ($verboseMode) {
                    $debug['message'] = \BrainCore\Services\McpCall\McpRedactor::redactString($e->getMessage());
                    $debug['stack'] = \BrainCore\Services\McpCall\McpRedactor::redactString($e->getTraceAsString());
                }
            }

            $output = [
                'ok' => false,
                'server' => $serverId,
                'tool' => $tool,
                'redactions_applied' => false,
                'error' => [
                    'code' => 'EXECUTION_ERROR',
                    'reason' => 'internal_error',
                    'message' => 'An internal execution error occurred.',
                    'hint' => 'Run: brain mcp:list ; brain mcp:describe --server=<server>',
                ],
            ];

            if ($debug !== null) {
                $output['error']['debug'] = $debug;
            }

            $this->outputResult($output);
            return 1;
        }
    }

    /**
     * Parse structured error message from exception.
     */
    private function outputResultFromException(\RuntimeException $e, string $serverId, string $tool): void
    {
        $msg = $e->getMessage();
        preg_match('/code=([^ ]+) reason=([^ ]+) message="([^"]+)" hint="([^"]+)"/', $msg, $m);

        if ($m) {
            $this->outputError($m[3], $m[1], $m[2], "Run: brain mcp:list ; brain mcp:describe --server=<server>");
        } else {
            $debugMode = getenv('BRAIN_MCP_DEBUG') === '1' || getenv('BRAIN_DEBUG_MCP') === '1';
            $debug = null;
            if ($debugMode) {
                $verboseMode = getenv('BRAIN_MCP_DEBUG_VERBOSE') === '1';
                $debug = [
                    'exception_class' => get_class($e),
                    'normalized_reason' => 'validation_exception',
                ];
                if ($verboseMode) {
                    $debug['message'] = \BrainCore\Services\McpCall\McpRedactor::redactString($e->getMessage());
                    $debug['stack'] = \BrainCore\Services\McpCall\McpRedactor::redactString($e->getTraceAsString());
                }
            }

            $output = [
                'ok' => false,
                'server' => $serverId,
                'tool' => $tool,
                'redactions_applied' => false,
                'error' => [
                    'code' => 'UNKNOWN_VALIDATION_ERROR',
                    'reason' => 'internal_validation_error',
                    'message' => 'An internal validation error occurred.',
                    'hint' => 'Run: brain mcp:list ; brain mcp:describe --server=<server>',
                ],
            ];

            if ($debug !== null) {
                $output['error']['debug'] = $debug;
            }

            $this->outputResult($output);
        }
    }
}
