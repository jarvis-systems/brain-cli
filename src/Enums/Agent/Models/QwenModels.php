<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Agent\Models;

use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Agent\Traits\AgentModelsTrait;
use BrainCLI\Support\Brain;

enum QwenModels: string
{
    use AgentModelsTrait;

    case CODER = 'qwen3-coder-plus';

    public function label(): string
    {
        return match ($this) {
            self::CODER => 'Qwen 3 Coder Plus',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CODER => 'Qwen 3 Coder Plus is a specialized model designed for coding tasks, offering advanced capabilities in code generation, understanding, and debugging across multiple programming languages.',
        };
    }

    public function share(): int
    {
        return match ($this) {
            self::CODER => Brain::getEnv('QWEN_CODER_SHARE', 100),
        };
    }

    /**
     * @return \BrainCLI\Enums\Agent
     */
    public function agent(): Agent
    {
        return Agent::QWEN;
    }

    /**
     * @return array<\BackedEnum>
     */
    protected function rawFallback(): array
    {
        return match ($this) {
            self::CODER => [GroqModels::OPENAI_GPT_OSS_120B, OpenRouterModels::GPT_OSS_120, LMStudioModels::GPT_OSS_20],
        };
    }
}
