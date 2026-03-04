<?php

declare(strict_types=1);

namespace BrainCLI\Services\Mcp;

use Illuminate\Container\Container;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;

class ClientToolingRouter
{
    protected ToolingMode $toolingMode;

    public function __construct(
        protected BrainMcpBridge $bridge,
    ) {
        $this->toolingMode = new ToolingMode();
    }

    public function docsSearch(array $arguments): array
    {
        if ($this->toolingMode->isEnabled()) {
            return $this->docsSearchViaMcp($arguments);
        }

        return $this->docsSearchViaLegacy($arguments);
    }

    public function diagnose(): array
    {
        if ($this->toolingMode->isEnabled()) {
            return $this->diagnoseViaMcp();
        }

        return $this->diagnoseViaLegacy();
    }

    public function listMasters(?string $agent = null): array
    {
        if ($this->toolingMode->isEnabled()) {
            return $this->listMastersViaMcp($agent);
        }

        return $this->listMastersViaLegacy($agent);
    }

    protected function docsSearchViaMcp(array $arguments): array
    {
        try {
            return $this->bridge->docsSearch($arguments);
        } catch (RuntimeException $e) {
            return $this->createErrorResponse('search', $e->getMessage());
        }
    }

    protected function diagnoseViaMcp(): array
    {
        try {
            return $this->bridge->diagnose();
        } catch (RuntimeException $e) {
            return $this->createErrorResponse('diagnostics', $e->getMessage());
        }
    }

    protected function listMastersViaMcp(?string $agent): array
    {
        try {
            return $this->bridge->listMasters($agent);
        } catch (RuntimeException $e) {
            return $this->createErrorResponse('masters', $e->getMessage());
        }
    }

    protected function docsSearchViaLegacy(array $arguments): array
    {
        $keywords = [];
        if (isset($arguments['keywords']) && is_array($arguments['keywords'])) {
            $keywords = $arguments['keywords'];
        } elseif (isset($arguments['query']) && is_string($arguments['query'])) {
            $keywords = [$arguments['query']];
        }

        $inputArgs = ['keywords' => $keywords];

        if (isset($arguments['limit']) && is_int($arguments['limit'])) {
            $inputArgs['--limit'] = $arguments['limit'];
        }

        $result = $this->executeDirectCommand('docs', $inputArgs);

        if ($result === null) {
            return $this->createErrorResponse('search', 'Failed to execute docs command');
        }

        $decoded = json_decode($result, true);

        return is_array($decoded) ? $decoded : ['raw' => $result];
    }

    protected function diagnoseViaLegacy(): array
    {
        $result = $this->executeDirectCommand('diagnose', ['--json' => true]);

        if ($result === null) {
            return $this->createErrorResponse('diagnostics', 'Failed to execute diagnose command');
        }

        $decoded = json_decode($result, true);

        return is_array($decoded) ? $decoded : ['raw' => $result];
    }

    protected function listMastersViaLegacy(?string $agent): array
    {
        $inputArgs = [
            'agent' => $agent ?? 'claude',
            '--json' => true,
        ];

        $result = $this->executeDirectCommand('list:masters', $inputArgs);

        if ($result === null) {
            return $this->createErrorResponse('masters', 'Failed to execute list:masters command');
        }

        $decoded = json_decode($result, true);

        return is_array($decoded) ? $decoded : ['raw' => $result];
    }

    protected function executeDirectCommand(string $commandName, array $inputArgs): ?string
    {
        $laravel = Container::getInstance();

        if (! $laravel) {
            return null;
        }

        $commandClass = $this->getCommandClass($commandName);

        if ($commandClass === '') {
            return null;
        }

        try {
            $command = $laravel->make($commandClass);
            $command->setLaravel($laravel);

            $stream = fopen('php://temp', 'r+');

            if ($stream === false) {
                return null;
            }

            $output = new StreamOutput($stream);
            $input = new ArrayInput($inputArgs);
            $input->setInteractive(false);

            $command->run($input, $output);

            rewind($stream);
            $content = stream_get_contents($stream);
            fclose($stream);

            return $content !== false ? $content : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function getCommandClass(string $name): string
    {
        return match ($name) {
            'docs' => \BrainCLI\Console\Commands\DocsCommand::class,
            'diagnose' => \BrainCLI\Console\Commands\DiagnoseCommand::class,
            'list:masters' => \BrainCLI\Console\Commands\ListMastersCommand::class,
            default => '',
        };
    }

    protected function createErrorResponse(string $operation, string $message): array
    {
        return [
            'error' => true,
            'operation' => $operation,
            'message' => 'Operation failed. Please try again.',
            'hint' => 'Check configuration and retry.',
        ];
    }
}
