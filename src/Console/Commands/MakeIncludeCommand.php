<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Console\Traits\StubGeneratorTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeIncludeCommand extends Command
{
    use StubGeneratorTrait;
    use HelpersTrait;

    protected $signature = 'make:include {name} {--force : Overwrite existing files}';

    protected $description = 'Create a new Include class';

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
        if (! str_ends_with($className, 'Include')) {
            $className .= 'Include';
            $id .= '-include';
        }

        return [
            'file' => "node/Includes/{$directory}{$className}.php",
            'stub' => 'include',
            'replacements' => [
                'namespace' => 'BrainNode\\Includes' . $namespace,
                'className' => $className,
                'purpose' => 'Include for ' . $id,
            ]
        ];
    }
}

