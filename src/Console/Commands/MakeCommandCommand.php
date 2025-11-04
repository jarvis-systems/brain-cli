<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\StubGeneratorTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeCommandCommand extends Command
{
    use StubGeneratorTrait;

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
        [$directory, $name, $namespace] = $this->extractInnerPathNameName();
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

    protected function extractInnerPathNameName(): array
    {
        $name = $this->argument('name');
        $path = str_replace('\\', DS, $name);
        $className = class_basename($name);
        $directory = str_replace($className, '', $path);
        $directory = array_map(function ($directory) {
            return Str::studly($directory);
        }, explode(DS, $directory));
        return [
            implode(DS, $directory),
            $className,
            ($directory ? '\\' . trim(implode('\\', $directory), '\\') : '')
        ];
    }
}

