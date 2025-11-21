<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Console\Traits\StubGeneratorTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeCommandCommand extends Command
{
    use StubGeneratorTrait;
    use HelpersTrait;

    protected $signature = 'make:command {name} {--force : Overwrite existing files}';

    protected $description = 'Create a new command class';

    public function handle(): int
    {
        return $this->generateFile(
            ...$this->generateParameters()
        ) ? OK : ERROR;
    }

    protected function generateParameters(): array
    {
        $this->checkWorkingDir();

        [$directory, $name, $namespace] = $this->extractInnerPathNameName(
            $this->argument('name')
        );
        $className = Str::studly($name);
        $id = Str::snake($name, '-');
        if (! str_ends_with($className, 'Command')) {
            $className .= 'Command';
        }

        return [
            'file' => "node/Commands/{$directory}{$className}.php",
            'stub' => 'command',
            'replacements' => [
                'commandId' => $id,
                'namespace' => 'BrainNode\\Commands' . $namespace,
                'className' => $className,
                'purpose' => 'Command for ' . $id,
            ]
        ];
    }
}

