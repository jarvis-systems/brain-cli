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
use Illuminate\Console\Command;

/**
 * MCP List Command lists available MCP servers and their allowed tools.
 */
class McpListCommand extends McpCommandAbstract
{
    protected $signature = 'mcp:list
        {--pretty : Pretty print JSON}
    ';

    protected $description = 'List available MCP servers and their allowed tools (policy-aware)';

    /**
     * Handle the command execution.
     * 
     * @return int
     */
    public function handle(): int
    {
        $projectRoot = Brain::projectDirectory();
        $cliPackageDir = Brain::localDirectory();

        $registryResolver = new FileRegistryResolver($projectRoot, $cliPackageDir);
        $externalToolsPolicyResolver = new FileExternalToolsPolicyResolver($projectRoot, $cliPackageDir);
        $policyResolver = new FilePolicyResolver($projectRoot, $cliPackageDir);

        $discoveryService = new McpDiscoveryService(
            $registryResolver,
            $externalToolsPolicyResolver
        );

        $isEnabled = $policyResolver->isEnabled();

        if (!$isEnabled) {
            $this->outputResult([
                'enabled' => false,
                'kill_switch_env' => 'BRAIN_DISABLE_MCP',
                'servers' => [],
                'summary' => [
                    'servers_total' => 0,
                    'servers_enabled' => 0,
                    'tools_allowed_total' => 0,
                ]
            ]);
            return 0;
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
                    // Ignore missing registry for policy validation purposes here 
                }
                (new \BrainCore\Services\McpExternalToolsPolicy\McpExternalToolsPolicyValidator())->validate($policyData, $registryData);
            }

            $data = $discoveryService->listServers();

            $output = array_merge([
                'enabled' => true,
                'kill_switch_env' => 'BRAIN_DISABLE_MCP',
            ], $data);

            $this->outputResult($output);
            return 0;

        } catch (\Throwable $e) {
            $this->outputError($e->getMessage(), 'DISCOVERY_FAILED');
            return 1;
        }
    }
}
