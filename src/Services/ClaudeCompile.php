<?php

declare(strict_types=1);

namespace BrainCLI\Services;

use BrainCLI\Console\Commands\CompileCommand;
use BrainCLI\Services\Contracts\CompileContract;
use BrainCLI\Support\Brain;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ClaudeCompile implements CompileContract
{
    const MCP_FILE = '.mcp.json';
    const CLAUDE_FILE = ['.claude', 'CLAUDE.md'];
    const CLAUDE_FOLDER = '.claude';
    const AGENTS_FOLDER = ['.claude', 'agents'];
    const COMMANDS_FOLDER = ['.claude', 'commands'];
    const SKILLS_FOLDER = ['.claude', 'skills'];

    /**
     * @var array<array{'id': non-empty-string, 'file': non-empty-string, 'meta': array<string, string>, 'class': class-string<\Bfg\Dto\Dto>, 'namespace': non-empty-string, 'namespaceType': non-empty-string, 'classBasename': non-empty-string, 'format': 'xml'|'json'|'yaml'|'toml', 'structure': string}>
     */
    protected array $agentFiles = [];

    /**
     * @var array<array{'id': non-empty-string, 'file': non-empty-string, 'meta': array<string, string>, 'class': class-string<\Bfg\Dto\Dto>, 'namespace': non-empty-string, 'namespaceType': non-empty-string, 'classBasename': non-empty-string, 'format': 'xml'|'json'|'yaml'|'toml', 'structure': string}>
     */
    protected array $commandFiles = [];

    /**
     * @var array<array{'id': non-empty-string, 'file': non-empty-string, 'meta': array<string, string>, 'class': class-string<\Bfg\Dto\Dto>, 'namespace': non-empty-string, 'namespaceType': non-empty-string, 'classBasename': non-empty-string, 'format': 'xml'|'json'|'yaml'|'toml', 'structure': string}>
     */
    protected array $mcpFiles = [];

    /**
     * @var array<array{'id': non-empty-string, 'file': non-empty-string, 'meta': array<string, string>, 'class': class-string<\Bfg\Dto\Dto>, 'namespace': non-empty-string, 'namespaceType': non-empty-string, 'classBasename': non-empty-string, 'format': 'xml'|'json'|'yaml'|'toml', 'structure': string}>
     */
    protected array $skillFiles = [];

    /**
     * @var array{'id': non-empty-string, 'file': non-empty-string, 'meta': array<string, string>, 'class': class-string<\Bfg\Dto\Dto>, 'namespace': non-empty-string, 'namespaceType': non-empty-string, 'classBasename': non-empty-string, 'format': 'xml'|'json'|'yaml'|'toml', 'structure': string}
     */
    protected array $brainFile = [];

    /**
     * @param  Collection<int, array{'id': non-empty-string, 'file': non-empty-string, 'meta': array<string, string>, 'class': class-string<\Bfg\Dto\Dto>, 'namespace': non-empty-string, 'namespaceType': non-empty-string, 'classBasename': non-empty-string, 'format': 'xml'|'json'|'yaml'|'toml', 'structure': string}>  $files
     * @return void
     */
    public function boot(Collection $files): void
    {
        $files->map(function (array $file) {
            if ($file['namespaceType'] === 'Agents') {
                $this->agentFiles[] = $file;
            } elseif ($file['namespaceType'] === 'Commands') {
                $this->commandFiles[] = $file;
            } elseif ($file['namespaceType'] === 'Mcp') {
                $this->mcpFiles[] = $file;
            } elseif ($file['namespaceType'] === 'Skills') {
                $this->skillFiles[] = $file;
            } elseif ($file['namespaceType'] === null && $file['classBasename'] === 'Brain') {
                $this->brainFile = $file;
            }
        });
    }

    public function compile(): bool
    {
        return $this->makeClaudeFile()
            && $this->makeMcpFile()
            && $this->makeAgentsFiles()
            && $this->makeCommandsFiles()
            && $this->makeSkillsFiles();
    }

    protected function makeClaudeFile(): bool
    {
        if (
            !is_dir($dir = Brain::projectDirectory($this->brainFolder()))
            && !mkdir($dir, 0755, true)
        ) {
            return false;
        }

        return !!file_put_contents(
            Brain::projectDirectory($this->brainFile()),
            $this->brainFile['structure']
        );
    }

    protected function makeMcpFile(): bool
    {
        $file = Brain::projectDirectory($this->mcpFile());
        $json = ['mcpServers' => []];

        foreach ($this->mcpFiles as $mcpFile) {
            $server = $mcpFile['structure'];
            if (is_array($server)) {
                $name = $mcpFile['meta']['id'] ?? preg_replace('/(.*)-mcp/', '$1', $mcpFile['id']);
                $json['mcpServers'][$name] = $server;
            }
        }

        return !!file_put_contents($file,
            json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function makeAgentsFiles(): bool
    {
        File::cleanDirectory(Brain::projectDirectory($this->agentsFolder()));

        foreach ($this->agentFiles as $agentFile) {
            $insidePath = $this->insidePath($agentFile['file'], 'Agents');
            $directory = Brain::projectDirectory([$this->agentsFolder(), $insidePath]);
            if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
                return false;
            }
            $filename = ($agentFile['meta']['id'] ?? $agentFile['id']).'.md';
            $file = implode(DS, [$directory, $filename]);
            $model = $agentFile['meta']['model'] ?? 'sonnet';
            $color = $agentFile['meta']['color'] ?? 'blue';
            $name = $agentFile['meta']['id'] ?? $agentFile['id'];
            $description = $agentFile['meta']['description'] ?? '';
            $structure = <<<MD
---
name: $name
description: "$description"
model: $model
color: $color
---

{$agentFile['structure']}
MD;

            if (!file_put_contents($file, $structure)) {
                return false;
            }
        }
        return true;
    }

    protected function makeCommandsFiles(): bool
    {
        File::cleanDirectory(Brain::projectDirectory($this->commandsFolder()));

        foreach ($this->commandFiles as $commandFile) {
            $insidePath = $this->insidePath($commandFile['file'], 'Commands');
            $directory = Brain::projectDirectory([$this->commandsFolder(), $insidePath]);
            if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
                return false;
            }
            $filename = preg_replace('/(.*)-command/', '$1', $commandFile['id']).'.md';
            $file = implode(DS, [$directory, $filename]);
            $name = $commandFile['meta']['id'] ?? $commandFile['id'];
            $description = $commandFile['meta']['description'] ?? '';
            $structure = <<<MD
---
name: $name
description: "$description"
---

{$commandFile['structure']}
MD;

            if (!file_put_contents($file, $structure)) {
                return false;
            }
        }
        return true;
    }

    protected function makeSkillsFiles(): bool
    {
        return true;
    }

    protected function insidePath(string $file, string $from): string
    {
        $path = trim(to_string(str_replace([
            Brain::nodeDirectory($from, true),
            Brain::nodeDirectory($from),
            DS.basename($file)
        ], '', $file)), DS);

        $path = array_map(function ($part) {
            return Str::snake($part, '-');
        }, explode(DS, $path));

        return implode(DS, $path);
    }

    public function brainFile(): string
    {
        return implode(DS, static::CLAUDE_FILE);
    }

    public function mcpFile(): string
    {
        return static::MCP_FILE;
    }

    public function brainFolder(): string
    {
        return static::CLAUDE_FOLDER;
    }

    public function agentsFolder(): string
    {
        return implode(DS, static::AGENTS_FOLDER);
    }

    public function commandsFolder(): string
    {
        return implode(DS, static::COMMANDS_FOLDER);
    }

    public function skillsFolder(): string
    {
        return implode(DS, static::SKILLS_FOLDER);
    }

    /**
     * Set unique formats for folders
     *
     * @return array<non-empty-string, non-empty-string>
     */
    public function formats(): array
    {
        return [
            Brain::nodeDirectory('Mcp') => 'json'
        ];
    }
}
