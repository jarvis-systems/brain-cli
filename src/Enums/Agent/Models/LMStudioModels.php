<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Agent\Models;

use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Agent\Traits\AgentModelsTrait;

enum LMStudioModels: string
{
    use AgentModelsTrait;

    case GPT_OSS_20 = 'openai/gpt-oss-20b';
    case QWEN3_CODER_30 = 'qwen/qwen3-coder-30b';
    case QWEN3_NEXT_80 = 'qwen/qwen3-next-80b';
    case QWEN3_VL_30 = 'qwen/qwen3-vl-30b';
    case MINISTRAL3_14 = 'mistralai/ministral-3-14b-reasoning';
    case DEVSTRAL_SMALL_2 = 'mistralai/devstral-small-2-2512';

    public function label(): string
    {
        return match ($this) {
            self::GPT_OSS_20 => 'GPT OSS 20B',
            self::QWEN3_CODER_30 => 'Qwen 3 Coder 30B',
            self::QWEN3_NEXT_80 => 'Qwen Next 80B',
            self::QWEN3_VL_30 => 'Qwen 3 VL 30B',
            self::MINISTRAL3_14 => 'Mistral 3 14B',
            self::DEVSTRAL_SMALL_2 => 'Devstral Small 2',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GPT_OSS_20 => 'An open-source 20 billion parameter language model optimized for code generation and understanding tasks.',
            self::QWEN3_CODER_30 => 'A 30 billion parameter model from Qwen, specifically designed for coding tasks with enhanced capabilities for code generation, completion, and debugging.',
            self::QWEN3_NEXT_80 => 'An 80 billion parameter advanced language model from Qwen, offering state-of-the-art performance in natural language understanding and generation across various domains.',
            self::QWEN3_VL_30 => 'A 30 billion parameter multimodal model from Qwen, capable of understanding and generating both text and images, making it suitable for a wide range of applications.',
            self::MINISTRAL3_14 => 'A 14 billion parameter reasoning-focused language model from Mistral AI, designed to excel in complex problem-solving and logical reasoning tasks.',
            self::DEVSTRAL_SMALL_2 => 'A smaller variant of Mistral AI\'s Devstral series, optimized for efficiency while maintaining strong performance in various language understanding and generation tasks.',
        };
    }

    public function share(): int
    {
        return match ($this) {
            self::GPT_OSS_20 => 50,
            self::QWEN3_CODER_30 => 20,
            self::QWEN3_NEXT_80 => 15,
            self::QWEN3_VL_30 => 10,
            self::MINISTRAL3_14 => 5,
            self::DEVSTRAL_SMALL_2 => 0,
        };
    }

    /**
     * @return \BrainCLI\Enums\Agent
     */
    public function agent(): Agent
    {
        return Agent::LM_STUDIO;
    }

    /**
     * @return array<\BackedEnum>
     */
    protected function rawFallback(): array
    {
        return match ($this) {
            self::GPT_OSS_20 => [self::QWEN3_CODER_30],
            self::QWEN3_CODER_30 => [self::QWEN3_NEXT_80],
            self::QWEN3_NEXT_80 => [self::QWEN3_VL_30],
            self::QWEN3_VL_30 => [self::MINISTRAL3_14],
            self::MINISTRAL3_14 => [self::DEVSTRAL_SMALL_2],
            self::DEVSTRAL_SMALL_2 => [],
        };
    }
}
