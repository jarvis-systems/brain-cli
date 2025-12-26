<?php

declare(strict_types=1);

namespace BrainCLI\Dto\Compile;

use Bfg\Dto\Dto;
use BrainCLI\Enums\CompiledData\Format;

class Data extends Dto
{
    /**
     * @param  non-empty-string  $id
     * @param  non-empty-string  $file
     * @param  non-empty-string  $class
     * @param  array<non-empty-string, scalar>  $meta
     * @param  non-empty-string  $namespace
     * @param  non-empty-string|null  $namespaceType
     * @param  non-empty-string  $classBasename
     * @param  Format  $format
     * @param  string|array|null  $structure
     */
    public function __construct(
        public string $id,
        public string $file,
        public string $class,
        public array $meta,
        public string $namespace,
        public string|null $namespaceType,
        public string $classBasename,
        public Format $format,
        public string|array|null $structure,
    ) {
    }
}
