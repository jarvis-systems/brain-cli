<?php

declare(strict_types=1);

namespace BrainCLI\Services\Clients;

use BrainCLI\Dto\Process\Payload;
use BrainCLI\Enums\Agent;
use BrainCLI\Services\ProcessFactory;
use BrainCLI\Support\Brain;

class QwenClient extends GeminiClient
{
    /**
     * Get agent type
     */
    public function agent(): Agent
    {
        return Agent::QWEN;
    }

    protected function getFolderParts(): string|array
    {
        return '.qwen';
    }

    protected function getFileParts(): string|array
    {
        return 'QWEN.md';
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
            Brain::getEnv('QWEN_NPM_PROGRAM_PATH', Brain::getEnv('NPM_PROGRAM_PATH', 'npm')),
            'install', '-g', '@qwen-code/qwen-code@v0.4.0-preview.1'
        ];
        $systemFile = null;

        return $payload
            ->installBehavior($install)
            ->updateBehavior($install)
            ->programBehavior(Brain::getEnv('QWEN_PROGRAM_PATH', 'qwen'))
            ->resumeBehavior(function (ProcessFactory $factory, string $sessionId) {
                return ['--resume', $sessionId];
            })
            ->continueBehavior('--resume')
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
                if (str_starts_with($systemPrompt, '@')) {
                    $file = substr($systemPrompt, 1);
                    $file = realpath($file);
                }
                return [
                    'env' => [
                        'QWEN_SYSTEM_MD' => $systemFile = $this->temporalFile($systemPrompt)
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
            isset($json['type']) && isset($json['subtype'])
            && $json['type'] === 'system' && $json['subtype'] === 'init'
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
            isset($json['type']) && isset($json['message']['type'])
            && $json['type'] === 'assistant' && $json['message']['type'] === 'message'
        ) {
            $this->message['id'] = $json['message']['id'] ?? '0';
            $this->message['content'] .= $json['message']['content'][0]['text'] ?? '';
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
            isset($json['type']) && isset($json['subtype'])
            && $json['type'] === 'result' && $json['subtype'] === 'success'
        ) {
            $this->message['success'] = true;
            return [
                'inputTokens' => $json['usage']['input_tokens'] ?? 0,
                'outputTokens' => $json['usage']['output_tokens'] ?? 0,
            ];
        }

        return null;
    }
}
