<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Agent\Models;

use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Agent\Traits\AgentModelsTrait;
use BrainCLI\Support\Brain;

enum CodexModels: string
{
    use AgentModelsTrait;

    case GPT51_CODEX_MAX = 'gpt-5.1-codex-max';
    case GPT51_CODEX = 'gpt-5.1-codex';
    case GPT51_CODEX_MINI = 'gpt-5.1-codex-mini';
    case GPT51 = 'gpt-5.1';

    case GPT52_CODEX_MAX = 'gpt-5.2-codex-max';
    case GPT52_CODEX = 'gpt-5.2-codex';
    case GPT52_CODEX_MINI = 'gpt-5.2-codex-mini';
    case GPT52 = 'gpt-5.2';

    public function label(): string
    {
        return match ($this) {
            self::GPT51_CODEX_MAX => 'GPT 5.1 Codex Max',
            self::GPT51_CODEX => 'GPT 5.1 Codex',
            self::GPT51_CODEX_MINI => 'GPT 5.1 Codex Mini',
            self::GPT51 => 'GPT 5.1',
            self::GPT52_CODEX_MAX => 'GPT 5.2 Codex Max',
            self::GPT52_CODEX => 'GPT 5.2 Codex',
            self::GPT52_CODEX_MINI => 'GPT 5.2 Codex Mini',
            self::GPT52 => 'GPT 5.2',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GPT51_CODEX_MAX => 'GPT 5.1 Codex Max is the most advanced Codex model, offering superior performance in complex tasks, creative content generation, and nuanced understanding.',
            self::GPT51_CODEX => 'GPT 5.1 Codex provides a balanced approach to performance and efficiency, making it suitable for a wide range of applications with strong language capabilities.',
            self::GPT51_CODEX_MINI => 'GPT 5.1 Codex Mini is optimized for speed and cost-effectiveness, ideal for straightforward tasks and applications requiring quick responses.',
            self::GPT51 => 'GPT 5.1 is a general-purpose model that excels in various language tasks, providing reliable performance across different domains.',
            self::GPT52_CODEX_MAX => 'GPT 5.2 Codex Max is the pinnacle of Codex models, delivering exceptional performance in complex coding and language tasks with advanced reasoning capabilities.',
            self::GPT52_CODEX => 'GPT 5.2 Codex strikes a balance between performance and efficiency, making it versatile for diverse applications with strong coding and language skills.',
            self::GPT52_CODEX_MINI => 'GPT 5.2 Codex Mini is designed for efficiency and speed, perfect for simpler tasks and applications that require rapid responses.',
            self::GPT52 => 'GPT 5.2 is a robust general-purpose model that performs well across a variety of language tasks, ensuring dependable results in multiple domains.',
        };
    }

    public function share(): int
    {
        return match ($this) {
            self::GPT51_CODEX_MAX => Brain::getEnv('CODEX_GPT_CODEX_MAX_SHARE', 0),
            self::GPT51_CODEX => Brain::getEnv('CODEX_GPT_CODEX_SHARE', 0),
            self::GPT51_CODEX_MINI => Brain::getEnv('CODEX_GPT_CODEX_MINI_SHARE', 0),
            self::GPT51 => Brain::getEnv('CODEX_GPT_SHARE', 0),
            self::GPT52_CODEX_MAX => Brain::getEnv('CODEX_GPT52_CODEX_MAX_SHARE', 52),
            self::GPT52_CODEX => Brain::getEnv('CODEX_GPT52_CODEX_SHARE', 21),
            self::GPT52_CODEX_MINI => Brain::getEnv('CODEX_GPT52_CODEX_MINI_SHARE', 9),
            self::GPT52 => Brain::getEnv('CODEX_GPT52_SHARE', 18),
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
            self::GPT51_CODEX_MAX => [self::GPT51_CODEX],
            self::GPT51_CODEX => [self::GPT51_CODEX_MINI],
            self::GPT51_CODEX_MINI => [self::GPT51],
            self::GPT51 => [GeminiModels::PRO],
            self::GPT52_CODEX_MAX => [self::GPT52_CODEX],
            self::GPT52_CODEX => [self::GPT52_CODEX_MINI],
            self::GPT52_CODEX_MINI => [self::GPT52],
            self::GPT52 => [self::GPT51_CODEX_MAX],
        };
    }

    public function alias(): array|string|null
    {
        return match ($this) {
            self::GPT51_CODEX_MAX => ['gpt-5.1-codex-max'],
            self::GPT51_CODEX => ['gpt-5.1-codex'],
            self::GPT51_CODEX_MINI => ['gpt-5.1-codex-mini'],
            self::GPT51 => ['gpt-5.1'],
            self::GPT52_CODEX_MAX => ['gpt-5.2-codex-max'],
            self::GPT52_CODEX => ['gpt-5.2-codex'],
            self::GPT52_CODEX_MINI => ['gpt-5.2-codex-mini'],
            self::GPT52 => ['gpt-5.2'],
        };
    }
}
