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
    case GPT52_CODEX = 'openai/gpt-5.2-codex';
    case GPT52 = 'openai/gpt-5.2-medium';
    case OPUS = 'anthropic/claude-opus-4-5';
    case SONNET = 'anthropic/claude-sonnet-4-5';
    case HAIKU = 'anthropic/claude-haiku-4-5';
    case Q3CP = 'lmstudio/qwen/qwen3-coder-30b';
    case DSCV2LI = 'lmstudio/deepseek-coder-v2-lite-instruct';
    case QWEN3_CODER = 'alibaba/qwen3-coder-480b-a35b-instruct';

    case GO_GEMINI3_PRO = 'google/gemini-3-pro-preview';
    case GO_GEMINI3_FLASH = 'google/gemini-3-flash-preview';


    case GH_SONNET = 'github-copilot/claude-sonnet-4.5';
    case GH_OPUS = 'github-copilot/claude-opus-4.5';
    case GH_HAIKU = 'github-copilot/claude-haiku-4.5';
    case GH_GEMINI3_FLASH = 'github-copilot/gemini-3-flash-preview';
    case GH_GEMINI3_PRO = 'github-copilot/gemini-3-pro-preview';
    case GH_GPT51_CODEX = 'github-copilot/gpt-5.1-codex';
    case GH_GPT51_CODEX_MAX = 'github-copilot/gpt-5.1-codex-max';
    case GH_GPT51_CODEX_MINI = 'github-copilot/gpt-5.1-codex-mini';
    case GH_GPT51 = 'github-copilot/gpt-5.1';
    case GH_GPT52 = 'github-copilot/gpt-5.2';
    case GH_GROK = 'github-copilot/grok-code-fast-1';

    public function label(): string
    {
        return match ($this) {
            self::GLM47 => 'Z.AI GLM-4.7',
            self::GLM47_FREE => 'Z.AI GLM-4.7 Free',
            self::BIG_PICKLE_FREE => 'Big Pickle Free',
            self::GROK_CODE_FREE => 'Grok Code Free',
            self::GPT52_CODEX => 'OpenAI GPT-5.2-Codex-medium',
            self::GPT52 => 'OpenAI GPT-5.2-medium',
            self::OPUS => 'Anthropic Claude-Opus',
            self::SONNET => 'Anthropic Claude-Sonnet',
            self::HAIKU => 'Anthropic Claude-Haiku',
            self::GH_SONNET => 'Github Claude-Sonnet',
            self::GH_OPUS => 'Github Claude-Opus',
            self::GH_HAIKU => 'Github Claude-Haiku',
            self::GH_GEMINI3_FLASH => 'Github Gemini-3-Flash-Preview',
            self::GH_GEMINI3_PRO => 'Github Gemini-3-Pro-Preview',
            self::GH_GPT51_CODEX => 'Github GPT-5.1-Codex',
            self::GH_GPT51_CODEX_MAX => 'Github GPT-5.1-Codex-Max',
            self::GH_GPT51_CODEX_MINI => 'Github GPT-5.1-Codex-Mini',
            self::GH_GPT51 => 'Github GPT-5.1',
            self::GH_GPT52 => 'Github GPT-5.2',
            self::GH_GROK => 'Github Grok-Code-Fast-1',
            self::Q3CP => 'Qwen3 Coder Plus',
            self::DSCV2LI => 'DeepSeek Coder V2 Lite Instruct',
            self::QWEN3_CODER => 'Qwen3 Coder',
            self::GO_GEMINI3_PRO => 'Google Gemini-3-Pro',
            self::GO_GEMINI3_FLASH => 'Google Gemini-3-Flash',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GLM47 => 'GLM-4.7 is the latest open-source SOTA model for advanced reasoning, coding, and agentic tasks.',
            self::GLM47_FREE => 'GLM-4.7 Free is a cost-free version of the GLM-4.7 model with limited capabilities.',
            self::BIG_PICKLE_FREE => 'Big Pickle Free is an open-source model optimized for code generation and understanding tasks.',
            self::GROK_CODE_FREE => 'Grok Code Free is a lightweight model designed for efficient coding assistance and code-related tasks.',
            self::GPT52_CODEX => 'GPT-5.2-Codex-medium is an advanced model by OpenAI, specialized in code generation and comprehension.',
            self::GPT52 => 'GPT-5.2-medium is a powerful language model by OpenAI, suitable for a wide range of applications including coding tasks.',
            self::OPUS => 'Claude-Opus is Anthropic\'s most capable model, excelling in complex reasoning and creative tasks.',
            self::SONNET => 'Claude-Sonnet is a balanced model by Anthropic, designed for general-purpose use with strong reasoning abilities.',
            self::HAIKU => 'Claude-Haiku is a lightweight model by Anthropic, optimized for speed and efficiency in simpler tasks.',
            self::GH_SONNET => 'GitHub Copilot Claude-Sonnet is a specialized version of Claude-Sonnet for coding tasks.',
            self::GH_OPUS => 'GitHub Copilot Claude-Opus is a specialized version of Claude-Opus for coding tasks.',
            self::GH_HAIKU => 'GitHub Copilot Claude-Haiku is a specialized version of Claude-Haiku for coding tasks.',
            self::GH_GEMINI3_FLASH => 'GitHub Copilot Gemini-3-Flash-Preview is an early access model for testing Gemini 3 capabilities.',
            self::GH_GEMINI3_PRO => 'GitHub Copilot Gemini-3-Pro-Preview is a professional-grade model for advanced coding assistance.',
            self::GH_GPT51_CODEX => 'GitHub Copilot GPT-5.1-Codex is a specialized version of GPT-5.1 for coding tasks.',
            self::GH_GPT51_CODEX_MAX => 'GitHub Copilot GPT-5.1-Codex-Max is the most powerful variant of GPT-5.1-Codex for intensive coding tasks.',
            self::GH_GPT51_CODEX_MINI => 'GitHub Copilot GPT-5.1-Codex-Mini is a lightweight variant of GPT-5.1-Codex for efficient coding assistance.',
            self::GH_GPT51 => 'GitHub Copilot GPT-5.1 is a specialized version of GPT-5.1 for coding tasks.',
            self::GH_GPT52 => 'GitHub Copilot GPT-5.2 is a specialized version of GPT-5.2 for coding tasks.',
            self::GH_GROK => 'GitHub Copilot Grok-Code-Fast-1 is a fast and efficient model for coding tasks.',
            self::Q3CP => 'Qwen3 Coder Plus is Alibaba\'s advanced model designed for coding and programming assistance.',
            self::DSCV2LI => 'DeepSeek Coder V2 Lite Instruct is a model optimized for coding tasks with instruction-following capabilities.',
            self::QWEN3_CODER => 'Qwen3 Coder is Alibaba\'s model focused on code generation and understanding tasks.',
            self::GO_GEMINI3_PRO => 'Google Gemini-3-Pro is Google\'s professional-grade model for advanced coding and reasoning tasks.',
            self::GO_GEMINI3_FLASH => 'Google Gemini-3-Flash is Google\'s fast-access model for coding and reasoning tasks.',
        };
    }

    public function share(): int
    {
        return match ($this) {
            self::GPT52_CODEX => Brain::getEnv('OPENCODE_GPT52_CODEX_SHARE', 35),
            self::GLM47 => Brain::getEnv('OPENCODE_GLM47_SHARE', 20),
            self::GLM47_FREE => Brain::getEnv('OPENCODE_GLM47_FREE_SHARE', 20),
            self::GROK_CODE_FREE => Brain::getEnv('OPENCODE_GROK_CODE_FREE_SHARE', 10),
            self::BIG_PICKLE_FREE => Brain::getEnv('OPENCODE_BIG_PICKLE_FREE_SHARE', 5),
            self::GPT52 => Brain::getEnv('OPENCODE_GPT52_SHARE', 10),
            self::OPUS, self::SONNET, self::HAIKU, self::GH_SONNET, self::GH_OPUS, self::GH_HAIKU,
            self::GH_GEMINI3_FLASH, self::GH_GEMINI3_PRO, self::GH_GPT51_CODEX, self::GH_GPT51_CODEX_MAX, self::GH_GPT51_CODEX_MINI,
            self::GH_GPT51, self::GH_GPT52, self::GH_GROK => Brain::getEnv('OPENCODE_OTHER_SHARE', 0),
            self::Q3CP => Brain::getEnv('OPENCODE_Q3CP_SHARE', 0),
            self::DSCV2LI => Brain::getEnv('OPENCODE_DSCV2LI_SHARE', 0),
            self::QWEN3_CODER => Brain::getEnv('OPENCODE_QWEN3_CODER_SHARE', 0),
            self::GO_GEMINI3_PRO, self::GO_GEMINI3_FLASH => Brain::getEnv('OPENCODE_GOOGLE_SHARE', 0),
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
            self::GLM47_FREE, self::GH_GROK => [self::GROK_CODE_FREE],
            self::GROK_CODE_FREE => [self::BIG_PICKLE_FREE],
            self::BIG_PICKLE_FREE => [QwenModels::CODER],
            self::GPT52_CODEX, self::GPT52, self::HAIKU => [self::GLM47],
            self::OPUS => [self::SONNET],
            self::SONNET => [self::HAIKU],
            self::GH_SONNET, self::GH_OPUS, self::GH_HAIKU, self::GH_GEMINI3_FLASH, self::GH_GEMINI3_PRO, self::GH_GPT51, self::GH_GPT52 => [self::GH_SONNET],
            self::GH_GPT51_CODEX, self::GH_GPT51_CODEX_MAX, self::GH_GPT51_CODEX_MINI => [self::GH_GPT51],
            self::Q3CP => [self::GPT52],
            self::DSCV2LI => [self::GH_GROK],
            self::QWEN3_CODER => [self::Q3CP],
            self::GO_GEMINI3_PRO, self::GO_GEMINI3_FLASH => [self::OPUS],
        };
    }

    public function alias(): array|string|null
    {
        return match ($this) {
            self::GLM47 => ['glm-4.7', 'zai-glm-4.7'],
            self::GLM47_FREE => ['glm-4.7-free', 'zai-glm-4.7-free'],
            self::BIG_PICKLE_FREE => ['big-pickle', 'big-pickle-free'],
            self::GROK_CODE_FREE => ['grok-code', 'grok-code-free'],
            self::GPT52_CODEX => ['gpt-5.2-codex', 'gpt-5.2-codex-medium', 'codex'],
            self::GPT52 => ['gpt-5.2', 'gpt-5.2-medium'],
            self::OPUS => ['claude-opus', 'claude-opus-4-5', 'claude'],
            self::SONNET => ['claude-sonnet', 'claude-sonnet-4-5'],
            self::HAIKU => ['claude-haiku', 'claude-haiku-4-5'],
            self::GH_SONNET => ['gh-claude-sonnet', 'github-claude-sonnet'],
            self::GH_OPUS => ['gh-claude-opus', 'github-claude-opus'],
            self::GH_HAIKU => ['gh-claude-haiku', 'github-claude-haiku'],
            self::GH_GEMINI3_FLASH => ['gh-gemini-3-flash', 'github-gemini-3-flash'],
            self::GH_GEMINI3_PRO => ['gh-gemini-3-pro', 'github-gemini-3-pro'],
            self::GH_GPT51_CODEX => ['gh-gpt-5.1-codex', 'github-gpt-5.1-codex'],
            self::GH_GPT51_CODEX_MAX => ['gh-gpt-5.1-codex-max', 'github-gpt-5.1-codex-max'],
            self::GH_GPT51_CODEX_MINI => ['gh-gpt-5.1-codex-mini', 'github-gpt-5.1-codex-mini'],
            self::GH_GPT51 => ['gh-gpt-5.1', 'github-gpt-5.1'],
            self::GH_GPT52 => ['gh-gpt-5.2', 'github-gpt-5.2'],
            self::GH_GROK => ['gh-grok-code', 'github-grok-code'],
            self::Q3CP => ['qwen3-coder-plus', 'qwen-coder-plus', 'qwen'],
            self::DSCV2LI => ['deepseek-coder-v2-lite-instruct', 'deepseek-coder-v2-lite'],
            self::QWEN3_CODER => ['qwen3-coder', 'qwen-coder'],
            self::GO_GEMINI3_PRO => ['google-gemini-3-pro', 'google-gemini-pro'],
            self::GO_GEMINI3_FLASH => ['google-gemini-3-flash', 'google-gemini-flash'],
        };
    }
}
