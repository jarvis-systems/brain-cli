<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Console\Traits\SelfDevGateTrait;
use BrainCLI\Console\Traits\StubGeneratorTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeScriptCommand extends Command
{
    use StubGeneratorTrait;
    use HelpersTrait;
    use SelfDevGateTrait;

    protected $signature = 'make:script {name} {--force : Overwrite existing files}';

    protected $description = 'Create a new script class';

    public function handle(): int
    {
        if (! $this->requireSelfDev()) {
            return ERROR;
        }

        $this->checkWorkingDir();

        return $this->generateFile(
            ...$this->generateParameters()
        ) ? OK : ERROR;
    }

    protected function generateParameters(): array
    {
        [$directory, $name, $namespace] = $this->extractInnerPathNameName(
            $this->argument('name')
        );
        $className = Str::studly($name);
        $id = Str::snake($name, '-');
        if (! str_ends_with($className, 'Script')) {
            $className .= 'Script';
        }

        return [
            'file' => "scripts/{$directory}{$className}.php",
            'stub' => 'script',
            'replacements' => [
                'commandId' => $id,
                'signature' => $id,
                'namespace' => 'BrainScripts' . $namespace,
                'className' => $className,
            ]
        ];
    }
}

