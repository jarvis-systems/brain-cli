<?php

declare(strict_types=1);

namespace BrainCLI\Services\Msp;

use BrainCLI\Services\Mcp\BrainMcpBridge;
use BrainCLI\Services\Mcp\McpToolSchema;
use BrainCLI\Services\Mcp\ToolingMode;
use Illuminate\Container\Container;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;

final class BrainToolsProvider implements MspProviderInterface
{
    public const ID = 'brain-tools';

    private const TOOL_DOCS_SEARCH = 'docs_search';
    private const TOOL_DIAGNOSE = 'diagnose';
    private const TOOL_LIST_MASTERS = 'list_masters';

    private ToolingMode $toolingMode;
    private BrainMcpBridge $bridge;

    public function __construct()
    {
        $this->toolingMode = new ToolingMode();
        $this->bridge = new BrainMcpBridge();
    }

    public function id(): string
    {
        return self::ID;
    }

    public function tools(): array
    {
        return [
            self::TOOL_DOCS_SEARCH => [
                'schema' => McpToolSchema::docsSearch(),
            ],
            self::TOOL_DIAGNOSE => [
                'schema' => McpToolSchema::diagnose(),
            ],
            self::TOOL_LIST_MASTERS => [
                'schema' => McpToolSchema::listMasters(),
            ],
        ];
    }

    public function call(string $tool, array $args): array
    {
        if (! isset($this->tools()[$tool])) {
            return $this->error('UNKNOWN_TOOL', 'Tool not found', 'Check available tools');
        }

        if ($this->toolingMode->isEnabled()) {
            return $this->callViaMcp($tool, $args);
        }

        return $this->callDirect($tool, $args);
    }

    private function callViaMcp(string $tool, array $args): array
    {
        try {
            return match ($tool) {
                self::TOOL_DOCS_SEARCH => $this->bridge->docsSearch($args),
                self::TOOL_DIAGNOSE => $this->bridge->diagnose(),
                self::TOOL_LIST_MASTERS => $this->bridge->listMasters($args['agent'] ?? null),
                default => $this->error('UNKNOWN_TOOL', 'Tool not found', 'Check available tools'),
            };
        } catch (\Throwable $e) {
            return $this->error('PROVIDER_ERROR', 'Execution failed', 'Check input and retry');
        }
    }

    private function callDirect(string $tool, array $args): array
    {
        $commandMap = [
            self::TOOL_DOCS_SEARCH => ['class' => \BrainCLI\Console\Commands\DocsCommand::class, 'name' => 'docs'],
            self::TOOL_DIAGNOSE => ['class' => \BrainCLI\Console\Commands\DiagnoseCommand::class, 'name' => 'diagnose'],
            self::TOOL_LIST_MASTERS => ['class' => \BrainCLI\Console\Commands\ListMastersCommand::class, 'name' => 'list:masters'],
        ];

        if (! isset($commandMap[$tool])) {
            return $this->error('UNKNOWN_TOOL', 'Tool not found', 'Check available tools');
        }

        $commandInfo = $commandMap[$tool];
        $command = $this->instantiateCommand($commandInfo['class']);

        if ($command === null) {
            return $this->error('PROVIDER_ERROR', 'Command unavailable', 'Check CLI installation');
        }

        $inputArgs = $this->buildInputArgs($tool, $args);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return $this->error('PROVIDER_ERROR', 'Stream error', 'Check system resources');
        }

        $output = new StreamOutput($stream);
        $input = new ArrayInput($inputArgs);
        $input->setInteractive(false);

        try {
            $command->run($input, $output);
        } catch (\Throwable $e) {
            fclose($stream);
            return $this->error('PROVIDER_ERROR', 'Execution failed', 'Check input and retry');
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        if ($content === false || $content === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : ['raw' => $content];
    }

    private function buildInputArgs(string $tool, array $args): array
    {
        if ($tool === self::TOOL_DOCS_SEARCH) {
            $inputArgs = [];
            if (isset($args['query'])) {
                $inputArgs['keywords'] = [$args['query']];
            } elseif (isset($args['keywords'])) {
                $inputArgs['keywords'] = (array) $args['keywords'];
            }
            foreach (['limit', 'exact', 'strict', 'headers', 'stats', 'code', 'snippets', 'links', 'global'] as $opt) {
                if (isset($args[$opt])) {
                    $inputArgs["--{$opt}"] = $args[$opt];
                }
            }
            $inputArgs['--json'] = true;
            return $inputArgs;
        }

        if ($tool === self::TOOL_LIST_MASTERS) {
            $inputArgs = ['--json' => true];
            if (isset($args['agent'])) {
                $inputArgs['agent'] = $args['agent'];
            }
            return $inputArgs;
        }

        return ['--json' => true];
    }

    private function instantiateCommand(string $class): ?\Illuminate\Console\Command
    {
        $laravel = Container::getInstance();
        if (! $laravel) {
            return null;
        }

        try {
            $command = $laravel->make($class);
            $command->setLaravel($laravel);
            return $command;
        } catch (\Throwable) {
            return null;
        }
    }

    private function error(string $code, string $reason, string $hint): array
    {
        return [
            'ok' => false,
            'error' => [
                'code' => $code,
                'reason' => $reason,
                'message' => 'Operation failed',
                'hint' => $hint,
            ],
        ];
    }
}
