<?php

declare(strict_types=1);

namespace BrainCLI\Enums;

use BackedEnum;
use BrainCLI\Dto\Person;
use BrainCLI\Enums\Agent\Models\ClaudeModels;
use BrainCLI\Enums\Agent\Models\CodexModels;
use BrainCLI\Enums\Agent\Models\GeminiModels;
use BrainCLI\Enums\Agent\Models\GroqModels;
use BrainCLI\Enums\Agent\Models\LMStudioModels;
use BrainCLI\Enums\Agent\Models\OpenCodeModels;
use BrainCLI\Enums\Agent\Models\OpenRouterModels;
use BrainCLI\Enums\Agent\Models\QwenModels;
use BrainCLI\Enums\Agent\Traits\AgentableTrait;
use BrainCLI\Enums\Agent\Traits\AgentModelsTrait;
use BrainCLI\Services\Clients\ClaudeClient;
use BrainCLI\Services\Clients\CodexClient;
use BrainCLI\Services\Clients\GeminiClient;
use BrainCLI\Services\Clients\GroqClient;
use BrainCLI\Services\Clients\LMStudioClient;
use BrainCLI\Services\Clients\OpenCodeClient;
use BrainCLI\Services\Clients\OpenRouterClient;
use BrainCLI\Services\Clients\QwenClient;
use BrainCLI\Support\Brain;

enum Agent: string
{
    use AgentableTrait;

    case CLAUDE = 'claude';
    case CODEX = 'codex';
    case GEMINI = 'gemini';
    case QWEN = 'qwen';
    case LM_STUDIO = 'lm-studio';
    case OPENROUTER = 'openrouter';
    case GROQ = 'groq';
    case OPENCODE = 'opencode';
//    case COPILOT = 'copilot';

    public function containerService(): string
    {
        return match ($this) {
            self::CLAUDE => ClaudeClient::class,
            self::CODEX => CodexClient::class,
            self::GEMINI => GeminiClient::class,
            self::QWEN => QwenClient::class,
            self::LM_STUDIO => LmStudioClient::class,
            self::OPENROUTER => OpenRouterClient::class,
            self::GROQ => GroqClient::class,
            self::OPENCODE => OpenCodeClient::class,
            //self::COPILOT => CopilotClient::class,
        };
    }

    /**
     * @return class-string<AgentModelsTrait|BackedEnum>
     */
    public function modelsEnum(): string
    {
        return match ($this) {
            self::CLAUDE => ClaudeModels::class,
            self::CODEX => CodexModels::class,
            self::GEMINI => GeminiModels::class,
            self::QWEN => QwenModels::class,
            self::LM_STUDIO => LMStudioModels::class,
            self::OPENROUTER => OpenRouterModels::class,
            self::GROQ => GroqModels::class,
            self::OPENCODE => OpenCodeModels::class,
        };
    }

    public function depended(): Agent|null
    {
        return match ($this) {
            self::CLAUDE, self::CODEX, self::GEMINI, self::QWEN, self::OPENCODE => null,
            self::LM_STUDIO, self::OPENROUTER, self::GROQ => self::CODEX,
        };
    }

    public function isEnabled(): bool
    {
        return match ($this) {
            self::CLAUDE => !! Brain::getEnv('CLAUDE_ENABLE', true),
            self::CODEX => !! Brain::getEnv('CODEX_ENABLE', true),
            self::GEMINI => !! Brain::getEnv('GEMINI_ENABLE', true),
            self::QWEN => !! Brain::getEnv('QWEN_ENABLE', true),
            self::LM_STUDIO => !! Brain::getEnv('LM_STUDIO_ENABLE', false),
            self::OPENROUTER => !! Brain::getEnv('OPENROUTER_API_KEY', false),
            self::GROQ => !! Brain::getEnv('GROQ_AKI_KEY', false),
            self::OPENCODE => !! Brain::getEnv('OPENCODE_ENABLE', true),
            //self::COPILOT => to_bool(Brain::getEnv('COPILOT_ENABLE', false)),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CLAUDE => 'Claude Code',
            self::CODEX => 'Codex CLI',
            self::GEMINI => 'Gemini CLI',
            self::QWEN => 'Qwen CLI',
            self::LM_STUDIO => 'LM Studio',
            self::OPENROUTER => 'OpenRouter CLI',
            self::GROQ => 'Groq CLI',
            self::OPENCODE => 'OpenCode CLI',
            //self::COPILOT => 'GitHub Copilot CLI',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CLAUDE => 'Work with Claude directly in your codebase. Build, debug, and ship from your terminal, IDE, or the web. Describe what you need, and Claude handles the rest.',
            self::CODEX => 'Codex CLI is a coding agent that you can run locally from your terminal and that can read, modify, and run code on your machine, in the chosen directory.',
            self::GEMINI => 'Gemini CLI is an open-source AI agent that brings the power of Gemini directly into your terminal. It provides lightweight access to Gemini, giving you the most direct path from your prompt to our model.',
            self::QWEN => 'Qwen Code is a powerful command-line AI workflow tool adapted from Gemini CLI, specifically optimized for Qwen3-Coder models.',
            self::LM_STUDIO => 'LM Studio CLI is a local AI agent that allows you to run language models directly from your terminal, enabling code generation, modification, and execution on your machine without relying on external APIs.',
            self::OPENROUTER => 'OpenRouter CLI is an open-source AI agent that integrates OpenRouter models directly into your terminal, providing seamless access to a variety of language models for coding and other tasks.',
            self::GROQ => 'Groq CLI is a command-line interface that allows you to interact with Groq AI models directly from your terminal, enabling efficient code generation and execution on your local machine.',
            self::OPENCODE => 'OpenCode CLI is a command-line tool that brings the capabilities of Z.AI\'s OpenCode models to your terminal, allowing you to generate, modify, and run code seamlessly within your local development environment.',
            //self::COPILOT => 'GitHub Copilot CLI is a command-line tool that brings the power of GitHub Copilot to your terminal, allowing you to generate and modify code seamlessly while working in your local development environment.',
        };
    }

    public function share(): int
    {
        return match ($this) {
            self::CLAUDE => Brain::getEnv('CLAUDE_SHARE', 28),
            self::OPENCODE => Brain::getEnv('OPENCODE_SHARE', 24),
            self::CODEX => Brain::getEnv('CODEX_SHARE', 22),
            self::GEMINI => Brain::getEnv('GEMINI_SHARE', 14),
            self::QWEN => Brain::getEnv('QWEN_SHARE', 9),
            self::LM_STUDIO => Brain::getEnv('LM_STUDIO_SHARE', 1),
            self::OPENROUTER => Brain::getEnv('OPENROUTER_SHARE', 1),
            self::GROQ => Brain::getEnv('GROQ_SHARE', 1),
            //self::COPILOT => Brain::getEnv('COPILOT_SHARE', 0),
        };
    }

    /**
     * @return array<ClaudeModels>
     */
    public function models(): array
    {
        Agent::validateShare();

        $enum = $this->modelsEnum();

        $enum::validateShare();

        return $enum::cases();
    }

    /**
     * @return AgentModelsTrait|BackedEnum
     */
    public function bestModel(): BackedEnum
    {
        $enum = $this->modelsEnum();

        return $enum::bestModel();
    }

    public function generalModel(): BackedEnum
    {
        $models = $this->models();
        $bigModel = $models[0];
        foreach ($models as $model) {
            if ($model->share() > $bigModel->share()) {
                $bigModel = $model;
            }
        }
        return $bigModel;
    }

    public static function list(): array
    {
        $list = [];
        foreach (self::cases() as $agent) {
            $list[$agent->value] = $agent->label();
        }
        return $list;
    }

    /**
     * @return array<Agent>
     */
    public static function enabledCases(): array
    {
        $cases = [];
        foreach (Agent::cases() as $case) {
            if ($case->isEnabled()) {
                $cases[] = $case;
            }
        }
        return $cases;
    }

    public static function tryFromEnabled(string $value): Agent|null
    {
        $agent = self::tryFrom($value);
        return $agent?->isEnabled() ? $agent : null;
    }

    public static function fromEnabled(string $value): Agent
    {
        $agent = self::from($value);
        return $agent->isEnabled() ? $agent : throw new \InvalidArgumentException("Agent not enabled: $value");
    }

    /**
     * @return Person[]
     */
    public static function persons(): array
    {
        $persons = [];
        foreach (self::cases() as $agent) {
            foreach ($agent->models() as $model) {
                $persons[] = Person::fromAssoc([
                    'agent' => $agent,
                    'model' => $model,
                ]);
            }
        }
        return $persons;
    }

    public static function personList(string $search = ''): array
    {
        $persons = Agent::persons();
        $persons = collect($persons)
            ->sort(function ($a, $b) {
                if ($a->share() === $b->share()) {
                    return 0;
                }
                return $a->share() > $b->share() ? -1 : 1;
            })
            ->when($search, function ($collection) use ($search) {
                return $collection->filter(function (Person $person) use ($search) {
                    return str_contains(strtolower($person->label()), strtolower($search))
                        || str_contains(strtolower($person->description()), strtolower($search));
                });
            })
            ->all();


        $list = [];
        foreach ($persons as $person) {
            $list[$person->agent->value . ':' . $person->model->value] = $person->label();
        }
        return $list;
    }

    public static function findPerson(string $person): Person
    {
        [$agentValue, $modelValue] = explode(':', $person, 2);
        $agent = self::from($agentValue);
        $models = $agent->models();

        foreach ($models as $model) {
            if ($model->value === $modelValue) {
                return Person::fromAssoc([
                    'agent' => $agent,
                    'model' => $model,
                ]);
            }
        }
        throw new \InvalidArgumentException("Person not found: $person");
    }
}
