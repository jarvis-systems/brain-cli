<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCore\Contracts\McpToolPolicy\McpToolPolicyResolver;
use BrainCore\Services\McpToolPolicy\FilePolicyResolver;
use BrainCore\Support\StableJsonTrait;
use Illuminate\Console\Command;
use RuntimeException;

abstract class McpCommandAbstract extends Command
{
    use StableJsonTrait;

    protected function checkPolicy(string $tool): void
    {
        $resolver = $this->createResolver();

        if (!$resolver->isEnabled()) {
            $this->outputError(
                "MCP operations are disabled via kill-switch.",
                "MCP_DISABLED",
                "kill_switch_active",
                "Run: brain mcp:list ; brain mcp:describe --server=<server>"
            );
            throw new \BrainCLI\Exceptions\CommandTerminatedException(1);
        }

        if (!$resolver->isAllowed($tool)) {
            $this->outputError(
                "Requested operation is not allowed by policy.",
                "TOOL_NOT_ALLOWED",
                "policy_blocked",
                "Run: brain mcp:list ; brain mcp:describe --server=<server>"
            );
            throw new \BrainCLI\Exceptions\CommandTerminatedException(1);
        }
    }

    protected function createResolver(): McpToolPolicyResolver
    {
        $projectRoot = $this->detectProjectRoot();
        $cliPackageDir = dirname(__DIR__, 2);

        return new FilePolicyResolver($projectRoot, $cliPackageDir);
    }

    protected function detectProjectRoot(): string
    {
        $dir = getcwd() ?: '.';

        for ($i = 0; $i < 5; $i++) {
            if (
                is_file($dir . '/.brain-config/mcp-tools.allowlist.json')
                || is_file($dir . '/.brain/config/mcp-tools.allowlist.json')
            ) {
                return $dir;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return getcwd() ?: '.';
    }

    protected function formatPath(?string $path): string
    {
        if ($path === null) {
            return 'none';
        }

        $cwd = getcwd();

        if ($cwd !== false && str_starts_with($path, $cwd)) {
            return '.' . substr($path, strlen($cwd));
        }

        $home = getenv('HOME');

        if ($home !== false && str_starts_with($path, $home)) {
            return '~' . substr($path, strlen($home));
        }

        return $path;
    }

    protected function outputResult(array $output): void
    {
        $jsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if ($this->hasOption('pretty') && $this->option('pretty')) {
            $jsonOptions |= JSON_PRETTY_PRINT;
        }

        $isOk = $output['ok'] ?? true;

        $unifiedOutput = [
            'ok' => $isOk,
        ];

        if ($isOk) {
            $unifiedOutput['enabled'] = $output['enabled'] ?? true;
            $unifiedOutput['kill_switch_env'] = $output['kill_switch_env'] ?? 'BRAIN_DISABLE_MCP';
        }

        // Ensure keys are set in top-level output
        foreach (['server', 'tool', 'redactions_applied'] as $key) {
            if (array_key_exists($key, $output)) {
                $unifiedOutput[$key] = $output[$key];
            }
        }

        if (array_key_exists('error', $output)) {
            $unifiedOutput['error'] = $output['error'];
        }

        if (array_key_exists('request_id', $output) && $this->hasOption('trace') && $this->option('trace')) {
            $unifiedOutput['request_id'] = $output['request_id'];
        }

        // Put the rest of the original output under "data" unless it's an error block
        $dataContent = array_diff_key($output, $unifiedOutput);
        if (array_key_exists('request_id', $output) && (!$this->hasOption('trace') || !$this->option('trace'))) {
            unset($dataContent['request_id']);
        }

        if (!empty($dataContent)) {
            $unifiedOutput['data'] = $dataContent;
        }

        ksort($unifiedOutput);
        $this->line((string) json_encode($unifiedOutput, $jsonOptions));
    }

    protected function outputError(string $message, string $code, string $reason = 'cli_error', ?string $hint = null): void
    {
        $serverId = $this->hasOption('server') ? ($this->option('server') ?: 'unknown') : 'unknown';
        $tool = $this->hasOption('tool') ? ($this->option('tool') ?: 'unknown') : 'unknown';

        $rawOutput = [
            'ok' => false,
            'redactions_applied' => false,
            'error' => [
                'code' => $code,
                'reason' => $reason,
                'message' => $message,
                'hint' => $hint ?: "Run: brain mcp:list ; brain mcp:describe --server=<server>"
            ],
        ];

        if ($this->hasOption('server')) {
            $rawOutput['server'] = $this->option('server') ?: 'unknown';
        }
        if ($this->hasOption('tool')) {
            $rawOutput['tool'] = $this->option('tool') ?: 'unknown';
        }

        if ($this->hasOption('trace') && $this->option('trace')) {
            $rawOutput['request_id'] = uniqid('err-');
        }

        $jsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($this->hasOption('pretty') && $this->option('pretty')) {
            $jsonOptions |= JSON_PRETTY_PRINT;
        }

        ksort($rawOutput);
        $this->line((string) json_encode($rawOutput, $jsonOptions));
    }

    protected function outputStableJson(array $data): void
    {
        $this->outputResult($data);
    }
}
