<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\StubGeneratorTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeMasterCommand extends Command
{
    use StubGeneratorTrait;

    protected $signature = 'make:master {name} {--force : Overwrite existing files}';

    protected $description = 'Create a new subagent master';

    public function handle(): int
    {
        return $this->generateFile(
            ...$this->generateParameters()
        ) ? OK : ERROR;
    }

    protected function generateParameters(): array
    {
        $name = $this->argument('name');
        $className = Str::studly($name);
        $id = Str::snake($name, '-');
        if (! str_ends_with($className, 'Master')) {
            $className .= 'Master';
            $id .= '-master';
        }

        return [
            'file' => "node/Agents/{$className}.php",
            'stub' => 'agent',
            'replacements' => [
                'namespace' => 'BrainNode\\Agents',
                'className' => $className,
                'agentId' => $id,
                'agentPurpose' => 'Master agent for ' . $id,
            ]
        ];
    }
}

