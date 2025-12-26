<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Dto;

use Bfg\Dto\Dto;
use BrainCLI\Console\AiCommands\Lab\Dto\ProcessConfig;

/**
 * Process state DTO for Lab Process Manager.
 *
 * Represents the runtime state and lifecycle of a managed process.
 * Tracks execution status, timing, output, and metadata throughout
 * the process lifecycle from PENDING to completion or termination.
 *
 * Lifecycle states:
 * PENDING → READY → RUNNING → PAUSED/COMPLETED/STOPPED
 * PAUSED ↔ RUNNING (resume/pause)
 * Any state → FAILED (on error)
 * Any state → STOPPED (manual kill)
 */
class ProcessState extends Dto
{
    /**
     * Process lifecycle status constants
     */
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_READY = 'READY';
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_PAUSED = 'PAUSED';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_STOPPED = 'STOPPED';

    /**
     * @param string $id Unique process identifier (e.g., "proc-001")
     * @param string $name Display name for UI
     * @param string $type Process type: shell | screen | agent
     * @param string $status Current lifecycle status (use STATUS_* constants)
     * @param ProcessConfig $config Process configuration reference
     * @param string $createdAt ISO 8601 timestamp of creation
     * @param string|null $startedAt ISO 8601 timestamp when started
     * @param string|null $completedAt ISO 8601 timestamp when completed
     * @param int|null $exitCode Process exit code (0 = success)
     * @param string|null $error Error message if failed
     * @param array $metadata Custom metadata (e.g., ['interrupted' => true])
     * @param int $outputLines Number of lines in log file
     * @param int $pid Operating system process ID
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public string $status,
        public ProcessConfig $config,
        public string $createdAt,
        public ?string $startedAt = null,
        public ?string $completedAt = null,
        public ?int $exitCode = null,
        public ?string $error = null,
        public array $metadata = [],
        public int $outputLines = 0,
        public int $pid = 0,
    ) {}

    /**
     * Mark process as running and record start time.
     */
    public function markRunning(): static
    {
        $this->status = self::STATUS_RUNNING;
        $this->startedAt = date('c');
        return $this;
    }

    /**
     * Mark process as completed with exit code and record completion time.
     */
    public function markCompleted(int $exitCode): static
    {
        $this->status = self::STATUS_COMPLETED;
        $this->exitCode = $exitCode;
        $this->completedAt = date('c');
        return $this;
    }

    /**
     * Mark process as failed with error message and record completion time.
     */
    public function markFailed(string $error): static
    {
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        $this->completedAt = date('c');
        return $this;
    }

    /**
     * Mark process as stopped and record completion time.
     */
    public function markStopped(): static
    {
        $this->status = self::STATUS_STOPPED;
        $this->completedAt = date('c');
        return $this;
    }

    /**
     * Mark process as paused.
     */
    public function markPaused(): static
    {
        $this->status = self::STATUS_PAUSED;
        return $this;
    }

    /**
     * Mark process as ready to start.
     */
    public function markReady(): static
    {
        $this->status = self::STATUS_READY;
        return $this;
    }

    /**
     * Check if process is currently running.
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Check if process has completed successfully.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if process has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if process is paused.
     */
    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    /**
     * Check if process is stopped.
     */
    public function isStopped(): bool
    {
        return $this->status === self::STATUS_STOPPED;
    }

    /**
     * Check if process is in a terminal state (completed, failed, or stopped).
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_STOPPED,
        ]);
    }
}