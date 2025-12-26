<?php

declare(strict_types=1);

namespace BrainCLI\Services\Clients;

use BrainCLI\Dto\Process\Payload;
use BrainCLI\Enums\Agent;
use BrainCLI\Services\ProcessFactory;
use BrainCLI\Support\Brain;

class LMStudioClient extends CodexClient
{
    /**
     * Get agent type
     */
    public function agent(): Agent
    {
        return Agent::LM_STUDIO;
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
                    'model_providers.lm_studio' => [
                        'name' => $this->agent()->label(),
                        'base_url' => Brain::getEnv('LM_STUDIO_BASE_URL', 'http://127.0.0.1:1234/v1')
                    ],
                    'model_provider' => 'lm_studio',
                ]);
            });
    }
}
