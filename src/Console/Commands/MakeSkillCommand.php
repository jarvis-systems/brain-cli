<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Console\Traits\SelfDevGateTrait;
use BrainCLI\Console\Traits\StubGeneratorTrait;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeSkillCommand extends Command
{
    use StubGeneratorTrait;
    use HelpersTrait;
    use SelfDevGateTrait;

    protected $signature = 'make:skill
        {name : Skill name}
        {--native : Create a native folder skill with SKILL.md}
        {--description= : Native skill description}
        {--force : Overwrite existing files}';

    protected $description = 'Create a new skill class or native skill folder';

    public function handle(): int
    {
        if (! $this->requireSelfDev()) {
            return ERROR;
        }

        if ($this->option('native')) {
            return $this->generateNativeSkill() ? OK : ERROR;
        }

        return $this->generateFile(...$this->generateParameters()) ? OK : ERROR;
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

    protected function generateNativeSkill(): bool
    {
        $this->checkWorkingDir();

        $id = Str::slug((string) $this->argument('name'));

        if ($id === '') {
            $this->components->error('Native skill name must contain at least one alpha-numeric character.');
            return false;
        }

        $description = (string) ($this->option('description') ?: 'Skill for ' . $id);
        $yamlName = json_encode($id, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $yamlDescription = json_encode($description, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $directory = Brain::workingDirectory(['node', 'Skills', $id]);
        $file = $directory . DS . 'SKILL.md';
        $displayFile = to_string(config('brain.dir', '.brain')) . DS . 'node' . DS . 'Skills' . DS . $id . DS . 'SKILL.md';

        if (is_file($file) && ! $this->option('force')) {
            $this->components->warn("File {$displayFile} already exists. Use --force to overwrite.");
            return false;
        }

        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            $this->components->error("Failed to create directory {$directory}.");
            return false;
        }

        $content = <<<MD
---
name: {$yamlName}
description: {$yamlDescription}
---

# {$id}

Use this skill when the task matches the description above. Keep the core workflow concise and move detailed references, scripts, or assets into sibling folders.

MD;

        if (file_put_contents($file, $content) === false) {
            $this->components->error("Failed to write file {$displayFile}.");
            return false;
        }

        $this->components->success("File {$displayFile} created successfully.");
        return true;
    }
}
