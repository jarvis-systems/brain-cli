<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Support\Brain;
use BrainCore\Attributes\Meta;
use BrainCore\Contracts\McpToolPolicy\McpToolPolicyResolver;
use BrainCore\Services\McpToolPolicy\FilePolicyResolver;
use BrainCore\Contracts\McpRegistry\McpRegistryResolver;
use BrainCore\Services\McpRegistry\FileRegistryResolver;
use BrainCore\Architectures\McpArchitecture;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class McpListCommand extends Command
{
    protected $signature = 'mcp:list
        {--json : JSON output (default)}
        {--pretty : Pretty print JSON}
        {--scan : Perform runtime reflection scan (discovery-only, non-canonical)}
    ';

    protected $description = 'List available MCP servers and their status (registry-driven)';

    private const SCHEMA_VERSION = '1.0.0';

    /**
     * Handle the command execution.
     * 
     * @return int
     */
    public function handle(): int
    {
        $policyResolver = $this->createPolicyResolver();
        $registryResolver = $this->createRegistryResolver();

        $output = [
            'enabled' => $policyResolver->isEnabled(),
            'kill_switch_env' => 'BRAIN_DISABLE_MCP',
            'resolved_registry_path' => 'none',
            'servers' => [],
            'summary' => ['total' => 0, 'enabled' => 0],
            'schema_version' => self::SCHEMA_VERSION,
        ];

        if (! $output['enabled']) {
            $this->outputJson($output);
            return 0;
        }

        try {
            if ($this->option('scan')) {
                $servers = $this->discoverServersByScan();
            } else {
                $registry = $registryResolver->resolve();
                $output['resolved_registry_path'] = $this->formatPath($registry->resolvedPath);
                $servers = $this->formatRegistryServers($registry->servers);
            }

            $output['servers'] = $servers;
            $output['summary'] = [
                'total' => count($servers),
                'enabled' => count(array_filter($servers, fn($s) => $s['enabled'])),
            ];

        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'MCP_REGISTRY_MISSING') {
                $this->outputError('MCP registry file not found', 'MCP_REGISTRY_MISSING');
            } else {
                $this->outputError($e->getMessage(), 'DISCOVERY_FAILED');
            }
            return 1;
        }

        $this->outputJson($output);

        return 0;
    }

    /**
     * Create the MCP tool policy resolver.
     */
    private function createPolicyResolver(): McpToolPolicyResolver
    {
        $projectRoot = Brain::projectDirectory();
        $cliPackageDir = Brain::localDirectory();

        return new FilePolicyResolver($projectRoot, $cliPackageDir);
    }

    /**
     * Create the MCP registry resolver.
     */
    private function createRegistryResolver(): McpRegistryResolver
    {
        $projectRoot = Brain::projectDirectory();
        $cliPackageDir = Brain::localDirectory();

        return new FileRegistryResolver($projectRoot, $cliPackageDir);
    }

    /**
     * Format registry servers for output.
     */
    private function formatRegistryServers(array $registryServers): array
    {
        $servers = array_map(function ($s) {
            return [
                'id' => $s['id'],
                'enabled' => $s['enabled'],
            ];
        }, $registryServers);

        // Deterministic sorting by ID ASC
        usort($servers, fn($a, $b) => strcmp($a['id'], $b['id']));

        return $servers;
    }

    /**
     * Discover MCP servers using reflection (legacy scan mode).
     * 
     * @return array
     */
    private function discoverServersByScan(): array
    {
        $mcpDir = Brain::nodeDirectory('Mcp');
        
        if (! is_dir($mcpDir)) {
            return [];
        }

        $servers = [];
        $files = File::allFiles($mcpDir);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = 'BrainNode\\Mcp\\' . $file->getBasename('.php');

            if (! class_exists($className)) {
                $this->ensureRootAutoloader();
            }

            if (! class_exists($className)) {
                @include_once $file->getRealPath();
            }

            if (! class_exists($className)) {
                continue;
            }

            if (! is_subclass_of($className, McpArchitecture::class)) {
                continue;
            }

            $id = $this->extractServerId($className);
            
            $servers[] = [
                'id' => $id,
                'enabled' => $this->isServerEnabledByClass($className, $id),
            ];
        }

        usort($servers, fn($a, $b) => strcmp($a['id'], $b['id']));

        return $servers;
    }

    /**
     * Ensure the root autoloader is loaded.
     */
    private function ensureRootAutoloader(): void
    {
        $projectRoot = Brain::projectDirectory();
        $rootAutoloader = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        
        if (is_file($rootAutoloader)) {
            require_once $rootAutoloader;
        }
    }

    /**
     * Extract the server ID from the class using reflection.
     */
    private function extractServerId(string $className): string
    {
        try {
            $ref = new \ReflectionClass($className);
            $attributes = $ref->getAttributes(Meta::class);
            
            foreach ($attributes as $attribute) {
                /** @var Meta $meta */
                $meta = $attribute->newInstance();
                if ($meta->name === 'id') {
                    return $meta->getText();
                }
            }
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to reflect MCP class {$className}: " . $e->getMessage());
        }

        throw new RuntimeException("MCP class {$className} is missing #[Meta('id', ...)] attribute");
    }

    /**
     * Determine if a server is enabled based on its class definition.
     */
    private function isServerEnabledByClass(string $className, string $id): bool
    {
        $builtIn = ['vector-task', 'vector-memory', 'sequential-thinking', 'context7'];
        
        if (in_array($id, $builtIn, true)) {
            return true;
        }

        if (method_exists($className, 'disableByDefault') && $className::disableByDefault()) {
            return false;
        }

        return true;
    }

    /**
     * Format path for consistent output.
     */
    private function formatPath(?string $path): string
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

    /**
     * Output the data as JSON.
     */
    private function outputJson(array $output): void
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
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'error',
            'error' => [
                'message' => $message,
                'code' => $code,
            ],
        ];
        $this->outputJson($output);
    }
}
