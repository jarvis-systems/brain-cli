<?php

declare(strict_types=1);

namespace BrainCLI\Dto\ProcessOutput;

use Bfg\Dto\Dto;
use BrainCLI\Enums\Agent;

class ToolUse extends Dto
{
    public function __construct(
        public Agent $agent,
        public string|null $id,
        public string $name,
        public array|string $input,
        public string $type = 'tool_use',
    ) {
    }
}
