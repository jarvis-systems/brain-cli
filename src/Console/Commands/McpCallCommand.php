<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Support\Brain;
use BrainCore\Contracts\McpCall\McpCallRequest;
use BrainCore\Services\McpCall\McpCallExecutor;
use BrainCore\Services\McpRegistry\FileRegistryResolver;
use BrainCore\Services\McpExternalToolsPolicy\FileExternalToolsPolicyResolver;
use BrainCLI\Services\McpRegistryValidator;
use BrainCLI\Exceptions\CommandTerminatedException;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * MCP Call Command allows executing MCP server tools through Brain.
 */
class McpCallCommand extends Command
{
    protected $signature = 'mcp:call
        {--server= : The MCP server ID (e.g., context7, sequential-thinking)}
        {--tool= : The name of the tool to execute}
        {--input= : The tool input as a JSON string (default: "{}")}
        {--json : Output as JSON (default)}
        {--pretty : Pretty print the JSON output}
    ';

    protected $description = 'Call an MCP server tool through Brain (gated, safe)';

    /**
     * Handle the command execution.
     * 
     * @return int
     */
    public function handle(): int
    {
        $serverId = $this->option('server');
        $tool = $this->option('tool');
        $inputRaw = $this->option('input') ?? '{}';

        if (! $serverId || ! $tool) {
            $this->outputError('Missing required options --server and --tool', 'MISSING_ARGUMENTS');
            return 1;
        }

        try {
            $input = json_decode($inputRaw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->outputError('Invalid JSON in --input: ' . $e->getMessage(), 'INVALID_INPUT');
            return 1;
        }

        $projectRoot = Brain::projectDirectory();
        $cliPackageDir = Brain::localDirectory();

        $registryResolver = new FileRegistryResolver($projectRoot, $cliPackageDir);
        $policyResolver = new FileExternalToolsPolicyResolver($projectRoot, $cliPackageDir);
        
        // 1. Validation before execution
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

        $executor = new McpCallExecutor($registryResolver, $policyResolver, $projectRoot);

        try {
            $request = new McpCallRequest($serverId, $tool, $input);
            $result = $executor->execute($request);
            
            $this->outputResult($result->toStableArray());
            
            return $result->ok ? 0 : 1;

        } catch (\Throwable $e) {
            $this->outputError($e->getMessage(), 'EXECUTION_ERROR');
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
            'ok' => false,
            'server' => $this->option('server') ?? 'unknown',
            'tool' => $this->option('tool') ?? 'unknown',
            'error' => [
                'message' => $message,
                'code' => $code,
                'reason' => 'cli_error',
                'hint' => 'Check command arguments or registry state.'
            ],
        ];
        $this->outputResult($output);
    }
}
