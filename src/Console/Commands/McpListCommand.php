<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Support\Brain;
use BrainCore\Attributes\Meta;
use BrainCore\Contracts\McpToolPolicy\McpToolPolicyResolver;
use BrainCore\Services\McpToolPolicy\FilePolicyResolver;
use BrainCore\Architectures\McpArchitecture;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class McpListCommand extends Command
{
    protected $signature = 'mcp:list
        {--json : JSON output (default)}
        {--pretty : Pretty print JSON}
    ';

    protected $description = 'List available MCP servers and their status';

    private const SCHEMA_VERSION = '1.0.0';

    /**
     * Handle the command execution.
     * 
     * @return int
     */
    public function handle(): int
    {
        $resolver = $this->createResolver();

        $status = 'ready';
        $servers = [];

        if (! $resolver->isEnabled()) {
            $status = 'disabled';
        } else {
            try {
                $servers = $this->discoverServers();
            } catch (RuntimeException $e) {
                // Return structured error in JSON if discovery fails (e.g. missing Meta ID)
                $this->outputError($e->getMessage(), 'DISCOVERY_FAILED');
                return 1;
            }
        }

        $output = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'servers' => $servers,
            'summary' => [
                'server_count' => count($servers),
            ],
        ];

        $this->outputJson($output);

        return 0;
    }

    /**
     * Create the MCP tool policy resolver.
     */
    private function createResolver(): McpToolPolicyResolver
    {
        $projectRoot = Brain::projectDirectory();
        $cliPackageDir = Brain::localDirectory();

        return new FilePolicyResolver($projectRoot, $cliPackageDir);
    }

    /**
     * Discover MCP servers using reflection.
     * 
     * @return array
     */
    private function discoverServers(): array
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

            // We assume the standard namespace for discovery
            $className = 'BrainNode\\Mcp\\' . $file->getBasename('.php');

            // Hardened approach: Ensure root autoloader is available
            if (! class_exists($className)) {
                $this->ensureRootAutoloader();
            }

            // Fallback: If still not found, include it once if it's within the project node directory
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
                'enabled' => $this->isServerEnabled($className, $id),
            ];
        }

        // Deterministic sorting by ID ASC
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
            // Check if this autoloader is already loaded by comparing realpaths
            // But usually require_once is enough.
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
    private function isServerEnabled(string $className, string $id): bool
    {
        // Built-in/Core servers are always enabled if MCP is enabled globally
        $builtIn = ['vector-task', 'vector-memory', 'sequential-thinking', 'context7'];
        
        if (in_array($id, $builtIn, true)) {
            return true;
        }

        // External/Optional servers check
        if (method_exists($className, 'disableByDefault') && $className::disableByDefault()) {
            return false;
        }

        return true;
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
