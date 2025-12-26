<?php

declare(strict_types=1);

namespace BrainCLI\Dto\Compile;

use Bfg\Dto\Dto;

class CommandInfo extends Dto
{
    /**
     * @param non-empty-string $filename
     * @param non-empty-string $insidePath
     * @param non-empty-string $name
     * @param string $description
     */
    public function __construct(
        public string $filename,
        public string $insidePath,
        public string $name,
        public string $description,
    ) {
    }
}
