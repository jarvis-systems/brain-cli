<?php

declare(strict_types=1);

namespace BrainCLI\Services\Clients;

use BrainCLI\Abstracts\ClientAbstract;
use BrainCLI\Dto\Compile\AgentInfo;
use BrainCLI\Dto\Compile\CommandInfo;
use BrainCLI\Dto\Compile\Data;
use BrainCLI\Dto\Process\Payload;
use BrainCLI\Enums\Agent;
use BrainCLI\Services\ProcessFactory;
use BrainCLI\Support\Brain;
use Illuminate\Support\Collection;

class OpenCodeClient extends ClientAbstract
{
    /**
     * Puzzle configuration
     *
     * @var array<string, string>
     */
    protected array $compilePuzzle = [
        'agent' => '@{{ value }}',
    ];

    /**
     * Get agent type
     */
    public function agent(): Agent
    {
        return Agent::OPENCODE;
    }

    protected function getFolderParts(): string|array
    {
        return '.opencode';
    }

    protected function getFileParts(): string|array
    {
        return 'AGENTS.md';
    }

    protected function getAgentsFolderParts(): string|array
    {
        return [$this->folder(), 'agent'];
    }

    protected function getCommandsFolderParts(): string|array
    {
        return [$this->folder(), 'command'];
    }

    /**
     * COMPILE METHODS
     * -----------------------------------------------------------------------------
     */

    /**
     * @param  Collection<int, Data>  $mcp
     * @return non-empty-string|array<string, mixed>
     */
    protected function createSettingsContent(Data $brain, Collection $mcp, array|string|null $old): string|array
    {
        $settings = is_array($old) ? $old : [];
        if (isset($brain->meta['model'])) {
            $settings['model'] = $brain->meta['model'];
        }

        $pluginDir = Brain::projectDirectory([$this->folder(), 'plugins']);
        $hookEndFile = Brain::projectDirectory([$this->folder(), 'plugins', 'start_end_hook.js']);
        if (is_dir($pluginDir)) {
            if (is_file($hookEndFile)) {
                unlink($hookEndFile);
            }
        } else {
            mkdir($pluginDir, 0755, true);
        }

        $startUrl = $this->getHookUrl();
        $stopUrl = $this->getHookUrl(true);

        if ($startUrl || $stopUrl) {
            $js = file_get_contents(__DIR__ . '/stubs/start_end_hook.js');
            if ($startUrl) {
                $js = str_replace('{{ START_HOOK_URL }}', $startUrl, $js);
            }
            if ($stopUrl) {
                $js = str_replace('{{ STOP_HOOK_URL }}', $stopUrl, $js);
            }
            file_put_contents($hookEndFile, $js);
        }

        return $settings;
    }

    /**
     * @param  Collection<int, Data>  $mcp
     * @return non-empty-string|array<string, mixed>
     */
    protected function createMcpContent(Collection $mcp, Data $brain, array|string|null $old): string|array
    {
        $settings = is_array($old) ? $old : [];
        $settings['mcp'] = [];
        foreach ($mcp as $file) {
            $server = $file->structure;
            if (is_array($server)) {
                $name = $file->meta['id'] ?? preg_replace('/(.*)-mcp/', '$1', $file->id);
                if ($server['type'] === 'stdio') {
                    $server['type'] = 'local';
                    $server['command'] = [$server['command'], ...$server['args']];
                    unset($server['args']);
                } else {
                    $server['type'] = 'remote';
                }
                $settings['mcp'][$name] = $server;
            }
        }
        return $settings;
    }

    /**
     * @return non-empty-string|array<string, mixed>|false
     */
    protected function createAgentContent(Data $agent, Data $brain, AgentInfo $info): string|array|false
    {
        return $this->generateWithYamlHeader([
            'description' => $info->description,
            'mode' => 'subagent',
            'model' => $info->model,
            'name' => $info->name,
            'temperature' => $info->meta['temperature'] ?? 0.3,
        ], $agent->structure);
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
                //'agent' => 'plane',
                'description' => $info->description,
                'model' => $info->meta['model'] ?? null,
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
            Brain::getEnv('OPENCODE_NPM_PROGRAM_PATH', Brain::getEnv('NPM_PROGRAM_PATH', 'npm')),
            'install', '-g', 'opencode-ai@latest',
        ];

        return $payload
            ->installBehavior($install)
            ->updateBehavior($install)
            ->programBehavior([
                'command' => [Brain::getEnv('OPENCODE_PROGRAM_PATH', 'opencode')],
                'env' => [
                    'OPENCODE_CONFIG' => Brain::projectDirectory($this->settingsFile())
                ],
            ])
            ->resumeBehavior(function (ProcessFactory $factory, string $sessionId) {
                return ['--session', $sessionId];
            })
            ->continueBehavior('--continue')
            ->promptBehavior(fn (ProcessFactory $factory, string $prompt) => ['--prompt', $prompt])
            ->askBehavior(fn (ProcessFactory $factory, string $prompt) => ['run', $prompt])
            ->jsonBehavior(fn (ProcessFactory $factory) => ['--format', 'json'])
            ->yoloBehavior([])
            ->allowToolsBehavior(fn (ProcessFactory $factory, array $tools) => [])
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
            ->noMcpBehavior(function (ProcessFactory $factory) {
                $file = Brain::projectDirectory($this->settingsFile());
                if (is_file($file)) {
                    $oldSettings = json_decode(
                        file_get_contents($file) ?? '{}',
                        true
                    ) ?? [];
                    unset($oldSettings['mcp']);
                    return [
                        'env' => [
                            'OPENCODE_CONFIG' => $this->temporalFile($oldSettings)
                        ],
                    ];
                }
            })
            ->settingsBehavior(function (ProcessFactory $factory, array $settings) {
                return [
                    'env' => [
                        'QWEN_CODE_SYSTEM_SETTINGS_PATH'
                        => $this->temporalFile($settings, $this->settingsFile(), $factory->type->name),
                    ]
                ];
            });
    }

    /**
     * @return array{sessionId: non-empty-string}|null
     */
    protected function processParseOutputInit(ProcessFactory $factory, array $json): array|null
    {
        if (
            isset($json['type']) && isset($json['sessionID'])
            && $json['type'] === 'step_start'
        ) {
            return [
                'sessionId' => $json['sessionID']
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
            isset($json['type']) && isset($json['part']['type'])
            && $json['type'] === 'text' && $json['part']['type'] === 'text'
        ) {
            return [
                'id' => $json['part']['messageID'],
                'content' => $json['part']['text'],
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
            isset($json['type']) && isset($json['part'])
            && $json['type'] === 'step_finish'
            && $json['part']['type'] === 'step-finish'
        ) {
            return [
                'inputTokens' => $json['part']['tokens']['input'] ?? 0,
                'outputTokens' => $json['part']['tokens']['output'] ?? 0,
            ];
        }

        return null;
    }
}
