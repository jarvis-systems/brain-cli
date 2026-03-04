<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Agent\Models;

use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Agent\Traits\AgentModelsTrait;
use BrainCLI\Support\Brain;

enum GeminiModels: string
{
    use AgentModelsTrait;

    case PRO = 'gemini-3.1-pro';
    case PRO_PREV = 'gemini-3.1-pro-preview';
    case FLASH = 'gemini-3-flash';
    case FLASH_LITE = 'gemini-2.5-pro';

    public function label(): string
    {
        return match ($this) {
            self::PRO => 'Gemini 3.1 Pro',
            self::FLASH => 'Gemini 3 Flash',
            self::FLASH_LITE => 'Gemini 2.5 Pro Lite',
            self::PRO_PREV => 'Gemini 3.1 Pro Preview',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PRO => 'Gemini 3.1 Pro is the most advanced Gemini model, offering superior performance in complex tasks, creative content generation, and nuanced understanding.',
            self::FLASH => 'Gemini 3 Flash provides a balanced approach to performance and efficiency, making it suitable for a wide range of applications with strong language capabilities.',
            self::FLASH_LITE => 'Gemini 2.5 Pro is optimized for speed and cost-effectiveness, ideal for straightforward tasks and applications requiring quick responses.',
            self::PRO_PREV => 'Gemini 3.1 Pro Preview is an early access version of the Gemini 3.1 Pro model, allowing users to experience the latest advancements in language understanding and generation before the official release.',
        };
    }

    public function share(): int
    {
        return match ($this) {
            self::PRO => Brain::getEnv('GEMINI_PRO_SHARE', 66),
            self::FLASH => Brain::getEnv('GEMINI_FLASH_SHARE', 27),
            self::FLASH_LITE => Brain::getEnv('GEMINI_FLASH_LITE_SHARE', 7),
            self::PRO_PREV => Brain::getEnv('GEMINI_PRO_PREVIEW_SHARE', 0),
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
            self::PRO_PREV => [self::PRO, self::FLASH, self::FLASH_LITE],
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
            self::PRO_PREV => ['gemini-pro-preview', 'gemini-2.5-pro-preview'],
        };
    }
}
