<?php

declare(strict_types=1);

namespace BrainCLI\Exceptions;

/**
 * Signals graceful command termination with a specific exit code.
 *
 * Caught by CommandKernel — never reaches the user as an unhandled exception.
 * Use instead of exit() to allow testability and proper cleanup.
 */
class CommandTerminatedException extends \RuntimeException
{
    public readonly int $exitCode;

    public function __construct(int $exitCode = 1, string $message = '')
    {
        $this->exitCode = $exitCode;
        parent::__construct($message, $exitCode);
    }
}
