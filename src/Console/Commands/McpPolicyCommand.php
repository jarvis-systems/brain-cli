<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCore\Contracts\McpToolPolicy\McpToolPolicyResolver;
use BrainCore\Services\McpToolPolicy\FilePolicyResolver;
use Illuminate\Console\Command;
use RuntimeException;

class McpPolicyCommand extends McpCommandAbstract
{
    protected $signature = 'mcp:policy
        {--diagnostics : Include additional diagnostic flags}
        {--pretty : Pretty print JSON}
    ';

    protected $description = 'Inspect MCP tool policy state (safe, no lists)';

    private const FORBIDDEN_PATTERNS = [
        'docs',
        'compile',
        'make:',
        'token',
        'secret',
        'api_key',
        'bearer',
        'sk-',
        'gsk_',
        'ctx7sk',
    ];

    public function handle(): int
    {
        try {
            $output = $this->buildOutput();

            $this->assertNoForbiddenContent($output);

            $this->outputResult($output);

            return 0;
        } catch (RuntimeException $e) {
            $this->outputError('Policy validation failed: ' . $e->getMessage(), 'POLICY_VALIDATION_FAILED');

            return 1;
        }
    }

    private function buildOutput(): array
    {
        $resolver = $this->createResolver();

        try {
            $policy = $resolver->resolve();
        } catch (RuntimeException $e) {
            return [
                'error' => true,
                'message' => 'Policy validation failed',
                'hint' => 'Run brain docs .docs/architecture/mcp-tool-policy.md for details',
            ];
        }

        $output = [
            'enabled' => $policy->enabled,
            'kill_switch_env' => $policy->killSwitchEnv,
            'resolved_path' => $this->formatPath($policy->resolvedPath),
            'schema_version' => $policy->version,
            'allowed_count' => count($policy->allowed),
            'never_count' => count($policy->never),
            'clients_enabled' => $this->countEnabledClients($policy->clients),
            'has_overrides' => ! empty($policy->clients) || str_contains((string) $policy->resolvedPath, '.brain-config') || str_contains((string) $policy->resolvedPath, '.brain/config'),
            'overlap' => false,
        ];

        if ($this->option('diagnostics')) {
            $output['self_hosting'] = $this->isSelfHosting();
        }

        return $output;
    }

    private function countEnabledClients(array $clients): int
    {
        $count = 0;

        foreach ($clients as $config) {
            if (is_array($config) && ($config['enabled'] ?? false)) {
                $count++;
            }
        }

        return $count;
    }

    private function isSelfHosting(): bool
    {
        $brainPath = getcwd() . '/.brain';

        if (! is_link($brainPath)) {
            return false;
        }

        $target = readlink($brainPath);

        return $target === '.' || $target === getcwd();
    }

    private function assertNoForbiddenContent(array $output): void
    {
        $json = json_encode($output);

        if ($json === false) {
            return;
        }

        $jsonLower = strtolower($json);

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (str_contains($jsonLower, strtolower($pattern))) {
                throw new RuntimeException('Output contains forbidden content — aborting');
            }
        }
    }
}
