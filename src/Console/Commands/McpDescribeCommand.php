<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Support\Brain;
use BrainCore\Contracts\McpToolPolicy\McpToolPolicyResolver;
use BrainCore\Services\McpToolPolicy\FilePolicyResolver;
use BrainCore\Contracts\McpRegistry\McpRegistryResolver;
use BrainCore\Services\McpRegistry\FileRegistryResolver;
use BrainCore\Services\McpExternalToolsPolicy\FileExternalToolsPolicyResolver;
use BrainCore\Services\McpDiscovery\McpDiscoveryService;
use BrainCLI\Services\McpRegistryValidator;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * MCP Describe Command returns metadata for a specific MCP server's allowed tools.
 */
class McpDescribeCommand extends Command
{
    protected $signature = 'mcp:describe
        {--server= : The MCP server ID to describe}
        {--json : Output as JSON (default)}
        {--pretty : Pretty print JSON}
    ';

    protected $description = 'Describe allowed tools for a specific MCP server (policy-aware)';

    /**
     * Handle the command execution.
     * 
     * @return int
     */
    public function handle(): int
    {
        $serverId = $this->option('server');

        if (! $serverId) {
            $this->outputError('Missing required option --server', 'MISSING_ARGUMENT');
            return 1;
        }

        $projectRoot = Brain::projectDirectory();
        $cliPackageDir = Brain::localDirectory();

        $registryResolver = new FileRegistryResolver($projectRoot, $cliPackageDir);
        $externalToolsPolicyResolver = new FileExternalToolsPolicyResolver($projectRoot, $cliPackageDir);
        $policyResolver = new FilePolicyResolver($projectRoot, $cliPackageDir);

        $discoveryService = new McpDiscoveryService(
            $registryResolver,
            $externalToolsPolicyResolver
        );

        if (! $policyResolver->isEnabled()) {
            $this->outputError('MCP operations are disabled via kill-switch.', 'MCP_DISABLED');
            return 1;
        }

        // 1. Validation of registry before describing
        try {
            $registry = $registryResolver->resolve();
            (new McpRegistryValidator())->validate($registry);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'code=MCP_REGISTRY_INVALID') || $e->getMessage() === 'MCP_REGISTRY_MISSING') {
                 $this->outputError($e->getMessage(), 'REGISTRY_VALIDATION_FAILED');
                 return 1;
            }
            throw $e;
        }

        try {
            $data = $discoveryService->describeServer($serverId);
            $this->outputResult($data);
            return 0;

        } catch (\Throwable $e) {
            $code = 'DESCRIBE_FAILED';
            if (str_contains($e->getMessage(), 'code=MCP_SERVER_NOT_FOUND')) $code = 'MCP_SERVER_NOT_FOUND';
            if (str_contains($e->getMessage(), 'code=MCP_SERVER_DISABLED')) $code = 'MCP_SERVER_DISABLED';
            
            $this->outputError($e->getMessage(), $code);
            return 1;
        }
    }

    /**
     * Output the result as JSON.
     */
    private function outputResult(array $output): void
    {
        $jsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($this->option('pretty')) {
            $jsonOptions |= JSON_PRETTY_PRINT;
        }

        $this->line((string) json_encode($output, $jsonOptions));
    }

    /**
     * Output a structured error as JSON.
     */
    private function outputError(string $message, string $code): void
    {
        $output = [
            'enabled' => true,
            'server' => $this->option('server') ?? 'unknown',
            'status' => 'error',
            'error' => [
                'message' => $message,
                'code' => $code,
            ],
        ];
        $this->outputResult($output);
    }
}
