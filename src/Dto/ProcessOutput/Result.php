<?php

declare(strict_types=1);

namespace BrainCLI\Dto\ProcessOutput;

use Bfg\Dto\Dto;
use BrainCLI\Enums\Agent;

class Result extends Dto
{
    public function __construct(
        public Agent $agent,
        public int $inputTokens,
        public int $outputTokens,
        public string $type = 'result',
    ) {
    }
}
