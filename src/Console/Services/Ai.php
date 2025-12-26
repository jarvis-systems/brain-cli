<?php

declare(strict_types=1);

namespace BrainCLI\Console\Services;

use BrainCLI\Console\Services\Traits\Ai\ConstructorTrait;
use BrainCLI\Console\Services\Traits\Ai\HelpersTrait;
use BrainCLI\Console\Services\Traits\Ai\RunBridgeTrait;
use BrainCLI\Dto\Person;
use Laravel\Prompts\Prompt;

class Ai extends Prompt
{
    use RunBridgeTrait;
    use ConstructorTrait;
    use HelpersTrait;

    public function __construct(
        public Person $person,
        protected array|null $schema = null,
        protected bool $npMcp = false,
        protected bool $yolo = false,
    ) {
        //
    }

    /**
     * Set the schema for the AI response.
     */
    public function schema(array $schema): static
    {
        $this->schema = $schema;
        return $this;
    }

    /**
     * Disable the use of MCP files for schema validation.
     */
    public function noMcp(bool $noMcp = true): static
    {
        $this->npMcp = $noMcp;
        return $this;
    }

    /**
     * Enable YOLO mode.
     */
    public function yolo(bool $yolo = true): static
    {
        $this->yolo = $yolo;
        return $this;
    }
}
