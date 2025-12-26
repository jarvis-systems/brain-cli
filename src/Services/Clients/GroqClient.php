<?php

declare(strict_types=1);

namespace BrainCLI\Services\Clients;

use BrainCLI\Dto\Process\Payload;
use BrainCLI\Enums\Agent;
use BrainCLI\Services\ProcessFactory;
use BrainCLI\Support\Brain;

class GroqClient extends CodexClient
{
    /**
     * Get agent type
     */
    public function agent(): Agent
    {
        return Agent::GROQ;
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
                    'model_providers.groq' => [
                        'name' => $this->agent()->label(),
                        'base_url' => Brain::getEnv('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
                        'env_key' => 'GROQ_AKI_KEY',
                    ],
                    'model_provider' => 'groq',
                ]);
                return [
                    'env' => [
                        'GROQ_AKI_KEY' => Brain::getEnv('GROQ_AKI_KEY'),
                    ]
                ];
            });
    }
}
