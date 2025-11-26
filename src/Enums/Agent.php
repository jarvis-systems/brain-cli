<?php

declare(strict_types=1);

namespace BrainCLI\Enums;

enum Agent: string
{
    case CLAUDE = 'claude';
    case CODEX = 'codex';
//    case GEMINI = 'gemini';
//    case QWEN = 'qwen';

    public function containerName(): string
    {
        return $this->value . ':compile';
    }

    public function label(): string
    {
        return match ($this) {
            self::CLAUDE => 'Claude Code',
            self::CODEX => 'Codex CLI',
//            self::GEMINI => 'Gemini CLI',
//            self::QWEN => 'Qwen CLI',
        };
    }

    public static function list(): array
    {
        $list = [];
        foreach (self::cases() as $agent) {
            $list[$agent->value] = $agent->label();
        }
        return $list;
    }
}
