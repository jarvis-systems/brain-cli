<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Agent\Models;

use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Agent\Traits\AgentModelsTrait;
use BrainCLI\Support\Brain;

enum ClaudeModels: string
{
    use AgentModelsTrait;

    case OPUS = 'claude-opus-4-5';
    case SONNET = 'claude-sonnet-4-5';
    case HAIKU = 'claude-haiku-4-5';

    public function label(): string
    {
        return match ($this) {
            self::OPUS => 'Claude Opus 4.5',
            self::SONNET => 'Claude Sonnet 4.5',
            self::HAIKU => 'Claude Haiku 4.5',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::OPUS => 'Claude Opus 4.5 is the most capable Claude model, excelling in complex reasoning, creative writing, and nuanced understanding.',
            self::SONNET => 'Claude Sonnet 4.5 balances performance and efficiency, making it suitable for a wide range of applications with strong language capabilities.',
            self::HAIKU => 'Claude Haiku 4.5 is optimized for speed and cost-effectiveness, ideal for straightforward tasks and applications requiring quick responses.',
        };
    }

    public function share(): int
    {
        return match ($this) {
            self::OPUS => Brain::getEnv('CLAUDE_OPUS_SHARE', 62),
            self::SONNET => Brain::getEnv('CLAUDE_SONNET_SHARE', 28),
            self::HAIKU => Brain::getEnv('CLAUDE_HAIKU_SHARE', 10),
        };
    }

    /**
     * @return \BrainCLI\Enums\Agent
     */
    public function agent(): Agent
    {
        return Agent::CLAUDE;
    }

    /**
     * @return array<\BackedEnum>
     */
    protected function rawFallback(): array
    {
        return match ($this) {
            self::SONNET => [self::OPUS],
            self::OPUS => [self::HAIKU],
            self::HAIKU => [CodexModels::GPT51_CODEX_MAX],
//            self::SONNET => [self::OPUS, self::HAIKU, CodexModels::GPT_CODEX_MAX, GeminiModels::PRO, QwenModels::CODER],
//            self::OPUS => [self::HAIKU, CodexModels::GPT_CODEX, GeminiModels::FLASH, QwenModels::CODER],
//            self::HAIKU => [CodexModels::GPT_CODEX_MINI, GeminiModels::FLASH_LITE, QwenModels::CODER],
        };
    }

    public function alias(): array|string|null
    {
        return match ($this) {
            self::OPUS => ['opus', 'claude-opus', 'claude-opus-4.5'],
            self::SONNET => ['sonnet', 'claude-sonnet', 'claude-sonnet-4.5'],
            self::HAIKU => ['haiku', 'claude-haiku', 'claude-haiku-4.5'],
        };
    }
}
