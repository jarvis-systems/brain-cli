<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab;

use BrainCLI\Console\AiCommands\Lab\Dto\ProcessConfig;
use BrainCLI\Console\AiCommands\Lab\Dto\ProcessState;
use React\ChildProcess\Process as ReactProcess;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Closure;

/**
 * ProcessManager - Async process lifecycle management with ReactPHP.
 *
 * Manages spawning, monitoring, and controlling child processes with real-time
 * output streaming, state persistence, and event-driven callbacks. Integrates
 * with ReactPHP event loop for non-blocking process execution and I/O.
 *
 * Key Features:
 * - Async process spawning with ReactPHP child-process
 * - Real-time stdout/stderr streaming via event handlers
 * - Process state persistence to JSON files
 * - Output buffering with periodic flush to log files
 * - Process recovery for interrupted processes on startup
 * - POSIX signal control (pause/resume/kill)
 * - Event callbacks for output, errors, completion, state changes
 *
 * Directory Structure:
 * - {processesPath}/*.json - Process state files
 * - {processesPath}/*.log - Process output log files
 *
 * Usage:
 * ```php
 * $manager = new ProcessManager($loop, '/path/to/laboratory');
 *
 * // Set event callbacks
 * $manager
 *     ->onOutput(fn($id, $chunk, $stream) => echo "[$id] $chunk")
 *     ->onComplete(fn($id, $exitCode) => echo "Process $id exited: $exitCode");
 *
 * // Spawn process
 * $state = $manager->spawn($config);
 *
 * // Control process
 * $manager->pause($state->id);
 * $manager->resume($state->id);
 * $manager->kill($state->id);
 * ```
 */
class ProcessManager
{
    /**
     * Active ReactPHP process instances.
     *
     * @var array<string, ReactProcess> [id => ReactProcess]
     */
    private array $activeProcesses = [];

    /**
     * Process state objects tracking lifecycle and metadata.
     *
     * @var array<string, ProcessState> [id => ProcessState]
     */
    private array $processStates = [];

    /**
     * Output buffers for batch writing to log files.
     *
     * @var array<string, array<string>> [id => [chunk1, chunk2, ...]]
     */
    private array $outputBuffers = [];

    /**
     * Next process ID counter for unique ID generation.
     */
    private int $nextId = 1;

    /**
     * ReactPHP event loop reference.
     */
    private LoopInterface $loop;

    /**
     * Path to processes directory for state and log files.
     */
    private string $processesPath;

    /**
     * Output flush timer reference.
     */
    private ?TimerInterface $flushTimer = null;

    /**
     * Event callback: fires when process emits output chunk.
     *
     * Signature: function(string $id, string $chunk, string $stream): void
     * - $id: Process ID
     * - $chunk: Output data chunk
     * - $stream: 'stdout' or 'stderr'
     */
    private ?Closure $onOutput = null;

    /**
     * Event callback: fires when process encounters error.
     *
     * Signature: function(string $id, string $error): void
     * - $id: Process ID
     * - $error: Error message
     */
    private ?Closure $onError = null;

    /**
     * Event callback: fires when process completes or terminates.
     *
     * Signature: function(string $id, int $exitCode): void
     * - $id: Process ID
     * - $exitCode: Process exit code (0 = success)
     */
    private ?Closure $onComplete = null;

    /**
     * Event callback: fires when process state changes.
     *
     * Signature: function(string $id, string $newState): void
     * - $id: Process ID
     * - $newState: New status (use ProcessState::STATUS_* constants)
     */
    private ?Closure $onStateChange = null;

    /**
     * Initialize ProcessManager with event loop and laboratory path.
     *
     * Sets up processes directory, recovers interrupted processes from previous
     * session, and starts periodic output flush timer (1 second interval).
     *
     * @param LoopInterface $loop ReactPHP event loop instance
     * @param string $laboratoryPath Path to laboratory workspace
     */
    public function __construct(LoopInterface $loop, string $laboratoryPath)
    {
        $this->loop = $loop;
        $this->processesPath = $laboratoryPath . '/processes';

        // Ensure processes directory exists
        if (!is_dir($this->processesPath)) {
            mkdir($this->processesPath, 0755, true);
        }

        // Recover processes from previous session
        $this->recoverProcesses();

        // Start periodic output buffer flush timer (1 second interval)
        $this->flushTimer = $this->loop->addPeriodicTimer(1.0, function () {
            $this->flushOutputBuffers();
        });
    }

    /**
     * Spawn new process from configuration.
     *
     * Creates unique process ID, initializes state as PENDING, builds command
     * string from ProcessConfig, spawns ReactPHP process, attaches stream event
     * handlers for real-time output capture, and marks process as RUNNING.
     *
     * Process output (stdout/stderr) is buffered and periodically flushed to
     * log file. Exit event handler marks process as COMPLETED or FAILED based
     * on exit code.
     *
     * @param ProcessConfig $config Process configuration
     * @return ProcessState Process state object with unique ID
     */
    public function spawn(ProcessConfig $config): ProcessState
    {
        // Generate unique process ID
        $id = sprintf('proc-%03d', $this->nextId++);

        // Build command string from config
        $command = $config->command;
        if ($config->args !== null && count($config->args) > 0) {
            $command .= ' ' . implode(' ', array_map('escapeshellarg', $config->args));
        }

        // Create process state (PENDING)
        $state = new ProcessState(
            id: $id,
            name: $config->command,
            type: $config->type,
            status: ProcessState::STATUS_PENDING,
            config: $config,
            createdAt: date('c'),
        );

        // Initialize output buffer for this process
        $this->outputBuffers[$id] = [];

        // Create ReactPHP process
        $process = new ReactProcess(
            $command,
            $config->cwd,
            $config->env,
        );

        // Start process
        $process->start($this->loop);

        // Store process reference
        $this->activeProcesses[$id] = $process;
        $this->processStates[$id] = $state;

        // Attach stdout stream handler
        $process->stdout->on('data', function ($chunk) use ($id) {
            $this->outputBuffers[$id][] = $chunk;
            if ($this->onOutput !== null) {
                ($this->onOutput)($id, $chunk, 'stdout');
            }
        });

        // Attach stderr stream handler
        $process->stderr->on('data', function ($chunk) use ($id) {
            $this->outputBuffers[$id][] = $chunk;
            if ($this->onOutput !== null) {
                ($this->onOutput)($id, $chunk, 'stderr');
            }
        });

        // Attach exit event handler
        $process->on('exit', function ($exitCode, $termSignal) use ($id) {
            $state = $this->processStates[$id];

            if ($exitCode === 0) {
                $state->markCompleted($exitCode);
            } else {
                $error = "Exit code: {$exitCode}";
                if ($termSignal !== null) {
                    $error .= ", Signal: {$termSignal}";
                }
                $state->markFailed($error);
            }

            $this->saveState($id);
            $this->flushOutputBuffers();

            if ($this->onComplete !== null) {
                ($this->onComplete)($id, $exitCode);
            }
        });

        // Store PID and mark as RUNNING
        $state->pid = $process->getPid() ?? 0;
        $state->markRunning();
        $this->saveState($id);

        // Emit state change event
        if ($this->onStateChange !== null) {
            ($this->onStateChange)($id, $state->status);
        }

        return $state;
    }

    /**
     * Kill process by sending SIGTERM and closing all streams.
     *
     * Closes stdin, stdout, stderr streams for proper cleanup, sends SIGTERM
     * signal to process, marks state as STOPPED, saves state to disk, and
     * emits state change event.
     *
     * @param string $id Process ID
     */
    public function kill(string $id): void
    {
        if (!isset($this->activeProcesses[$id])) {
            return;
        }

        $process = $this->activeProcesses[$id];
        $state = $this->processStates[$id];

        // Close all streams for proper cleanup
        $process->stdin->close();
        $process->stdout->close();
        $process->stderr->close();

        // Send SIGTERM signal
        $process->terminate(SIGTERM);

        // Mark state as STOPPED
        $state->markStopped();
        $this->saveState($id);

        // Emit state change event
        if ($this->onStateChange !== null) {
            ($this->onStateChange)($id, $state->status);
        }

        // Remove from active processes
        unset($this->activeProcesses[$id]);
    }

    /**
     * Pause process by sending SIGSTOP signal (POSIX only).
     *
     * Suspends process execution without terminating. Process can be resumed
     * with resume() method. Marks state as PAUSED and saves to disk.
     *
     * @param string $id Process ID
     */
    public function pause(string $id): void
    {
        if (!isset($this->activeProcesses[$id])) {
            return;
        }

        $process = $this->activeProcesses[$id];
        $state = $this->processStates[$id];

        // Send SIGSTOP signal (POSIX only)
        $process->terminate(SIGSTOP);

        // Mark state as PAUSED
        $state->markPaused();
        $this->saveState($id);

        // Emit state change event
        if ($this->onStateChange !== null) {
            ($this->onStateChange)($id, $state->status);
        }
    }

    /**
     * Resume paused process by sending SIGCONT signal (POSIX only).
     *
     * Continues process execution after pause. Marks state as RUNNING and
     * saves to disk.
     *
     * @param string $id Process ID
     */
    public function resume(string $id): void
    {
        if (!isset($this->activeProcesses[$id])) {
            return;
        }

        $process = $this->activeProcesses[$id];
        $state = $this->processStates[$id];

        // Send SIGCONT signal (POSIX only)
        $process->terminate(SIGCONT);

        // Mark state as RUNNING
        $state->markRunning();
        $this->saveState($id);

        // Emit state change event
        if ($this->onStateChange !== null) {
            ($this->onStateChange)($id, $state->status);
        }
    }

    /**
     * Send input to process stdin.
     *
     * Writes data to process stdin stream for interactive processes that
     * accept input during execution.
     *
     * @param string $id Process ID
     * @param string $input Input data to send
     */
    public function send(string $id, string $input): void
    {
        if (!isset($this->activeProcesses[$id])) {
            return;
        }

        $process = $this->activeProcesses[$id];
        $process->stdin->write($input);
    }

    /**
     * Get process output from log file.
     *
     * Reads complete output log file for process. Returns empty string if
     * log file does not exist.
     *
     * @param string $id Process ID
     * @return string Complete process output
     */
    public function getOutput(string $id): string
    {
        $logPath = $this->processesPath . '/' . $id . '.log';

        if (!file_exists($logPath)) {
            return '';
        }

        return @file_get_contents($logPath) ?: '';
    }

    /**
     * Get process state by ID.
     *
     * Returns ProcessState object containing current status, timing, exit code,
     * output line count, and metadata. Returns null if process ID not found.
     *
     * @param string $id Process ID
     * @return ProcessState|null Process state or null if not found
     */
    public function getState(string $id): ?ProcessState
    {
        return $this->processStates[$id] ?? null;
    }

    /**
     * Get all process states.
     *
     * Returns array of all ProcessState objects indexed by process ID.
     *
     * @return array<string, ProcessState> All process states
     */
    public function getAllStates(): array
    {
        return $this->processStates;
    }

    /**
     * Save process state to JSON file.
     *
     * Persists ProcessState to disk as JSON for recovery on next startup.
     * Uses JSON_PRETTY_PRINT for human-readable output.
     *
     * @param string $id Process ID
     */
    public function saveState(string $id): void
    {
        if (!isset($this->processStates[$id])) {
            return;
        }

        $state = $this->processStates[$id];
        $stateFile = $this->processesPath . '/' . $id . '.json';

        $data = [
            'id' => $state->id,
            'name' => $state->name,
            'type' => $state->type,
            'status' => $state->status,
            'createdAt' => $state->createdAt,
            'startedAt' => $state->startedAt,
            'completedAt' => $state->completedAt,
            'exitCode' => $state->exitCode,
            'error' => $state->error,
            'metadata' => $state->metadata,
            'outputLines' => $state->outputLines,
            'pid' => $state->pid,
            'config' => [
                'command' => $state->config->command,
                'args' => $state->config->args,
                'cwd' => $state->config->cwd,
                'env' => $state->config->env,
                'timeout' => $state->config->timeout,
                'tty' => $state->config->tty,
                'type' => $state->config->type,
                'screenClass' => $state->config->screenClass,
                'screenMethod' => $state->config->screenMethod,
                'screenArgs' => $state->config->screenArgs,
            ],
        ];

        file_put_contents($stateFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Flush output buffers to log files.
     *
     * Called periodically by timer (every 1 second). Writes buffered output
     * chunks to process log files, updates outputLines count in state, and
     * clears buffers. Skips empty buffers.
     */
    public function flushOutputBuffers(): void
    {
        foreach ($this->outputBuffers as $id => $buffer) {
            if (empty($buffer)) {
                continue;
            }

            $logPath = $this->processesPath . '/' . $id . '.log';
            $output = implode('', $buffer);

            // Append to log file
            file_put_contents($logPath, $output, FILE_APPEND);

            // Update output line count
            if (isset($this->processStates[$id])) {
                $lineCount = substr_count($output, "\n");
                $this->processStates[$id]->outputLines += $lineCount;
            }

            // Clear buffer
            $this->outputBuffers[$id] = [];
        }
    }

    /**
     * Recover processes from previous session.
     *
     * Called on startup. Scans processes directory for state JSON files,
     * loads ProcessState objects, and marks RUNNING/PAUSED processes as
     * STOPPED with interrupted flag in metadata (processes cannot survive
     * parent process restart).
     */
    public function recoverProcesses(): void
    {
        $files = glob($this->processesPath . '/*.json');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            try {
                $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // Skip corrupted state files
                continue;
            }

            // Reconstruct ProcessConfig
            $config = new ProcessConfig(
                command: $data['config']['command'],
                args: $data['config']['args'] ?? null,
                cwd: $data['config']['cwd'] ?? null,
                env: $data['config']['env'] ?? null,
                timeout: $data['config']['timeout'] ?? null,
                tty: $data['config']['tty'] ?? false,
                type: $data['config']['type'] ?? 'shell',
                screenClass: $data['config']['screenClass'] ?? null,
                screenMethod: $data['config']['screenMethod'] ?? null,
                screenArgs: $data['config']['screenArgs'] ?? [],
            );

            // Reconstruct ProcessState
            $state = new ProcessState(
                id: $data['id'],
                name: $data['name'],
                type: $data['type'],
                status: $data['status'],
                config: $config,
                createdAt: $data['createdAt'],
                startedAt: $data['startedAt'] ?? null,
                completedAt: $data['completedAt'] ?? null,
                exitCode: $data['exitCode'] ?? null,
                error: $data['error'] ?? null,
                metadata: $data['metadata'] ?? [],
                outputLines: $data['outputLines'] ?? 0,
                pid: $data['pid'] ?? 0,
            );

            // Mark interrupted processes as STOPPED
            if (in_array($state->status, [ProcessState::STATUS_RUNNING, ProcessState::STATUS_PAUSED])) {
                $state->markStopped();
                $state->metadata['interrupted'] = true;
                $this->processStates[$state->id] = $state;
                $this->saveState($state->id);
            } else {
                $this->processStates[$state->id] = $state;
            }

            // Update nextId counter
            if (preg_match('/proc-(\d+)/', $state->id, $matches)) {
                $num = (int) $matches[1];
                if ($num >= $this->nextId) {
                    $this->nextId = $num + 1;
                }
            }
        }
    }

    /**
     * Set output event callback.
     *
     * Callback signature: function(string $id, string $chunk, string $stream): void
     * - $id: Process ID
     * - $chunk: Output data chunk
     * - $stream: 'stdout' or 'stderr'
     *
     * @param Closure $callback Output event handler
     * @return static Fluent interface
     */
    public function onOutput(Closure $callback): static
    {
        $this->onOutput = $callback;
        return $this;
    }

    /**
     * Set error event callback.
     *
     * Callback signature: function(string $id, string $error): void
     * - $id: Process ID
     * - $error: Error message
     *
     * @param Closure $callback Error event handler
     * @return static Fluent interface
     */
    public function onError(Closure $callback): static
    {
        $this->onError = $callback;
        return $this;
    }

    /**
     * Set completion event callback.
     *
     * Callback signature: function(string $id, int $exitCode): void
     * - $id: Process ID
     * - $exitCode: Process exit code (0 = success)
     *
     * @param Closure $callback Completion event handler
     * @return static Fluent interface
     */
    public function onComplete(Closure $callback): static
    {
        $this->onComplete = $callback;
        return $this;
    }

    /**
     * Set state change event callback.
     *
     * Callback signature: function(string $id, string $newState): void
     * - $id: Process ID
     * - $newState: New status (use ProcessState::STATUS_* constants)
     *
     * @param Closure $callback State change event handler
     * @return static Fluent interface
     */
    public function onStateChange(Closure $callback): static
    {
        $this->onStateChange = $callback;
        return $this;
    }
}