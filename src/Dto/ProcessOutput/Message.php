<?php

declare(strict_types=1);

namespace BrainCLI\Dto\ProcessOutput;

use Bfg\Dto\Dto;
use BrainCLI\Enums\Agent;

class Message extends Dto
{
    public function __construct(
        public Agent $agent,
        public string|null $id,
        public string|array $content,
        public string $type = 'message',
    ) {
    }
}
