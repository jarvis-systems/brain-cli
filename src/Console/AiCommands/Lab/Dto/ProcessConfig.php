<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Dto;

use Bfg\Dto\Dto;

/**
 * Process configuration DTO for ProcessManager.
 *
 * Defines execution parameters for spawning processes in Lab environment.
 * Supports shell commands, PHP scripts, and agent execution types.
 */
class ProcessConfig extends Dto
{
    /**
     * @param string $command Command to execute (REQUIRED)
     * @param array|null $args Command arguments
     * @param string|null $cwd Working directory (null = inherit from parent)
     * @param array|null $env Environment variables (null = inherit from parent)
     * @param int|null $timeout Timeout in seconds (null = infinite)
     * @param bool $tty Allocate TTY for process (default: false)
     * @param string $type Process type: 'shell' | 'php' | 'agent' (default: 'shell')
     * @param string|null $screenClass ScreenAbstract class for agent type execution
     * @param string|null $screenMethod Method to call on screen class
     * @param array $screenArgs Arguments to pass to screen method
     */
    public function __construct(
        public string $command,
        public ?array $args = null,
        public ?string $cwd = null,
        public ?array $env = null,
        public ?int $timeout = null,
        public bool $tty = false,
        public string $type = 'shell',
        public ?string $screenClass = null,
        public ?string $screenMethod = null,
        public array $screenArgs = [],
    ) {
    }
}