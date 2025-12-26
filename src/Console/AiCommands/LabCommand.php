<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use Bfg\Attributes\Attributes;
use BrainCLI\Console\AiCommands\Lab\Abstracts\ScreenAbstract;
use BrainCLI\Console\AiCommands\Lab\Dto\Context;
use BrainCLI\Console\AiCommands\Lab\Screen;
use BrainCLI\Console\AiCommands\Lab\WorkSpace;
use BrainCLI\Console\Services\Ai;
use BrainCLI\Support\Brain;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;
use BrainCLI\Console\AiCommands\Lab\ProcessManager;
use BrainCLI\Console\AiCommands\Lab\Dto\ProcessConfig;

use function BrainCLI\Console\AiCommands\Lab\Prompts\registerCommandLineRenderer;


class LabCommand extends CommandBridgeAbstract
{
    protected Ai $orbiter;

    protected array $signatureParts = [
        '{workspace? : The name of the workspace to use}',
    ];

    public Screen $screen;

    public WorkSpace $workSpace;

    private Collection $screens;

    private string $laboratoryPath;

    private LoopInterface $loop;

    private ProcessManager $processManager;

    public bool $shouldStop = false;

    private bool $signalHandlersRegistered = false;

    private int $startTime;

    private array $stats = ['memory' => 0, 'runtime' => '00:00:00'];

    public function __construct() {
        $this->signature = "lab";
        foreach ($this->signatureParts as $part) {
            $this->signature .= " " . $part;
        }
        $this->description = "Start a meeting with AI agents";
        parent::__construct();
    }

    /**
     * Initialize and run ReactPHP event loop for async REPL.
     *
     * Flow:
     * 1. Get event loop instance via Loop::get()
     * 2. Register SIGINT/SIGTERM handlers for graceful shutdown
     * 3. Initialize start time and stats array
     * 4. Add periodic timers (1s runtime, 5s memory)
     * 5. Start async draw loop via Screen::drawAsync()
     * 6. Block on Loop::run() until shutdown signal
     *
     * @param Context $response Initial context from handle()
     * @return int Exit code (self::SUCCESS or self::FAILURE)
     */
    protected function handleBridge(): int|array
    {
        $this->laboratoryPath = Brain::projectDirectory([
            '.laboratories', $this->getWorkspaceName(),
        ]);
        if (! is_dir($this->laboratoryPath)) {
            mkdir($this->laboratoryPath, 0755, true);
        }
        $runtimeFile = implode(DS, [
            $this->laboratoryPath, 'runtime.json'
        ]);
        if (file_exists($runtimeFile)) {
            if ($content = file_get_contents($runtimeFile)) {
                $response = Context::from($content);
            }
        }

        // Register CommandLinePrompt renderer before creating Screen
        registerCommandLineRenderer();

        $this->screen = new Screen($this, $this->laboratoryPath);
        $this->workSpace = new WorkSpace($this, $this->laboratoryPath);

        $response = ($response ?? Context::fromEmpty())
            ->setMeta(['onChange' => function (Context $response) {
                $this->saveResponse($response);
            }]);

        // Initialize ReactPHP event loop
        $this->loop = Loop::get();

        // Initialize ProcessManager with event loop and laboratory path
        $this->processManager = new ProcessManager($this->loop, $this->laboratoryPath);

        // Graceful shutdown sequence:
        // 1. Set shouldStop flag (checked by timers and promise chain)
        // 2. Log signal received
        // 3. Call Loop::stop() to break blocking Loop::run()
        // Note: Active processes should be cleaned up by ProcessManager (Task #5)
        $shutdown = function (int $signal) {
            $this->shouldStop = true;
            $this->info("Received signal {$signal}, shutting down...");
            Loop::stop();
        };

        if (function_exists('pcntl_signal') && !$this->signalHandlersRegistered) {
            Loop::addSignal(SIGINT, $shutdown);
            Loop::addSignal(SIGTERM, $shutdown);
            $this->signalHandlersRegistered = true;
        }

        // Record start time
        $this->startTime = time();

        // 1-second timer: Update runtime clock
        $this->loop->addPeriodicTimer(1.0, function () {
            if ($this->shouldStop) return;
            $elapsed = time() - $this->startTime;
            $this->stats['runtime'] = sprintf('%02d:%02d:%02d',
                floor($elapsed / 3600),
                floor(($elapsed % 3600) / 60),
                $elapsed % 60
            );
        });

        // 5-second timer: Update memory stats
        $this->loop->addPeriodicTimer(5.0, function () {
            if ($this->shouldStop) return;
            $this->stats['memory'] = memory_get_usage(true);
        });

        // Start async draw loop
        $this->screen->drawAsync($response, $this->loop)
            ->then(function (Context $finalResponse) {
                // This only executes when loop stops (shouldn't happen normally)
                $this->saveResponse($finalResponse);
            })
            ->otherwise(function (\Throwable $error) {
                // Handle cancellation (Ctrl+C) or errors
                if (!($error instanceof \RuntimeException && $error->getMessage() === 'Cancelled')) {
                    $this->error('Error: ' . $error->getMessage());
                }
            });

        // Block and run event loop (this is where execution stays)
        $this->loop->run();

        return OK;
    }

    public function getWorkspaceName(): string
    {
        $workspace = $this->argument('workspace');
        if (! $workspace) {
            $workspace = 'default';
        }
        return $workspace;
    }

    /**
     * Execute shell command synchronously (blocking).
     *
     * @param string $command Command to execute
     * @param string|null $arg Optional argument
     * @return array{status: int, body: array<int, string>} Command output
     */
    public function process(string $command, string|null $arg): array
    {
        $output = [
            'body' => [],
            'status' => 0,
        ];
        $cmd = $arg ? $command . " " . $arg : $command;
        $process = Process::fromShellCommandline($cmd, null, [])
            ->setTimeout(null);
        $output['status'] = $process->run(function ($type, $o) use (&$output) {
            if ($o = trim($o)) {
                $output['body'][] = $o;
            }
        });

        $output['body'] = [implode(PHP_EOL, $output['body'])];

        return $output;
    }

    /**
     * Execute shell command asynchronously via ProcessManager.
     *
     * @param string $command Command to execute
     * @param string|null $arg Optional argument
     * @return PromiseInterface<array> Resolves with ['status' => int, 'body' => array]
     */
    public function processAsync(string $command, string|null $arg = null): PromiseInterface
    {
        // Build full command
        $cmd = $arg ? $command . ' ' . $arg : $command;

        // Create ProcessConfig
        $config = new ProcessConfig(command: $cmd);

        // Spawn via ProcessManager
        $state = $this->processManager->spawn($config);

        // Create promise that resolves when process completes
        $deferred = new Deferred();

        // Wire up completion event
        $this->processManager->onComplete(function (string $id, int $exitCode) use ($state, $deferred) {
            if ($id === $state->id) {
                $output = [
                    'status' => $exitCode,
                    'body' => explode("\n", trim($this->processManager->getOutput($id))),
                ];
                $deferred->resolve($output);
            }
        });

        return $deferred->promise();
    }

    public function saveResponse(Context $response): bool
    {
        return !! file_put_contents(
            implode(DS, [
                $this->laboratoryPath, 'runtime.json'
            ]),
            $response->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Get all lab screens
     *
     * @return Collection<int, ScreenAbstract>
     */
    public function screens(): Collection
    {
        if (isset($this->screens)) {
            return $this->screens;
        }
        $classes = Attributes::new()
            ->wherePath(implode(DS, [__DIR__, 'Lab', 'Screens']))
            ->classes();

        return $this->screens = $classes->filter(
            fn (\ReflectionClass $reflectionClass) => $reflectionClass->isSubclassOf(ScreenAbstract::class)
        )->map(
            fn (\ReflectionClass $reflectionClass) => $reflectionClass->newInstance()->setMeta([
                'command' => $this,
                'screen' => $this->screen,
                'workspace' => $this->workSpace,
            ])
        );
    }

    /**
     * Get current runtime statistics
     *
     * @return array{memory: int, runtime: string}
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Get ProcessManager instance for Screen/other components to access.
     *
     * @return ProcessManager
     */
    public function getProcessManager(): ProcessManager
    {
        return $this->processManager;
    }
}

