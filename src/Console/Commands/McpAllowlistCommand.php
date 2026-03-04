<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCore\Contracts\McpToolPolicy\McpToolPolicyResolver;
use BrainCore\Services\McpToolPolicy\FilePolicyResolver;
use Illuminate\Console\Command;
use RuntimeException;

class McpAllowlistCommand extends McpCommandAbstract
{
    protected $signature = 'mcp:allowlist
        {--pretty : Pretty print JSON}
    ';

    protected $description = 'Inspect MCP tool allowlist policy (stable programmatic JSON)';

    private const FORBIDDEN_PATTERNS = [
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
            $this->outputError('Policy resolution failed: ' . $e->getMessage(), 'POLICY_RESOLUTION_FAILED');

            return 1;
        }
    }

    private function buildOutput(): array
    {
        $resolver = $this->createResolver();

        try {
            $policy = $resolver->resolve();
        } catch (RuntimeException $e) {
            throw new RuntimeException('MCP Policy resolution failed', 0, $e);
        }

        $data = $policy->toStableArray();

        // Format path for consistent output across environments
        $data['resolved_path'] = $this->formatPath($policy->resolvedPath);

        return $data;
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
