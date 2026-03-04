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
class McpDescribeCommand extends McpCommandAbstract
{
    protected $signature = 'mcp:describe
        {--server= : The MCP server ID to describe}
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

        if (!$serverId) {
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

        if (!$policyResolver->isEnabled()) {
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
            $policy = $externalToolsPolicyResolver->resolve();
            if ($policy->enabled && $policy->resolvedPath) {
                $policyData = json_decode(file_get_contents($policy->resolvedPath), true, flags: JSON_THROW_ON_ERROR);
                $registryData = null;
                try {
                    $registry = $registryResolver->resolve();
                    if ($registry->resolvedPath) {
                        $registryData = json_decode(file_get_contents($registry->resolvedPath), true, flags: JSON_THROW_ON_ERROR);
                    }
                } catch (\Throwable $e) {
                }
                (new \BrainCore\Services\McpExternalToolsPolicy\McpExternalToolsPolicyValidator())->validate($policyData, $registryData);
            }

            $data = $discoveryService->describeServer($serverId);
            $this->outputResult($data);
            return 0;

        } catch (\Throwable $e) {
            $this->outputResultFromException($e);
            return 1;
        }
    }

    /**
     * Parse structured error message from exception.
     */
    private function outputResultFromException(\Throwable $e): void
    {
        $msg = $e->getMessage();
        preg_match('/code=([^ ]+) reason=([^ ]+) message="([^"]+)"/', $msg, $m);

        if ($m) {
            $this->outputError($m[3], $m[1], $m[2]);
        } else {
            $this->outputError($msg, 'DESCRIBE_FAILED');
        }
    }
}
