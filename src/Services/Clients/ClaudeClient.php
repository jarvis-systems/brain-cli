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

class ClaudeClient extends ClientAbstract
{
    /**
     * Puzzle configuration
     *
     * @var array<string, string>
     */
    protected array $compilePuzzle = [
        'agent' => '@agent-{{ value }}',
    ];

    /**
     * Get agent type
     */
    public function agent(): Agent
    {
        return Agent::CLAUDE;
    }

    protected function getFolderParts(): string|array
    {
        return '.claude';
    }

    protected function getFileParts(): string|array
    {
        return [$this->folder(), 'CLAUDE.md'];
    }

    protected function getMcpFileParts(): string|array
    {
        return [$this->folder(), '.mcp.json'];
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
        return $settings;
    }

    /**
     * @return non-empty-string|array<string, mixed>|false
     */
    protected function createAgentContent(Data $agent, Data $brain, AgentInfo $info): string|array|false
    {
        if ($agent->classBasename === 'ExploreMaster') {
            return false;
        }

        return $this->generateWithYamlHeader([
            'name' => $info->name,
            'description' => $info->description,
            'model' => $info->model,
            'color' => $info->color,
        ], $agent->structure);
    }

    /**
     * @return non-empty-string|array{file: non-empty-string, content: non-empty-string}|false
     */
    protected function createCommandContent(Data $command, Data $brain, CommandInfo $info): string|array|false
    {
        return $this->generateWithYamlHeader([
            'name' => $info->name,
            'description' => $info->description,
        ], $command->structure);
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
            Brain::getEnv('CLAUDE_NPM_PROGRAM_PATH', Brain::getEnv('NPM_PROGRAM_PATH', 'npm')),
            'install', '-g', '@anthropic-ai/claude-code'
        ];

        return $payload
            ->installBehavior($install)
            ->updateBehavior($install)
            ->programBehavior(Brain::getEnv('CLAUDE_PROGRAM_PATH', 'claude'))
            ->resumeBehavior(function (ProcessFactory $factory, string $sessionId) {
                return ['--resume', $sessionId];
            })
            ->continueBehavior('--continue')
            ->askBehavior(fn (ProcessFactory $factory, string $prompt) => [$prompt, '--print'])
            ->jsonBehavior(['--output-format', 'stream-json', '--verbose'])
            ->schemaBehavior(function (ProcessFactory $factory, array $schema) {
                $rules = $this->generateRulesOfSchema($schema);
                if ($factory->reflection->isUsed('system')) {
                    return $factory->reflection->mapCommand(function (string $value, int $key, string|null $option) use ($rules) {
                        if ($option === '--system-prompt') {
                            return $value . "\n\n" . $rules;
                        }
                        return $value;
                    });
                }
                return $this->temporalAppendFile($this->file(), $rules);
            })
            ->yoloBehavior('--dangerously-skip-permissions')
            ->allowToolsBehavior(fn (ProcessFactory $factory, array $tools) => [
                '--allowedTools', implode(' ', $tools),
            ])
            ->modelBehavior(fn (ProcessFactory $factory, string $model) => [
                '--model', $model,
            ])
            ->systemBehavior(function (ProcessFactory $factory, string $systemPrompt) {
                if (str_starts_with($systemPrompt, '@')) {
                    $file = substr($systemPrompt, 1);
                    $systemPrompt = file_get_contents($file);
                }
                return [
                    '--system-prompt', $systemPrompt,
                ];
            })
            ->systemAppendBehavior(function (ProcessFactory $factory, string $systemPrompt) {
                if (str_starts_with($systemPrompt, '@')) {
                    $file = substr($systemPrompt, 1);
                    $systemPrompt = file_get_contents($file);
                }
                $this->temporalAppendFile($this->file(), $systemPrompt);
            })
            ->noMcpBehavior(function (ProcessFactory $factory) {
                $factory->reflection->validatedUsed('ask');
                return ['--tools', "''"];
            })
            ->settingsBehavior(function (ProcessFactory $factory, array $settings) {
                if ($factory->reflection->hasCommand('--settings')) {
                    return $factory->reflection->mapCommand(function (string $value, int $key, string|null $option) use ($settings) {
                        if ($option === '--settings') {
                            return json_encode(array_merge(
                                json_decode($value, true),
                                $settings,
                            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        }
                        return $value;
                    });
                }
                return [
                    '--settings', json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            });
    }

    /**
     * @return array{sessionId: non-empty-string}|null
     */
    protected function processParseOutputInit(ProcessFactory $factory, array $json): array|null
    {
        if (
            isset($json['type']) && isset($json['subtype'])
            && $json['type'] === 'system' && $json['subtype'] === 'init'
        ) {
            return [
                'sessionId' => $json['session_id']
            ];
        }

        return null;
    }

    /**
     * @return array{id: non-empty-string, content: non-empty-string|array}|null
     */
    protected function processParseOutputMessage(ProcessFactory $factory, array $json): array|null
    {
        if (
            isset($json['type'], $json['message']['type'], $json['message']['content'][0]['text'])
            && $json['type'] === 'assistant' && $json['message']['type'] === 'message'
            && $json['message']['content'][0]['text']
        ) {
            return [
                'id' => $json['message']['id'] ?? null,
                'content' => $json['message']['content'][0]['text'],
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
            isset($json['type']) && isset($json['subtype'])
            && $json['type'] === 'result' && $json['subtype'] === 'success'
        ) {
            return [
                'inputTokens' => $json['usage']['input_tokens'] ?? 0,
                'outputTokens' => $json['usage']['output_tokens'] ?? 0,
            ];
        }

        return null;
    }
}
