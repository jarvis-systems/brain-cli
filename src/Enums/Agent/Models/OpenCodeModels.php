<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Agent\Models;

use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Agent\Traits\AgentModelsTrait;
use BrainCLI\Support\Brain;

enum OpenCodeModels: string
{
    use AgentModelsTrait;

    case GLM47 = 'zai-coding-plan/glm-4.7';
    case GLM47_FREE = 'opencode/glm-4.7-free';
    case BIG_PICKLE_FREE = 'opencode/big-pickle';
    case GROK_CODE_FREE = 'opencode/grok-code';

    public function label(): string
    {
        return match ($this) {
            self::GLM47 => 'Z.AI GLM-4.7',
            self::GLM47_FREE => 'Z.AI GLM-4.7 Free',
            self::BIG_PICKLE_FREE => 'Big Pickle Free',
            self::GROK_CODE_FREE => 'Grok Code Free',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GLM47 => 'GLM-4.7 is the latest open-source SOTA model for advanced reasoning, coding, and agentic tasks.',
            self::GLM47_FREE => 'GLM-4.7 Free is a cost-free version of the GLM-4.7 model with limited capabilities.',
            self::BIG_PICKLE_FREE => 'Big Pickle Free is an open-source model optimized for code generation and understanding tasks.',
            self::GROK_CODE_FREE => 'Grok Code Free is a lightweight model designed for efficient coding assistance and code-related tasks.',
        };
    }

    public function share(): int
    {
        return match ($this) {
            self::GLM47 => Brain::getEnv('OPENCODE_GLM47_SHARE', 50),
            self::GLM47_FREE => Brain::getEnv('OPENCODE_GLM47_FREE_SHARE', 30),
            self::GROK_CODE_FREE => Brain::getEnv('OPENCODE_GROK_CODE_FREE_SHARE', 15),
            self::BIG_PICKLE_FREE => Brain::getEnv('OPENCODE_BIG_PICKLE_FREE_SHARE', 5),
        };
    }

    /**
     * @return \BrainCLI\Enums\Agent
     */
    public function agent(): Agent
    {
        return Agent::OPENCODE;
    }

    /**
     * @return array<\BackedEnum>
     */
    protected function rawFallback(): array
    {
        return match ($this) {
            self::GLM47 => [ClaudeModels::OPUS],
            self::GLM47_FREE => [self::GROK_CODE_FREE],
            self::GROK_CODE_FREE => [self::BIG_PICKLE_FREE],
            self::BIG_PICKLE_FREE => [QwenModels::CODER],
        };
    }
}
