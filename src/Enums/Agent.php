<?php

declare(strict_types=1);

namespace BrainCLI\Enums;

enum Agent: string
{
    case CLAUDE = 'claude';
    case CODEX = 'codex';
    case GEMINI = 'gemini';
    case QWEN = 'qwen';
}
