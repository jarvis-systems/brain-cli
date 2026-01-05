<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Agent\Models;

use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Agent\Traits\AgentModelsTrait;
use BrainCLI\Support\Brain;

enum GeminiModels: string
{
    use AgentModelsTrait;

    case PRO = 'gemini-2.5-pro';
    case FLASH = 'gemini-2.5-flash';
    case FLASH_LITE = 'gemini-2.5-flash-lite';

    public function label(): string
    {
        return match ($this) {
            self::PRO => 'Gemini 2.5 Pro',
            self::FLASH => 'Gemini 2.5 Flash',
            self::FLASH_LITE => 'Gemini 2.5 Flash Lite',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PRO => 'Gemini 2.5 Pro is the most advanced Gemini model, offering superior performance in complex tasks, creative content generation, and nuanced understanding.',
            self::FLASH => 'Gemini 2.5 Flash provides a balanced approach to performance and efficiency, making it suitable for a wide range of applications with strong language capabilities.',
            self::FLASH_LITE => 'Gemini 2.5 Flash Lite is optimized for speed and cost-effectiveness, ideal for straightforward tasks and applications requiring quick responses.',
        };
    }

    public function share(): int
    {
        return match ($this) {
            self::PRO => Brain::getEnv('GEMINI_PRO_SHARE', 66),
            self::FLASH => Brain::getEnv('GEMINI_FLASH_SHARE', 27),
            self::FLASH_LITE => Brain::getEnv('GEMINI_FLASH_LITE_SHARE', 7),
        };
    }

    /**
     * @return \BrainCLI\Enums\Agent
     */
    public function agent(): Agent
    {
        return Agent::GEMINI;
    }

    /**
     * @return array<\BackedEnum>
     */
    protected function rawFallback(): array
    {
        return match ($this) {
            self::PRO => [self::FLASH],
            self::FLASH => [self::FLASH_LITE],
            self::FLASH_LITE => [QwenModels::CODER],
//            self::SONNET => [self::OPUS, self::HAIKU, CodexModels::GPT_CODEX_MAX, GeminiModels::PRO, QwenModels::CODER],
//            self::OPUS => [self::HAIKU, CodexModels::GPT_CODEX, GeminiModels::FLASH, QwenModels::CODER],
//            self::HAIKU => [CodexModels::GPT_CODEX_MINI, GeminiModels::FLASH_LITE, QwenModels::CODER],
        };
    }

    public function alias(): array|string|null
    {
        return match ($this) {
            self::PRO => ['gemini-pro', 'gemini-2.5-pro'],
            self::FLASH => ['gemini-flash', 'gemini-2.5-flash'],
            self::FLASH_LITE => ['gemini-flash-lite', 'gemini-2.5-flash-lite'],
        };
    }
}
