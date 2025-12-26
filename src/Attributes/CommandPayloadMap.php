<?php

declare(strict_types=1);

namespace BrainCLI\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class CommandPayloadMap
{
    public function __construct(
        public array $data = []
    ) {
    }
}
