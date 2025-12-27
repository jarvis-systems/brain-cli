<?php

declare(strict_types=1);

namespace BrainCLI\Services\Clients;

use BrainCLI\Abstracts\ClientAbstract;
use BrainCLI\Dto\Compile\CommandInfo;
use BrainCLI\Dto\Compile\Data;
use BrainCLI\Dto\Process\Payload;
use BrainCLI\Enums\Agent;
use BrainCLI\Services\ProcessFactory;
use BrainCLI\Support\Brain;
use Illuminate\Support\Collection;

class CodexClient extends ClientAbstract
{
    /**
     * MCP file format
     */
    protected string $compileMcpFormat = 'toml';

    /**
     * Puzzle configuration
     *
     * @var array<string, string>
     */
    protected array $compilePuzzle = [
        'variable' => 'var {{ value }}',
    ];

    /**
     * System prompt file append mode
     */
    protected bool $temporalSystemAppend = false;

    /**
     * Get agent type
     */
    public function agent(): Agent
    {
        return Agent::CODEX;
    }

    protected function getFolderParts(): string|array
    {
        return '.codex';
    }

    protected function getFileParts(): string|array
    {
        return 'AGENTS.md';
    }

    protected function getSettingsFileParts(): string|array
    {
        return [$this->folder(), 'config.toml'];
    }

    protected function getCommandsFolderParts(): string|array
    {
        return [$this->folder(), 'prompts'];
    }

    /**
     * COMPILE METHODS
     * -----------------------------------------------------------------------------
     */

    /**
     * Create brain content.
     */
    protected function createBrainContent(Data $brain, string|null $old): string
    {
        return "# Instruction in XML format\n\n"
            . parent::createBrainContent($brain, $old);
    }

    /**
     * @param  Collection<int, Data>  $mcp
     * @return non-empty-string|array<string, mixed>
     */
    protected function createMcpContent(Collection $mcp, Data $brain, array|string|null $old): string|array
    {
        $old = is_string($old) ? $old : '';

        $tomls = [];

        if (isset($brain->meta['model'])) {
            $tomls[] = "model = \"{$brain->meta['model']}\"";
        }

        foreach ($mcp as $mcpFile) {
            $server = $mcpFile->structure;
            if (is_string($server)) {
                $name = $mcpFile->meta['id'] ?? preg_replace('/(.*)-mcp/', '$1', $mcpFile->id);
                $server = preg_replace('/\n\[(.*)]\n/', "\n[mcp_servers.{$name}.$1]\n", $server);
                $tomls[] = "[mcp_servers.{$name}]\n" . $server;
            }
        }

        $toml = implode("\n\n", $tomls);

        $toml = "# <-- Auto-config zone -->\n" . $toml . "\n# <-- End Auto-config zone -->";

        if ($old !== '') {
            $old = preg_replace('/model = "(.*)"\n*/', '', $old);
            $tomlNew = preg_replace(
                '/# <-- Auto-config zone -->\n(.*)\n# <-- End Auto-config zone -->/ms',
                $toml,
                $old, -1,$count);

            if (! $count) {
                $toml = $old . "\n\n" . $toml;
            } else {
                $toml = $tomlNew;
            }
        }

        return $toml;
    }

    /**
     * @return non-empty-string|array{file: non-empty-string, content: non-empty-string}|false
     */
    protected function createCommandContent(Data $command, Data $brain, CommandInfo $info): string|array|false
    {
        return [
            'file' => ($info->insidePath ? str_replace(DS, '-', $info->insidePath) . '-' : '')
                . $info->filename . '.md',
            'content' => $this->generateWithYamlHeader([
                'name' => $info->name,
                'description' => $info->description,
            ], $command->structure),
        ];
    }

    /**
     * PROCESS METHODS
     * -----------------------------------------------------------------------------
     */

    /**
     * Process payload creation
     */
    protected function processPayload(Payload $payload): Payload
    {
        $install = [
            Brain::getEnv('CODEX_NPM_PROGRAM_PATH', Brain::getEnv('NPM_PROGRAM_PATH', 'npm')),
            'install', '-g', '@openai/codex@latest'
        ];

        return $payload
            ->installBehavior($install)
            ->updateBehavior($install)
            ->programBehavior(fn () => $this->processProgramBehavior())
            ->resumeBehavior(function (ProcessFactory $factory, string $sessionId) {
                return ['resume', $sessionId];
            })
            ->continueBehavior(['resume', '--last'])
            ->askBehavior(fn (ProcessFactory $factory, string $prompt) => ['exec', $prompt])
            ->jsonBehavior('--json')
            ->yoloBehavior('--dangerously-bypass-approvals-and-sandbox')
            ->allowToolsBehavior(fn (ProcessFactory $factory, array $tools) => [
                // Todo: implement tool allowance when supported
            ])
            ->modelBehavior(fn (ProcessFactory $factory, string $model) => [
                '--model', $model,
            ])
            ->systemBehavior(function (ProcessFactory $factory, string $systemPrompt) {
                $this->temporalReplaceFile($this->file(), $systemPrompt);
            })
            ->systemAppendBehavior(function (ProcessFactory $factory, string $systemPrompt) {
                $this->temporalAppendFile($this->file(), $systemPrompt);
            })
            ->schemaBehavior(function (ProcessFactory $factory, array $schema) {
                $rules = $this->generateRulesOfSchema($schema);
                return $this->temporalAppendFile($this->file(), $rules);
            })
            ->noMcpBehavior(['-c', 'mcp_servers={}'])
            ->settingsBehavior(function (ProcessFactory $factory, array $settings) {
                $args = [];
                foreach ($settings as $key => $value) {
                    if (is_array($value)) {
                        $value = $this->transformArrayToConfigLine($value);
                    } else {
                        $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                    $args[] = '-c';
                    $args[] = "$key=$value";
                }
                return $args;
            });
    }

    protected function transformArrayToConfigLine(array $config): string
    {
        $return = [];

        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $value = $this->transformArrayToConfigLine($value);
            } else {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $return[] = "$key = $value";
        }

        return "{" . implode(", ", $return) . "}";
    }

    protected function processProgramBehavior(): array
    {
        return [
            'command' => [Brain::getEnv('CODEX_PROGRAM_PATH', 'codex'), '--search'],
            'env' => [
                'CODEX_HOME' => Brain::projectDirectory($this->folder())
            ],
        ];
    }

    /**
     * @return array{sessionId: non-empty-string}|null
     */
    protected function processParseOutputInit(ProcessFactory $factory, array $json): array|null
    {
        if (
            isset($json['type']) && $json['type'] === 'thread.started'
        ) {
            return [
                'sessionId' => $json['thread_id'],
            ];
        }

        return null;
    }

    /**
     * @return array{id: non-empty-string, content: non-empty-string}|null
     */
    protected function processParseOutputMessage(ProcessFactory $factory, array $json): array|null
    {
        if (
            isset($json['type'], $json['item']['type'], $json['item']['text'])
            && $json['type'] === 'item.completed' && $json['item']['type'] === 'agent_message'
            && $json['item']['text']
        ) {
            return [
                'id' => $json['item']['id'] ?? null,
                'content' => $json['item']['text'],
            ];
        }

        return null;
    }

    /**
     * @return array{inputTokens: int, outputTokens: int}|null
     */
    protected function processParseOutputResult(ProcessFactory $factory, array $json): array|null
    {
        if (
            isset($json['type']) && $json['type'] === 'turn.completed'
        ) {
            return [
                'inputTokens' => $json['usage']['input_tokens'] ?? 0,
                'outputTokens' => $json['usage']['output_tokens'] ?? 0,
            ];
        }

        return null;
    }
}
