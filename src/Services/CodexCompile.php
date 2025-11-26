<?php

declare(strict_types=1);

namespace BrainCLI\Services;

use BrainCLI\Console\Commands\CompileCommand;
use BrainCLI\Services\Contracts\CompileContract;
use BrainCLI\Support\Brain;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CodexCompile implements CompileContract
{
    const MCP_FILE = '.codex/config.toml';
    const CODEX_FILE = ['AGENTS.md'];
    const CODEX_FOLDER = '.codex';
    const AGENTS_FOLDER = ['.codex', 'agents'];
    const COMMANDS_FOLDER = ['.codex', 'prompts'];
    const SKILLS_FOLDER = ['.codex', 'skills'];

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
            if ($file['namespaceType'] && str_starts_with($file['namespaceType'], 'Agents')) {
                $this->agentFiles[] = $file;
            } elseif ($file['namespaceType'] && str_starts_with($file['namespaceType'], 'Commands')) {
                $this->commandFiles[] = $file;
            } elseif ($file['namespaceType'] && str_starts_with($file['namespaceType'], 'Mcp')) {
                $this->mcpFiles[] = $file;
            } elseif ($file['namespaceType'] && str_starts_with($file['namespaceType'], 'Skills')) {
                $this->skillFiles[] = $file;
            } elseif ($file['namespaceType'] === null && $file['classBasename'] === 'Brain') {
                $this->brainFile = $file;
            }
        });
    }

    public function compile(): bool
    {
        return $this->makeCodexFile()
            && $this->makeConfigFile()
            && $this->makeCommandsFiles();
    }

    public function compiled(): void
    {

    }

    protected function makeCodexFile(): bool
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

    /**
     * Mcp file example:
     * model = "gpt-5.1-codex-max"
     *
     * # <-- Auto-config zone -->
     * [mcp_servers.context7]
     * type = "stdio"
     * command = "npx"
     * args = ["-y", "@upstash/context7-mcp", "--api-key", "ctx7sk-d066fdcb-bd7e-4e10-acf4-522c7621e2c4"]
     *
     * [mcp_servers.laravel-boost]
     * type = "stdio"
     * command = "php"
     * args = ["/Users/xsaven/PhpstormProjects/getorder/customers/artisan", "boost:mcp"]
     *
     * [mcp_servers.sequential-thinking]
     * type = "stdio"
     * command = "npx"
     * args = ["-y", "@modelcontextprotocol/server-sequential-thinking"]
     *
     * [mcp_servers.vector-memory]
     * type = "stdio"
     * command = "uvx"
     * args = ["vector-memory-mcp", "--working-dir", "/Users/xsaven/PhpstormProjects/getorder/customers", "--memory-limit", "2000000"]
     *
     * [mcp_servers.vector-task]
     * type = "stdio"
     * command = "uvx"
     * args = ["vector-task-mcp", "--working-dir", "/Users/xsaven/PhpstormProjects/getorder/customers"]
     *
     * [projects."/Users/xsaven/PhpstormProjects/getorder/customers"]
     * trust_level = "trusted"
     * # <-- End Auto-config zone -->
     *
     * [notice]
     * hide_gpt5_1_migration_prompt = true
     * "hide_gpt-5.1-codex-max_migration_prompt" = true
     */
    protected function makeConfigFile(): bool
    {
        $file = Brain::projectDirectory($this->mcpFile());
        $tomls = [];

        if (isset($this->brainFile['meta']['model'])) {
            $tomls[] = "model = \"{$this->brainFile['meta']['model']}\"";
        }

        foreach ($this->mcpFiles as $mcpFile) {
            $server = $mcpFile['structure'];
            if (is_string($server)) {
                $name = $mcpFile['meta']['id'] ?? preg_replace('/(.*)-mcp/', '$1', $mcpFile['id']);
                $server = preg_replace('/\n\[(.*)]\n/', "\n[mcp_servers.{$name}.$1]\n", $server);
                $tomls[] = "[mcp_servers.{$name}]\n" . $server;
            }
        }

        $toml = implode("\n\n", $tomls);

        $toml = "# <-- Auto-config zone -->\n" . $toml . "\n# <-- End Auto-config zone -->";

        if (is_file($file)) {
            $content = file_get_contents($file);
            if ($content === false) {
                return !!file_put_contents($file, $toml);
            }
            $content = preg_replace('/model = "(.*)"\n*/', '', $content);
            $tomlNew = preg_replace(
                '/# <-- Auto-config zone -->\n(.*)\n# <-- End Auto-config zone -->/ms',
                $toml,
                $content, -1,$count);

            if (! $count) {
                $toml = $content . "\n\n" . $toml;
            } else {
                $toml = $tomlNew;
            }
        }

        return !!file_put_contents($file, $toml);
    }

    protected function makeCommandsFiles(): bool
    {
        File::cleanDirectory(Brain::projectDirectory($this->commandsFolder()));

        foreach ($this->commandFiles as $commandFile) {
            $insidePath = $this->insidePath($commandFile['file'], 'Commands');
            $directory = Brain::projectDirectory($this->commandsFolder());
            if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
                return false;
            }
            $name = ($commandFile['meta']['id'] ?? preg_replace('/(.*)-command/', '$1', $commandFile['id']));
            $filename = ($insidePath ? str_replace(DS, '-', $insidePath) . '-' : '')
                . preg_replace('/(.*)-command/', '$1', $commandFile['id']) . '.md';
            $file = implode(DS, [$directory, $filename]);
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
        return implode(DS, static::CODEX_FILE);
    }

    public function mcpFile(): string
    {
        return static::MCP_FILE;
    }

    public function brainFolder(): string
    {
        return static::CODEX_FOLDER;
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
            Brain::nodeDirectory('Mcp', true) => 'toml'
        ];
    }

    public function compileVariables(): array
    {
        return [];
    }

    public function compileAgentPrefix(): string|array
    {
        return 'mcp__brain__task-drone({{ value }})';
    }

    public function compileStoreVarPrefixPrefix(): string
    {
        return 'var {{ value }}';
    }

    public function run(): array
    {
        return ['codex'];
    }

    public function exit(): void
    {
        // TODO: Implement exit() method.
    }

    public function resume(): array
    {
        return ['codex', 'resume', '--last'];
    }

    public function commandEnv(): array
    {
        return [
            'CODEX_HOME' => Brain::projectDirectory($this->brainFolder())
        ];
    }

    public function update(): array
    {
        return ['npm', 'install', '-g', '@openai/codex@latest'];
    }
}
