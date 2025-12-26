<?php

declare(strict_types=1);

namespace BrainCLI\Dto\ProcessOutput;

use Bfg\Dto\Dto;
use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Process\Type;

class Init extends Dto
{
    public function __construct(
        public Agent $agent,
        public string $sessionId,
        public Type $processType,
        public string $type = 'init',
    ) {
    }
}
