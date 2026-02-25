<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCore\Contracts\McpToolPolicy\McpToolPolicyResolver;
use BrainCore\Services\McpToolPolicy\FilePolicyResolver;
use Illuminate\Console\Command;
use RuntimeException;

class McpListCommand extends Command
{
    protected $signature = 'mcp:list
        {--json : JSON output (default)}
        {--pretty : Pretty print JSON}
    ';

    protected $description = 'List available MCP servers and their status';

    private const SCHEMA_VERSION = '1.0.0';

    public function handle(): int
    {
        $resolver = $this->createResolver();
        $projectRoot = $this->detectProjectRoot();

        $status = 'ready';
        $servers = [];

        if (! $resolver->isEnabled()) {
            $status = 'disabled';
        } else {
            $servers = $this->discoverServers($projectRoot);
        }

        $output = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'servers' => $servers,
            'summary' => [
                'server_count' => count($servers),
            ],
        ];

        $jsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($this->option('pretty')) {
            $jsonOptions |= JSON_PRETTY_PRINT;
        }

        $this->line((string) json_encode($output, $jsonOptions));

        return 0;
    }

    private function createResolver(): McpToolPolicyResolver
    {
        $projectRoot = $this->detectProjectRoot();
        $cliPackageDir = dirname(__DIR__, 2);

        return new FilePolicyResolver($projectRoot, $cliPackageDir);
    }

    private function detectProjectRoot(): string
    {
        $dir = getcwd() ?: '.';

        for ($i = 0; $i < 5; $i++) {
            if (is_file($dir . '/.brain-config/mcp-tools.allowlist.json')
                || is_file($dir . '/.brain/config/mcp-tools.allowlist.json')
                || is_dir($dir . '/node/Mcp')
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

    private function discoverServers(string $projectRoot): array
    {
        $mcpDir = $projectRoot . '/node/Mcp';
        if (! is_dir($mcpDir)) {
            return [];
        }

        $servers = [];
        $files = glob($mcpDir . '/*.php');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Simple regex to extract Meta ID attribute using single quotes for regex string
            if (preg_match('/#\[Meta\s*\(\s*[\'"]id[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)\]/', $content, $matches)) {
                $servers[] = [
                    'id' => $matches[1],
                    'enabled' => true,
                ];
            }
        }

        // Deterministic sorting by ID ASC
        usort($servers, fn($a, $b) => strcmp($a['id'], $b['id']));

        return $servers;
    }
}
