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
     * Child process PID for signal forwarding.
     */
    protected ?int $childPid = null;

    /**
     * Main process resource for cleanup.
     *
     * @var resource|null
     */
    protected mixed $mainProcess = null;

    /**
     * @return int Exit code
     */
    public function open(callable|null $openedCallback = null): int
    {
        $this->compiler->processRunCallback($this);
        $data = $this->toArray();
        $baseEnv = getenv();
        $env = array_merge($baseEnv, $data['env']);

        // Register signal handlers for graceful child process termination
        $this->registerSignalHandlers();

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

        // Use 'exec' prefix to replace shell with command, ensuring signal delivery to actual process
        // Without exec: bash receives signal but may not forward to child
        // With exec: command runs directly, receives signals properly
        $command = $this->wrapCommandForSignalDelivery($data['command']);
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $this->cwd, $env);
        if (is_resource($process)) {
            // Store process info for signal handler
            $this->mainProcess = $process;
            $status = proc_get_status($process);
            $this->childPid = $status['pid'] ?? null;

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
            $exitCode = proc_close($process);

            // Clear state after normal exit
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

    /**
     * Register signal handlers for graceful child process termination.
     *
     * Prevents orphan/zombie processes when parent is killed externally
     * (e.g., by tmux session termination or manual SIGTERM).
     */
    protected function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            return;
        }

        // Enable async signal handling
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        $signalHandler = function (int $signal): never {
            $this->terminateChildProcess($signal);
            exit(128 + $signal);
        };

        pcntl_signal(SIGTERM, $signalHandler);
        pcntl_signal(SIGINT, $signalHandler);
        pcntl_signal(SIGHUP, $signalHandler);
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

    /**
     * Wrap command for proper signal delivery to child process.
     *
     * Problem: proc_open spawns a shell (sh -c "command"), and when parent
     * receives SIGTERM/SIGINT, the shell may die but leave the actual command
     * running as an orphan zombie.
     *
     * Solution: Use 'exec' to replace the shell with the command, so signals
     * go directly to the command process.
     *
     * @param string|array $command The command to wrap
     * @return string|array The wrapped command
     */
    protected function wrapCommandForSignalDelivery(string|array $command): string|array
    {
        // Array commands are passed directly to proc_open without shell
        if (is_array($command)) {
            return $command;
        }

        // Already has exec prefix
        if (str_starts_with(ltrim($command), 'exec ')) {
            return $command;
        }

        // Wrap with exec to replace shell with actual command
        // This ensures SIGTERM/SIGINT reach the command directly
        return 'exec ' . $command;
    }

    /**
     * Terminate child process gracefully, then forcefully if needed.
     */
    protected function terminateChildProcess(int $signal): void
    {
        if ($this->childPid === null) {
            return;
        }

        // Check if child process is still running
        if (! posix_kill($this->childPid, 0)) {
            return;
        }

        // Forward the signal to child process
        posix_kill($this->childPid, $signal);

        // Give it time to terminate gracefully (500ms)
        usleep(500000);

        // Force kill if still running
        if (posix_kill($this->childPid, 0)) {
            posix_kill($this->childPid, SIGKILL);
            usleep(100000);
        }

        // Close process resource
        if (is_resource($this->mainProcess)) {
            proc_close($this->mainProcess);
        }

        $this->childPid = null;
        $this->mainProcess = null;
    }

    /**
     * Symfony Process instance for signal handling in run() method.
     */
    protected ?Process $symfonyProcess = null;

    public function run(callable|null $callback = null): int
    {
        $hosted = false;
        $data = $this->toArray();
        $this->compiler->processRunCallback($this);

        // Wrap command for signal delivery
        $command = $this->wrapCommandForSignalDelivery($data['command']);

        // Symfony Process accepts array (command + args) or uses fromShellCommandline for strings
        if (is_array($command)) {
            $this->symfonyProcess = new Process($command, $this->cwd, $data['env']);
        } else {
            $this->symfonyProcess = Process::fromShellCommandline($command, $this->cwd, $data['env']);
        }
        $this->symfonyProcess->setTimeout(null);

        // Register signal handlers for Symfony Process
        $this->registerSymfonySignalHandlers();

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
            $this->restoreSignalHandlers();
        }

        $this->compiler->processExitCallback($this, $exitCode);
        return $exitCode;
    }

    /**
     * Register signal handlers for Symfony Process termination.
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
                $this->symfonyProcess->stop(0.5, $signal);
            }
            exit(128 + $signal);
        };

        pcntl_signal(SIGTERM, $signalHandler);
        pcntl_signal(SIGINT, $signalHandler);
        pcntl_signal(SIGHUP, $signalHandler);
    }

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
