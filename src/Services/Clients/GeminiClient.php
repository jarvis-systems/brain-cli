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

class GeminiClient extends ClientAbstract
{
    /**
     * Get agent type
     */
    public function agent(): Agent
    {
        return Agent::GEMINI;
    }

    protected function getFolderParts(): string|array
    {
        return '.gemini';
    }

    protected function getFileParts(): string|array
    {
        return 'GEMINI.md';
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
        if (isset($brain->meta['model']) && $brain->meta['model'] !== 'opus') {
            $settings['model'] = $brain->meta['model'];
        }
        return $settings;
    }

    /**
     * @return non-empty-string|array<string, mixed>|false
     */
    protected function createAgentContent(Data $agent, Data $brain, AgentInfo $info): string|array|false
    {
        return $this->generateWithYamlHeader([
            'name' => $info->name,
            'description' => $info->description,
            'color' => $info->color,
        ], $agent->structure);
    }

    /**
     * @return non-empty-string|array{file: non-empty-string, content: non-empty-string}|false
     */
    protected function createCommandContent(Data $command, Data $brain, CommandInfo $info): string|array|false
    {
        return [
            'file' => implode(DS, [$info->insidePath, $info->filename . '.toml']),
            'content' => <<<TOML
# Invoked via: /$info->name

description = "$info->description"
prompt = """
{$command->structure}
"""
TOML
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
            Brain::getEnv('GEMINI_NPM_PROGRAM_PATH', Brain::getEnv('NPM_PROGRAM_PATH', 'npm')),
            'install', '-g', '@google/gemini-cli@latest'
        ];
        $systemFile = null;

        return $payload
            ->installBehavior($install)
            ->updateBehavior($install)
            ->programBehavior(Brain::getEnv('GEMINI_PROGRAM_PATH', 'gemini'))
            ->resumeBehavior(function (ProcessFactory $factory, string $sessionId) {
                return ['--resume', $sessionId];
            })
            ->continueBehavior('--resume')
            ->promptBehavior(fn (ProcessFactory $factory, string $prompt) => ['--prompt-interactive', $prompt])
            ->askBehavior(fn (ProcessFactory $factory, string $prompt) => $prompt)
            ->jsonBehavior(fn (ProcessFactory $factory) => ['--output-format', 'stream-json'])
            ->yoloBehavior('--yolo')
            ->allowToolsBehavior(fn (ProcessFactory $factory, array $tools) => [
                '--allowed-tools', json_encode(array_values($tools), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ])
            ->modelBehavior(fn (ProcessFactory $factory, string $model) => [
                '--model', $model,
            ])
            ->systemBehavior(function (ProcessFactory $factory, string $systemPrompt) use (&$systemFile) {
                return [
                    'env' => [
                        'GEMINI_SYSTEM_MD' => $systemFile = $this->temporalFile($systemPrompt)
                    ]
                ];
            })
            ->systemAppendBehavior(function (ProcessFactory $factory, string $systemPrompt) {
                if (str_starts_with($systemPrompt, '@')) {
                    $file = substr($systemPrompt, 1);
                    $systemPrompt = file_get_contents($file);
                }
                $this->temporalAppendFile($this->file(), $systemPrompt);
            })
            ->schemaBehavior(function (ProcessFactory $factory, array $schema) use (&$systemFile) {
                $rules = $this->generateRulesOfSchema($schema);
                return $this->temporalAppendFile($systemFile ?: $this->file(), $rules);
            })
            ->noMcpBehavior(['--allowed-mcp-server-names', '[]'])
            ->settingsBehavior(function (ProcessFactory $factory, array $settings) {
                return [
                    'env' => [
                        'GEMINI_CLI_SYSTEM_SETTINGS_PATH'
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
            isset($json['type'], $json['session_id'])
            && $json['type'] === 'init'
        ) {
            return [
                'sessionId' => $json['session_id']
            ];
        }

        return null;
    }

    protected array $message = [
        'id' => '',
        'content' => '',
        'count' => 0,
        'success' => false,
    ];

    /**
     * @return array{id: non-empty-string, content: non-empty-string}|null
     */
    protected function processParseOutputMessage(ProcessFactory $factory, array $json): array|null
    {
        if ($this->message['success']) {
            return [
                'id' => $this->message['id'],
                'content' => $this->message['content'],
            ];
        }

        if (
            isset($json['type'], $json['role'])
            && $json['type'] === 'message' && $json['role'] === 'assistant'
        ) {
            $this->message['id'] = md5($json['timestamp'] ?? '0');
            $this->message['content'] .= $json['content'] ?? '';
            $this->message['count'] += 1;
        }

        return null;
    }

    /**
     * @return array{inputTokens: int, outputTokens: int}|null
     */
    protected function processParseOutputResult(ProcessFactory $factory, array $json): array|null
    {
        if (
            isset($json['type'], $json['stats'])
            && $json['type'] === 'result' && is_array($json['stats'])
        ) {
            $this->message['success'] = true;
            return [
                'inputTokens' => $json['stats']['input_tokens'] ?? 0,
                'outputTokens' => $json['stats']['output_tokens'] ?? 0,
            ];
        }

        return null;
    }
}
