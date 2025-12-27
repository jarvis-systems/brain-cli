<?php

declare(strict_types=1);

namespace BrainCLI\Dto\Compile;

use Bfg\Dto\Dto;

class AgentInfo extends Dto
{
    /**
     * @param  non-empty-string  $filename
     * @param  non-empty-string  $insidePath
     * @param  string|null  $model
     * @param  string  $color
     * @param  non-empty-string  $name
     * @param  string  $description
     * @param  array  $meta
     */
    public function __construct(
        public string $filename,
        public string $insidePath,
        public string|null $model,
        public string $color,
        public string $name,
        public string $description,
        public array $meta,
    ) {
    }
}
