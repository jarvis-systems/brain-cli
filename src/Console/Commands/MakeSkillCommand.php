<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Console\Traits\StubGeneratorTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeSkillCommand extends Command
{
    use StubGeneratorTrait;
    use HelpersTrait;

    protected $signature = 'make:skill {name} {--force : Overwrite existing files}';

    protected $description = 'Create a new skill class';

    public function handle(): int
    {
        return $this->generateFile(
            ...$this->generateParameters()
        ) ? OK : ERROR;
    }

    protected function generateParameters(): array
    {
        $this->checkWorkingDir();
        $name = $this->argument('name');
        $className = Str::studly($name);
        $id = Str::snake($name, '-');
        if (! str_ends_with($className, 'Skill')) {
            $className .= 'Skill';
            $id .= '-skill';
        }

        return [
            'file' => "node/Skills/{$className}.php",
            'stub' => 'skill',
            'replacements' => [
                'namespace' => 'BrainNode\\Skills',
                'className' => $className,
                'purpose' => 'Skill for ' . $id,
            ]
        ];
    }
}

