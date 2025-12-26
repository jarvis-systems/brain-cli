<?php

declare(strict_types=1);

namespace BrainCLI\Enums\CompiledData;

enum Format: string
{
    case XML = 'xml';
    case JSON = 'json';
    case YAML = 'yaml';
    case TOML = 'toml';
    case META = 'meta';
}
