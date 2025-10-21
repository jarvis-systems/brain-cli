<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\StubGeneratorTrait;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;

class InitCommand extends Command
{
    use StubGeneratorTrait;

    protected $signature = 'init {--force : Overwrite existing configuration file}';

    protected $description = 'Initialize Brain Configuration';

    public function handle(): int
    {
        try {
            $this->generate();
        } catch (\Exception $e) {
            $this->components->error($e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function generate(): void
    {
        $version = Brain::version();
        if (! $version) {
            throw new \RuntimeException('Unable to determine Brain version.');
        }
        $schema = config('brain.schema_url');
        if (! $schema) {
            throw new \RuntimeException('Schema URL is not defined in the configuration.');
        }
        $name = 'brain';
        $this->generateFile('brain.yaml', $name, [
            'schema' => tag_replace($schema, compact('version', 'name')),
        ]);
        $name = 'mcp';
        $this->generateFile('mcp.yaml', $name, [
            'schema' => tag_replace($schema, compact('version', 'name')),
        ]);

        $this->generateFile('agents' . DS . '.gitkeep', 'gitkeep');
        $this->generateFile('skills' . DS . '.gitkeep', 'gitkeep');
        $this->generateFile('commands' . DS . '.gitkeep', 'gitkeep');
        $this->generateFile('includes' . DS . '.gitkeep', 'gitkeep');
    }
}

