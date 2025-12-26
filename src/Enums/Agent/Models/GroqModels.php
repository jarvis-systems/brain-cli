<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Agent\Models;

use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Agent\Traits\AgentModelsTrait;
use BrainCLI\Support\Brain;

enum GroqModels: string
{
    use AgentModelsTrait;

    case LLAMA_3_1_8B = 'llama-3.1-8b-instant';
    case LLAMA_3_3_70B = 'llama-3.3-70b-instant';
    case META_LLAMA_GUARD_4_12B = 'meta-llama/llama-guard-4-12b';
    case OPENAI_GPT_OSS_120B = 'openai/gpt-oss-120b';
    case OPENAI_GPT_OSS_20B = 'openai/gpt-oss-20b';

    public function label(): string
    {
        return match ($this) {
            self::LLAMA_3_1_8B => 'LLaMA 3.1 8B Instant',
            self::LLAMA_3_3_70B => 'LLaMA 3.3 70B Instant',
            self::META_LLAMA_GUARD_4_12B => 'Meta LLaMA Guard 4 12B',
            self::OPENAI_GPT_OSS_120B => 'OpenAI GPT-OSS 120B',
            self::OPENAI_GPT_OSS_20B => 'OpenAI GPT-OSS 20B',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LLAMA_3_1_8B => 'LLaMA 3.1 8B Instant is a lightweight version of the LLaMA 3.1 model, optimized for faster inference and lower resource consumption.',
            self::LLAMA_3_3_70B => 'LLaMA 3.3 70B Instant is a powerful variant of the LLaMA 3.3 model, designed for high-performance applications requiring advanced language understanding.',
            self::META_LLAMA_GUARD_4_12B => 'Meta LLaMA Guard 4 12B is a robust language model developed by Meta, focusing on safety and reliability in AI interactions.',
            self::OPENAI_GPT_OSS_120B => 'OpenAI GPT-OSS 120B is an open-source large language model with 120 billion parameters, suitable for complex NLP tasks.',
            self::OPENAI_GPT_OSS_20B => 'OpenAI GPT-OSS 20B is an open-source large language model with 20 billion parameters, ideal for various natural language processing applications.',
        };
    }

    public function share(): int
    {
        return match ($this) {
            self::OPENAI_GPT_OSS_120B => 40,
            self::LLAMA_3_3_70B => 30,
            self::OPENAI_GPT_OSS_20B => 15,
            self::META_LLAMA_GUARD_4_12B => 10,
            self::LLAMA_3_1_8B => 5,
        };
    }

    /**
     * @return \BrainCLI\Enums\Agent
     */
    public function agent(): Agent
    {
        return Agent::GROQ;
    }

    /**
     * @return array<\BackedEnum>
     */
    protected function rawFallback(): array
    {
        return match ($this) {
            self::OPENAI_GPT_OSS_120B => [OpenRouterModels::GPT_OSS_120, self::LLAMA_3_3_70B],
            self::LLAMA_3_3_70B => [self::OPENAI_GPT_OSS_20B],
            self::OPENAI_GPT_OSS_20B => [OpenRouterModels::GPT_OSS_20, self::META_LLAMA_GUARD_4_12B],
            self::META_LLAMA_GUARD_4_12B => [self::LLAMA_3_1_8B],
            self::LLAMA_3_1_8B => [],
        };
    }
}
