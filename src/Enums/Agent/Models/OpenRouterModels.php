<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Agent\Models;

use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Agent\Traits\AgentModelsTrait;

/**
 * #model = "openai/gpt-oss-20b:free"
 * #model = "openai/gpt-oss-120b:free"
 * #model = "alibaba/tongyi-deepresearch-30b-a3b:free"
 * #model = "kwaipilot/kat-coder-pro:free"
 * #model = "nex-agi/deepseek-v3.1-nex-n1:free"
 * #model = "qwen/qwen3-coder:free"
 * #model = "amazon/nova-2-lite-v1:free"
 * #model = "arcee-ai/trinity-mini:free" # Strange
 * #model = "tngtech/tng-r1t-chimera:free" # Strange but can like char or judge
 * #model = "openai/gpt-oss-20b:free"
 * #model = "z-ai/glm-4.5-air:free"
 */
enum OpenRouterModels: string
{
    use AgentModelsTrait;

    case GPT_OSS_20 = 'openai/gpt-oss-20b:free';
    case GPT_OSS_120 = 'openai/gpt-oss-120b:free';
    case TONGYI_DEEPRESEARCH_30 = 'alibaba/tongyi-deepresearch-30b-a3b:free';
    case KAT_CODER_PRO = 'kwaipilot/kat-coder-pro:free';
    case DEEPSEEK_V3_1_NEX_N1 = 'nex-agi/deepseek-v3.1-nex-n1:free';
    case QWEN3_CODER_30 = 'qwen/qwen3-coder:free';
    case NOVA_2_LITE_V1 = 'amazon/nova-2-lite-v1:free';
    case TRINITY_MINI = 'arcee-ai/trinity-mini:free';
    case TNG_R1T_CHIMERA = 'tngtech/tng-r1t-chimera:free';
    case GLM_4_5_AIR = 'z-ai/glm-4.5-air:free';



    public function label(): string
    {
        return match ($this) {
            self::GPT_OSS_20 => 'GPT-OSS 20B',
            self::GPT_OSS_120 => 'GPT-OSS 120B',
            self::TONGYI_DEEPRESEARCH_30 => 'Tongyi DeepResearch 30B',
            self::KAT_CODER_PRO => 'Kat Coder Pro',
            self::DEEPSEEK_V3_1_NEX_N1 => 'DeepSeek V3.1 Nex N1',
            self::QWEN3_CODER_30 => 'Qwen3 Coder 30B',
            self::NOVA_2_LITE_V1 => 'NOVA 2 Lite V1',
            self::TRINITY_MINI => 'Trinity Mini',
            self::TNG_R1T_CHIMERA => 'TNG R1T Chimera',
            self::GLM_4_5_AIR => 'GLM 4.5 Air',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GPT_OSS_20 => 'An open-source large language model with 20 billion parameters, suitable for various NLP tasks.',
            self::GPT_OSS_120 => 'A powerful open-source large language model with 120 billion parameters for advanced applications.',
            self::TONGYI_DEEPRESEARCH_30 => 'A 30 billion parameter model by Alibaba, designed for deep research and complex language understanding.',
            self::KAT_CODER_PRO => 'A specialized model optimized for coding tasks, providing high accuracy in code generation and completion.',
            self::DEEPSEEK_V3_1_NEX_N1 => 'A versatile model for a wide range of applications, known for its balanced performance.',
            self::QWEN3_CODER_30 => 'A 30 billion parameter model focused on coding, offering robust capabilities for developers.',
            self::NOVA_2_LITE_V1 => 'A lightweight version of the NOVA 2 model, designed for efficient performance in various tasks.',
            self::TRINITY_MINI => 'A compact model suitable for quick tasks and applications requiring lower computational resources.',
            self::TNG_R1T_CHIMERA => 'A unique model with specialized capabilities, ideal for character interactions and judgment tasks.',
            self::GLM_4_5_AIR => 'An advanced model with 4.5 billion parameters, optimized for a variety of language processing tasks.',
        };
    }

    public function share(): int
    {
        return match ($this) {
            self::GPT_OSS_20 => 10,
            self::GPT_OSS_120 => 15,
            self::TONGYI_DEEPRESEARCH_30 => 12,
            self::KAT_CODER_PRO => 13,
            self::DEEPSEEK_V3_1_NEX_N1 => 10,
            self::QWEN3_CODER_30 => 15,
            self::NOVA_2_LITE_V1 => 8,
            self::TRINITY_MINI => 7,
            self::TNG_R1T_CHIMERA => 5,
            self::GLM_4_5_AIR => 5,
        };
    }

    /**
     * @return \BrainCLI\Enums\Agent
     */
    public function agent(): \BrainCLI\Enums\Agent
    {
        return Agent::OPENROUTER;
    }

    /**
     * @return array<\BackedEnum>
     */
    protected function rawFallback(): array
    {
        return match ($this) {
            self::GPT_OSS_120 => [self::QWEN3_CODER_30],
            self::QWEN3_CODER_30 => [self::KAT_CODER_PRO],
            self::KAT_CODER_PRO => [self::TONGYI_DEEPRESEARCH_30],
            self::TONGYI_DEEPRESEARCH_30 => [self::GPT_OSS_20],
            self::GPT_OSS_20 => [self::DEEPSEEK_V3_1_NEX_N1],
            self::DEEPSEEK_V3_1_NEX_N1 => [self::NOVA_2_LITE_V1],
            self::NOVA_2_LITE_V1 => [self::TRINITY_MINI],
            self::TRINITY_MINI => [self::TNG_R1T_CHIMERA],
            self::TNG_R1T_CHIMERA => [self::GLM_4_5_AIR],
            self::GLM_4_5_AIR => [],
        };
    }
}
