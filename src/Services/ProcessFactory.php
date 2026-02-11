<?php

declare(strict_types=1);

namespace BrainCLI\Services;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Abstracts\ClientAbstract;
use BrainCLI\Dto\Process\Payload;
use BrainCLI\Dto\Process\Reflection;
use BrainCLI\Enums\Process\Type;
use BrainCLI\Support\Brain;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Traits\Conditionable;
use Stringable;
use Symfony\Component\Process\Process;

/**
 * @method ProcessFactory install()
 * @method ProcessFactory installWhen(mixed $condition)
 * @method ProcessFactory update()
 * @method ProcessFactory updateWhen(mixed $condition)
 * @method ProcessFactory program()
 * @method ProcessFactory programWhen(mixed $condition)
 * @method ProcessFactory resume(string|null $sessionId)
 * @method ProcessFactory resumeWhen(mixed $condition, string|callable $sessionId)
 * @method ProcessFactory prompt(string|null $prompt)
 * @method ProcessFactory promptWhen(mixed $condition, string|callable $prompt)
 * @method ProcessFactory continue()
 * @method ProcessFactory continueWhen(mixed $condition)
 * @method ProcessFactory ask(string $prompt)
 * @method ProcessFactory askWhen(mixed $condition, string|callable $prompt)
 * @method ProcessFactory yolo()
 * @method ProcessFactory yoloWhen(mixed $condition)
 * @method ProcessFactory allowTools(array $tools)
 * @method ProcessFactory allowToolsWhen(mixed $condition, array|callable $tools)
 * @method ProcessFactory noMcp()
 * @method ProcessFactory noMcpWhen(mixed $condition)
 * @method ProcessFactory model(string $model)
 * @method ProcessFactory modelWhen(mixed $condition, string|callable $model)
 * @method ProcessFactory system(string $systemPrompt)
 * @method ProcessFactory systemWhen(mixed $condition, string|callable $systemPrompt)
 * @method ProcessFactory systemAppend(string $systemPromptAppend)
 * @method ProcessFactory systemAppendWhen(mixed $condition, string|callable $systemPromptAppend)
 * @method ProcessFactory settings(array $settings)
 * @method ProcessFactory settingsWhen(mixed $condition, array|callable $settings)
 * @method ProcessFactory json()
 * @method ProcessFactory jsonWhen(mixed $condition)
 * @method ProcessFactory schema(array $schema)
 * @method ProcessFactory schemaWhen(mixed $condition, array|callable $schema)
 */
class ProcessFactory implements Arrayable
{
    use Conditionable;

    public Reflection $reflection;

    public string $cwd;

    public array $output = [];

    protected bool $dump = false;

    /**
     * Child process PID for signal forwarding and cleanup.
     */
    protected ?int $childPid = null;

    /**
     * Main process resource for cleanup.
     *
     * @var resource|null
     */
    protected mixed $mainProcess = null;

    /**
     * Symfony Process instance for signal handling in run() method.
     */
    protected ?Process $symfonyProcess = null;

    /**
     * Whether shutdown handler has been registered for this instance.
     */
    protected bool $shutdownRegistered = false;

    public function __construct(
        public Type $type,
        public ClientAbstract $compiler,
        public Payload $payload,
        public CommandBridgeAbstract $command,
    ) {
        $this->cwd = Brain::projectDirectory();
        $this->reflection = Reflection::fromAssoc([
            'payload' => $payload,
        ])->setMeta('factory', $this);
        $this->payload->setMeta('reflection', $this->reflection);
    }

    public function dump(bool $dump = true): static
    {
        $this->dump = $dump;

        return $this;
    }

    /**
     * @return int Exit code
     */
    public function open(callable|null $openedCallback = null): int
    {
        $this->compiler->processRunCallback($this);
        $data = $this->toArray();
        $baseEnv = getenv();
        $env = array_merge($baseEnv, $data['env']);

        if ($data['commands']['before']) {
            foreach ($data['commands']['before'] as $beforeCommand) {
                if (empty($beforeCommand)) {
                    continue;
                }
                $beforeProcess = proc_open($beforeCommand, [STDIN, STDOUT, STDERR], $beforePipes, $this->cwd, $env);
                if (is_resource($beforeProcess)) {
                    proc_close($beforeProcess);
                }
            }
        }

        $process = proc_open($data['command'], [STDIN, STDOUT, STDERR], $pipes, $this->cwd, $env);
        if (is_resource($process)) {
            // Store process info IMMEDIATELY for signal handler and shutdown function
            $this->mainProcess = $process;
            $status = proc_get_status($process);
            $this->childPid = $status['pid'] ?? null;

            // Register ALL cleanup mechanisms
            $this->registerSignalHandlers();
            $this->registerShutdownHandler();

            if ($data['commands']['after']) {
                foreach ($data['commands']['after'] as $afterCommand) {
                    if (empty($afterCommand)) {
                        continue;
                    }
                    $afterProcess = proc_open($afterCommand, [STDIN, STDOUT, STDERR], $afterPipes, $this->cwd, $env);
                    if (is_resource($afterProcess)) {
                        proc_close($afterProcess);
                    }
                }
            }

            $this->compiler->processHostedCallback($this);
            if ($openedCallback) {
                call_user_func($openedCallback, $this);
            }
            $exitCode = $this->awaitProcess($process);

            // Normal exit — clear state so shutdown handler is a no-op
            $this->childPid = null;
            $this->mainProcess = null;
            $this->restoreSignalHandlers();

            if ($data['commands']['exit']) {
                foreach ($data['commands']['exit'] as $exitCommand) {
                    if (empty($exitCommand)) {
                        continue;
                    }
                    $exitProcess = proc_open($exitCommand, [STDIN, STDOUT, STDERR], $exitPipes, $this->cwd, $env);
                    if (is_resource($exitProcess)) {
                        proc_close($exitProcess);
                    }
                }
            }

            $this->compiler->processExitCallback($this, $exitCode);
            return $exitCode;
        }

        return ERROR;
    }

    public function run(callable|null $callback = null): int
    {
        $hosted = false;
        $data = $this->toArray();
        $this->compiler->processRunCallback($this);

        if (is_array($data['command'])) {
            $this->symfonyProcess = new Process($data['command'], $this->cwd, $data['env']);
        } else {
            $this->symfonyProcess = Process::fromShellCommandline($data['command'], $this->cwd, $data['env']);
        }
        $this->symfonyProcess->setTimeout(null);

        $this->registerSymfonySignalHandlers();
        $this->registerShutdownHandler();

        try {
            $exitCode = $this->symfonyProcess->run(function ($type, $output) use ($callback, &$hosted) {
                $output = trim($output);
                if (! $hosted) {
                    $this->compiler->processHostedCallback($this);
                    $hosted = true;
                }
                if ($callback) {
                    call_user_func($callback, $output, $type);
                }
                $this->output[] = $output;
            });
        } finally {
            $this->symfonyProcess = null;
            $this->childPid = null;
            $this->restoreSignalHandlers();
        }

        $this->compiler->processExitCallback($this, $exitCode);
        return $exitCode;
    }

    // ─── Signal & Cleanup ───────────────────────────────────────────────

    /**
     * Register signal handlers for graceful child process termination.
     *
     * Handles SIGTERM, SIGINT (Ctrl+C), and SIGHUP (terminal closed/tmux killed).
     */
    protected function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            return;
        }

        // No pcntl_async_signals(true) here — it's a global PHP setting that
        // can interfere with child process terminal rendering (TUI real-time updates).
        // Signals are dispatched via pcntl_signal_dispatch() in awaitProcess() loop.

        $signalHandler = function (int $signal): never {
            $this->killProcessTree($this->childPid);
            exit(128 + $signal);
        };

        pcntl_signal(SIGTERM, $signalHandler);
        pcntl_signal(SIGINT, $signalHandler);
        pcntl_signal(SIGHUP, $signalHandler);
    }

    /**
     * Register signal handlers for Symfony Process (run() method).
     */
    protected function registerSymfonySignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        $signalHandler = function (int $signal): never {
            if ($this->symfonyProcess !== null && $this->symfonyProcess->isRunning()) {
                // Get PID before stopping
                $pid = $this->symfonyProcess->getPid();
                if ($pid) {
                    $this->killProcessTree($pid);
                } else {
                    $this->symfonyProcess->stop(1, SIGTERM);
                }
            }
            exit(128 + $signal);
        };

        pcntl_signal(SIGTERM, $signalHandler);
        pcntl_signal(SIGINT, $signalHandler);
        pcntl_signal(SIGHUP, $signalHandler);
    }

    /**
     * Register shutdown function as LAST RESORT fallback.
     *
     * This fires even when:
     * - Signal handlers don't work (pcntl unavailable)
     * - PHP crashes or runs out of memory
     * - exit() is called from unexpected place
     * - Fatal error occurs
     */
    protected function registerShutdownHandler(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        register_shutdown_function(function (): void {
            // If childPid is null, normal exit already cleaned up — nothing to do
            if ($this->childPid === null && $this->symfonyProcess === null) {
                return;
            }

            // Symfony Process path
            if ($this->symfonyProcess !== null && $this->symfonyProcess->isRunning()) {
                $pid = $this->symfonyProcess->getPid();
                if ($pid) {
                    $this->killProcessTree($pid);
                } else {
                    $this->symfonyProcess->stop(1, SIGTERM);
                }
                $this->symfonyProcess = null;
                return;
            }

            // proc_open path
            if ($this->childPid !== null) {
                $this->killProcessTree($this->childPid);
                $this->childPid = null;
                $this->mainProcess = null;
            }
        });
    }

    /**
     * Restore default signal handlers.
     */
    protected function restoreSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
        pcntl_signal(SIGHUP, SIG_DFL);
    }

    // ─── Process Await ────────────────────────────────────────────────

    /**
     * Wait for process exit using polling loop with signal dispatch.
     *
     * Unlike blocking proc_close(), this dispatches pending signals via
     * pcntl_signal_dispatch() between checks — no pcntl_async_signals(true)
     * needed, which avoids interfering with child process terminal rendering.
     *
     * @param  resource  $process
     */
    protected function awaitProcess(mixed $process): int
    {
        $exitCode = -1;
        $hasDispatch = function_exists('pcntl_signal_dispatch');

        while (true) {
            if ($hasDispatch) {
                pcntl_signal_dispatch();
            }

            $status = proc_get_status($process);
            if (! $status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            usleep(50000); // 50ms
        }

        // Process already exited — just release the resource.
        // Exit code may be -1 here (already consumed by proc_get_status), ignore it.
        proc_close($process);

        return max($exitCode, 0);
    }

    // ─── Process Tree Kill ──────────────────────────────────────────────

    /**
     * Kill an entire process tree: all descendants first, then the root.
     *
     * This is critical because AI clients (claude, opencode) spawn MCP servers
     * as child processes (node, python). Killing only the client leaves MCP
     * servers as orphans consuming resources indefinitely.
     *
     * Strategy:
     * 1. Find all descendant PIDs recursively (pgrep -P)
     * 2. Send SIGTERM to entire tree (bottom-up: children first)
     * 3. Wait briefly for graceful shutdown
     * 4. SIGKILL anything still alive
     */
    protected function killProcessTree(?int $pid): void
    {
        if ($pid === null || ! $this->isProcessAlive($pid)) {
            return;
        }

        // Collect all descendant PIDs before killing anything
        $descendants = $this->getDescendantPids($pid);

        // Phase 1: SIGTERM entire tree (bottom-up: deepest children first)
        foreach (array_reverse($descendants) as $childPid) {
            @posix_kill($childPid, SIGTERM);
        }
        @posix_kill($pid, SIGTERM);

        // Phase 2: Wait up to 1 second for graceful termination
        $waited = 0;
        while ($waited < 1000000 && $this->isProcessAlive($pid)) {
            usleep(50000); // 50ms intervals
            $waited += 50000;
        }

        // Phase 3: SIGKILL anything still alive
        if ($this->isProcessAlive($pid)) {
            // Re-collect descendants (some may have spawned during shutdown)
            $descendants = $this->getDescendantPids($pid);
            foreach (array_reverse($descendants) as $childPid) {
                @posix_kill($childPid, SIGKILL);
            }
            @posix_kill($pid, SIGKILL);
            usleep(100000);
        }
    }

    /**
     * Recursively find all descendant PIDs of a process.
     *
     * @return list<int>
     */
    protected function getDescendantPids(int $pid): array
    {
        $pids = [];
        $output = @shell_exec("pgrep -P {$pid} 2>/dev/null");
        if ($output === null || $output === '') {
            return $pids;
        }

        $childPids = array_filter(
            array_map('intval', explode("\n", trim($output))),
            fn (int $p): bool => $p > 0,
        );

        foreach ($childPids as $childPid) {
            $pids[] = $childPid;
            // Recurse into grandchildren
            $pids = array_merge($pids, $this->getDescendantPids($childPid));
        }

        return $pids;
    }

    /**
     * Check if a process is still alive.
     */
    protected function isProcessAlive(int $pid): bool
    {
        if (! function_exists('posix_kill')) {
            // Fallback: check via shell
            return trim((string) @shell_exec("kill -0 {$pid} 2>/dev/null && echo 1 || echo 0")) === '1';
        }

        // posix_kill with signal 0 = existence check only
        return @posix_kill($pid, 0);
    }

    // ─── Other ──────────────────────────────────────────────────────────

    public function apply(callable $callback): static
    {
        call_user_func($callback, $this);

        return $this;
    }

    /**
     * @param  array<string, string>  $env
     */
    public function env(array $env): static
    {
        $this->reflection->addEnv($env);

        return $this;
    }

    public function __call(string $name, array $arguments)
    {
        if (str_ends_with($name, 'When')) {
            $baseName = substr($name, 0, -4);
            $condition = array_shift($arguments);
            if ($condition) {
                if (isset($arguments[0]) && is_callable($arguments[0])) {
                    $arguments[0] = call_user_func($arguments[0], $this);
                }
                return $this->__call($baseName, $arguments);
            }
            return $this;
        }

        $mapData = $this->payload->getMapData($name);

        if ($mapData !== null) {
            if (isset($mapData['used'])) {
                foreach ($mapData['used'] as $item) {
                    $this->reflection->validatedUsed($item);
                }
            }
            if (isset($mapData['notUsed'])) {
                foreach ($mapData['notUsed'] as $item) {
                    $this->reflection->validatedNotUsed($item);
                }
            }
            $this->reflection->fillBody(
                $this->payload->parameter($name, ...$arguments)
            );
            return $this;
        }

        throw new \BadMethodCallException("Method $name does not exist.");
    }

    /**
     * @return array{command: list<string>, env: array<string, string>, commands: array{before: list<string>, after: list<string>, exit: list<string>}}
     */
    public function toArray(): array
    {
        if (
            $this->payload->isNotNull('append')
            && $this->reflection->isUsed('program')
        ) {
            $this->__call('append', []);
        }

        $body = $this->reflection->get('body');

        if ($this->dump) {
            dump($body);
        }

        if (empty($body['command'])) {
            throw new \RuntimeException('Incorrect later command build: command part is empty');
        }

        return $body;
    }
}