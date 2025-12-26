<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Agent\Models;

use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Agent\Traits\AgentModelsTrait;
use BrainCLI\Support\Brain;

enum CodexModels: string
{
    use AgentModelsTrait;

    case GPT_CODEX_MAX = 'gpt-5.1-codex-max';
    case GPT_CODEX = 'gpt-5.1-codex';
    case GPT_CODEX_MINI = 'gpt-5.1-codex-mini';
    case GPT = 'gpt-5.1';

    public function label(): string
    {
        return match ($this) {
            self::GPT_CODEX_MAX => 'GPT 5.1 Codex Max',
            self::GPT_CODEX => 'GPT 5.1 Codex',
            self::GPT_CODEX_MINI => 'GPT 5.1 Codex Mini',
            self::GPT => 'GPT 5.1',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GPT_CODEX_MAX => 'GPT 5.1 Codex Max is the most advanced Codex model, offering superior performance in complex tasks, creative content generation, and nuanced understanding.',
            self::GPT_CODEX => 'GPT 5.1 Codex provides a balanced approach to performance and efficiency, making it suitable for a wide range of applications with strong language capabilities.',
            self::GPT_CODEX_MINI => 'GPT 5.1 Codex Mini is optimized for speed and cost-effectiveness, ideal for straightforward tasks and applications requiring quick responses.',
            self::GPT => 'GPT 5.1 is a general-purpose model that excels in various language tasks, providing reliable performance across different domains.',
        };
    }

    public function share(): int
    {
        return match ($this) {
            self::GPT_CODEX_MAX => Brain::getEnv('CODEX_GPT_CODEX_MAX_SHARE', 52),
            self::GPT_CODEX => Brain::getEnv('CODEX_GPT_CODEX_SHARE', 21),
            self::GPT_CODEX_MINI => Brain::getEnv('CODEX_GPT_CODEX_MINI_SHARE', 9),
            self::GPT => Brain::getEnv('CODEX_GPT_SHARE', 18),
        };
    }

    /**
     * @return \BrainCLI\Enums\Agent
     */
    public function agent(): Agent
    {
        return Agent::CODEX;
    }

    /**
     * @return array<\BackedEnum>
     */
    protected function rawFallback(): array
    {
        return match ($this) {
            self::GPT_CODEX_MAX => [self::GPT_CODEX],
            self::GPT_CODEX => [self::GPT_CODEX_MINI],
            self::GPT_CODEX_MINI => [self::GPT],
            self::GPT => [GeminiModels::PRO],
//            self::SONNET => [self::OPUS, self::HAIKU, CodexModels::GPT_CODEX_MAX, GeminiModels::PRO, QwenModels::CODER],
//            self::OPUS => [self::HAIKU, CodexModels::GPT_CODEX, GeminiModels::FLASH, QwenModels::CODER],
//            self::HAIKU => [CodexModels::GPT_CODEX_MINI, GeminiModels::FLASH_LITE, QwenModels::CODER],
        };
    }
}
