<?php

declare(strict_types=1);

namespace BrainCLI\Console\Kernel;

use BrainCLI\Core;
use BrainCLI\Exceptions\CommandTerminatedException;

/**
 * Unified exception boundary for command execution.
 *
 * Exception → exit code mapping:
 * - CommandTerminatedException → $e->exitCode
 * - Throwable → 1, debug output via Core::debugException
 *
 * The kernel never writes to stdout/stderr.
 * User-facing error reporting: optional onError callback.
 *
 * Uses Core directly instead of Brain facade to work
 * without a container (testability, early bootstrap).
 */
final class CommandKernel
{
    /**
     * @param  callable(): int  $fn
     * @param  string  $context  Debug label (e.g. 'compile', 'docs')
     * @param  (callable(\Throwable): void)|null  $onError
     * @return int Exit code (0 = success, 1+ = error)
     */
    public static function run(callable $fn, string $context, ?callable $onError = null): int
    {
        try {
            return (int) $fn();
        } catch (CommandTerminatedException $e) {
            return $e->exitCode;
        } catch (\Throwable $e) {
            (new Core)->debugException($e, "brain-debug:{$context}");

            if ($onError !== null) {
                $onError($e);
            }

            return 1;
        }
    }
}
