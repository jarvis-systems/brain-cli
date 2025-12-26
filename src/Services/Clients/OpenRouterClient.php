<?php

declare(strict_types=1);

namespace BrainCLI\Services\Clients;

use BrainCLI\Dto\Process\Payload;
use BrainCLI\Enums\Agent;
use BrainCLI\Services\ProcessFactory;
use BrainCLI\Support\Brain;

class OpenRouterClient extends CodexClient
{
    /**
     * Get agent type
     */
    public function agent(): Agent
    {
        return Agent::OPENROUTER;
    }

    /**
     * Process payload creation
     */
    protected function processPayload(Payload $payload): Payload
    {
        return parent::processPayload($payload)
            ->defaultOptionsBehavior(function (array $options) {
                return [
                    'model' => $options['model'] ?? $this->agent()->generalModel()->value,
                ];
            })
            ->appendBehavior(function (ProcessFactory $factory) {
                $factory->settings([
                    'model_providers.openrouter' => [
                        'name' => $this->agent()->label(),
                        'base_url' => Brain::getEnv('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
                        'env_key' => 'OPENROUTER_API_KEY',
                    ],
                    'model_provider' => 'openrouter',
                ]);
                return [
                    'env' => [
                        'OPENROUTER_API_KEY' => Brain::getEnv('OPENROUTER_API_KEY'),
                    ]
                ];
            });
    }
}
