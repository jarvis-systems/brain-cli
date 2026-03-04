<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Support\Brain;
use BrainCore\Contracts\McpToolPolicy\McpToolPolicyResolver;
use BrainCore\Services\McpToolPolicy\FilePolicyResolver;
use BrainCore\Contracts\McpRegistry\McpRegistryResolver;
use BrainCore\Services\McpRegistry\FileRegistryResolver;
use BrainCore\Contracts\McpExternalToolsPolicy\McpExternalToolsPolicyResolver;
use BrainCore\Services\McpExternalToolsPolicy\FileExternalToolsPolicyResolver;
use Illuminate\Console\Command;

/**
 * MCP Guardrails Command provides a runtime governance snapshot.
 */
class McpGuardrailsCommand extends McpCommandAbstract
{
    protected $signature = 'mcp:guardrails
        {--pretty : Pretty print JSON}
    ';

    protected $description = 'Inspect current MCP governance guardrails (runtime snapshot)';

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

        $isEnabled = $policyResolver->isEnabled();
        $budget = \BrainCore\Services\McpCall\McpCallBudget::create($projectRoot);

        $output = [
            'enabled' => $isEnabled,
            'kill_switch_env' => 'BRAIN_DISABLE_MCP',
            'call_budget' => [
                'limit' => $budget->getLimit(),
                'remaining' => $budget->getRemaining(),
                'budget_type' => 'logical',
                'storage_path' => $this->formatPath($budget->getStoragePath()),
            ],
            'registry' => [
                'resolved_path' => 'none',
                'servers_enabled' => 0,
            ],
            'external_tools_policy' => [
                'resolved_path' => 'none',
                'servers_with_rules' => 0,
            ],
            'tools_policy' => [
                'resolved_path' => 'none',
                'schema_version' => 'unknown',
            ],
        ];

        try {
            $registry = $registryResolver->resolve();
            $output['registry']['resolved_path'] = $this->formatPath($registry->resolvedPath);
            $output['registry']['servers_enabled'] = count(array_filter($registry->servers, fn($s) => $s['enabled']));
        } catch (\Throwable) {
            // Silently skip if missing or invalid for the snapshot
        }

        try {
            $extPolicy = $externalToolsPolicyResolver->resolve();
            $output['external_tools_policy']['resolved_path'] = $this->formatPath($extPolicy->resolvedPath);
            $output['external_tools_policy']['servers_with_rules'] = count($extPolicy->servers);
        } catch (\Throwable) {
            // Silently skip
        }

        try {
            $policy = $policyResolver->resolve();
            $output['tools_policy']['resolved_path'] = $this->formatPath($policy->resolvedPath);
            $output['tools_policy']['schema_version'] = $policy->version;
        } catch (\Throwable) {
            // Silently skip
        }

        $this->outputResult($output);

        return 0;
    }
}
