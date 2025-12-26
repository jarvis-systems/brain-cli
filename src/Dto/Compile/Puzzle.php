<?php

declare(strict_types=1);

namespace BrainCLI\Dto\Compile;

use Bfg\Dto\Dto;

class Puzzle extends Dto
{
    /**
     * Dto key preparation start.
     */
    protected static string $__keyPrepEnd = 'puzzle-';

    public function __construct(
        protected string $agent = "mcp__brain__agent({{ value }})",
        protected string $variable = "\${{ value }}",
    ) {
    }
}
