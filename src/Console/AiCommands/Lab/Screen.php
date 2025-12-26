<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab;

use BrainCLI\Console\AiCommands\Lab\Abstracts\ScreenAbstract;
use BrainCLI\Console\AiCommands\Lab\Dto\Context;
use BrainCLI\Console\AiCommands\Lab\Dto\Tab;
use BrainCLI\Console\AiCommands\Lab\Prompts\CommandHistory;
use BrainCLI\Console\AiCommands\Lab\Prompts\CommandLinePrompt;
use BrainCLI\Console\AiCommands\Lab\TabBar;
use BrainCLI\Console\AiCommands\Lab\Traits\TermwindTrait;
use BrainCLI\Console\AiCommands\LabCommand;
use BrainCLI\Support\Brain;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Laravel\Prompts\Concerns\Colors;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Symfony\Component\VarExporter\VarExporter;

use function BrainCLI\Console\AiCommands\Lab\Prompts\commandline;
use function Laravel\Prompts\clear;
use function Laravel\Prompts\pause;
use function React\Promise\resolve;
use function Termwind\render;

/**
 * DSL REPL orchestrator for Brain Lab.
 *
 * Handles command parsing, direction routing (/, !, $, @, #, ^),
 * modifier processing (+, &, *), and screen dispatching.
 *
 * @see .docs/tor/lab-specification-part-1.md
 */
class Screen
{
    use Colors;
    use TermwindTrait;

    private const VALUE_MAX_LENGTH = 80;
    private const SHORT_VALUE_LENGTH = 40;

    protected Factory $components;

    protected CommandHistory $history;

    protected TabBar $tabBar;

    /** @var string Regex for parallel execution syntax *(cmd1)(cmd2) */
    public string $parallelInterfaceRegexp;

    /** @var string Regex for DSL command parsing [modifier][direction]command */
    public string $interfaceRegexp;

    /** @var string Regex for array slice operators (-N, --N) */
    public string $sliceRegexp;

    public function __construct(
        protected LabCommand $command,
        protected string $laboratoryPath,
    ) {
        $this->components = $this->command->outputComponents();
        $historyFile = implode(DS, [$this->laboratoryPath, '.lab_history']);
        $this->history = new CommandHistory($historyFile, 100);
        $this->tabBar = new TabBar();

        $modifierRegexp = "(?<modifier>\+|\&)?\s*";
        $commandRegexp = "(?<direction>[\/!?@\$#\^])(?<command>[A-Za-z0-9-\.]+)\s*(?<argument>[^)]*)";
        $this->parallelInterfaceRegexp = "/$modifierRegexp\*\(\s*$commandRegexp\s*\)/ms";
        $this->interfaceRegexp = "/^$modifierRegexp$commandRegexp$/ms";
        $this->sliceRegexp = "/^(?<modifier>[\-]{1,2})\s*(?<num>[\d]+)$/";
    }

    /**
     * Render the screen header panel.
     *
     * Displays the screen title in a styled header box using Termwind.
     *
     * @param string|null $title Optional title suffix to display
     * @return void
     */
    protected function drawHeader(string|null $title = null): void
    {
        $this->reboot(
            "AI Lab [".$this->command->getWorkspaceName()."]"
            . ($title ? " - " . $title : "")
        );
    }

    /**
     * Render tab bar from Context tab state.
     *
     * @param Context $response Current execution context
     * @return void
     */
    protected function drawTabBar(Context $response): void
    {
        $response->ensureMainTab();

        $tabs = $response->getAsArray('tabs') ?? [];
        $this->tabBar->render(array_values($tabs));
        $this->line('');
    }

    /**
     * Get active tab content or fallback to main result.
     *
     * @param Context $response Current execution context
     * @return mixed Active tab content or main result
     */
    protected function getActiveTabContent(Context $response): mixed
    {
        $activeTabId = $response->get('activeTab');
        $tabs = $response->getAsArray('tabs') ?? [];

        if ($activeTabId && isset($tabs[$activeTabId])) {
            return $tabs[$activeTabId]->content;
        }

        // Fallback to main result if no tabs
        return $response->getAsArray('result');
    }

    /**
     * Switch to next tab (Tab key handler).
     *
     * Navigates to the next tab in the tab list, wrapping around to the first tab
     * when at the end of the list. Updates tab states (Active/Inactive) accordingly.
     *
     * @param Context $response Current execution context
     * @return Context Updated context with new active tab
     */
    protected function switchToNextTab(Context $response): Context
    {
        $tabs = $response->getAsArray('tabs') ?? [];
        $activeTabId = $response->get('activeTab');

        if (empty($tabs)) {
            return $response;
        }

        $tabIds = array_keys($tabs);
        $currentIndex = array_search($activeTabId, $tabIds);

        // Move to next tab (wrap around to first)
        $nextIndex = ($currentIndex === false || $currentIndex >= count($tabIds) - 1)
            ? 0
            : $currentIndex + 1;

        $nextTabId = $tabIds[$nextIndex];

        // Update states
        if ($activeTabId && isset($tabs[$activeTabId])) {
            $tabs[$activeTabId]->markInactive();
        }
        $tabs[$nextTabId]->markActive();

        return $response->tabs($tabs)->activeTab($nextTabId);
    }

    /**
     * Switch to previous tab (Shift+Tab key handler).
     *
     * Navigates to the previous tab in the tab list, wrapping around to the last tab
     * when at the beginning of the list. Updates tab states (Active/Inactive) accordingly.
     *
     * @param Context $response Current execution context
     * @return Context Updated context with new active tab
     */
    protected function switchToPreviousTab(Context $response): Context
    {
        $tabs = $response->getAsArray('tabs') ?? [];
        $activeTabId = $response->get('activeTab');

        if (empty($tabs)) {
            return $response;
        }

        $tabIds = array_keys($tabs);
        $currentIndex = array_search($activeTabId, $tabIds);

        // Move to previous tab (wrap around to last)
        $prevIndex = ($currentIndex === false || $currentIndex <= 0)
            ? count($tabIds) - 1
            : $currentIndex - 1;

        $prevTabId = $tabIds[$prevIndex];

        // Update states
        if ($activeTabId && isset($tabs[$activeTabId])) {
            $tabs[$activeTabId]->markInactive();
        }
        $tabs[$prevTabId]->markActive();

        return $response->tabs($tabs)->activeTab($prevTabId);
    }

    /**
     * Main REPL loop for DSL command execution.
     *
     * Renders header, processes messages, displays dashboard,
     * accepts user input, and recursively processes commands.
     *
     * @param Context $response Current execution context
     * @return void
     */
    public function draw(Context $response): void
    {
        $this->drawHeader();
        $this->drawTabBar($response);
        $this->drawScreenResponseMessages($response);

        $this->renderVariablesPanel();

        $next = $this->drawScreenResponse($response);


        $command = commandline(
            label: "Enter command",
            options: function (string $value) use ($next, $response) {
                // Generate autocomplete options from screen/command patterns
                $return = $next['nextVariants'];
                $searchValue = trim($value);

                if (preg_match($this->interfaceRegexp, $value, $matches)) {
                    $modifier = $matches['modifier'];
                    $direction = $matches['direction'];
                    $command = $matches['command'];
                    $argument = $matches['argument'] ?: null;

                    if ($direction === '/') {
                        foreach ($this->command->screens() as $screen) {
                            $cmdName = $modifier.$screen->commandName();
                            if ($screen->name === $command) {
                                if (
                                    method_exists($screen, 'options')
                                    && is_array($validateArguments = $screen->validateArguments($argument, $response))
                                ) {
                                    foreach ($screen->options($searchValue, ...$validateArguments) as $key => $option) {
                                        $return[$cmdName . ' ' . $key] = [
                                            'value' => $cmdName . ' ' . $key,
                                            'label' => $option,
                                        ];
                                    }
                                }
                            }
                            $return[$cmdName] = [
                                'value' => $cmdName,
                                'label' => $screen->label(),
                            ];
                        }
                    }
                }

                // If user is typing, filter the options
                if ($searchValue !== '') {
                    $return = array_filter($return, function ($option, $key) use ($searchValue) {
                        return Str::contains(mb_strtolower($key), mb_strtolower($searchValue))
                            || Str::contains(mb_strtolower($option['label']), mb_strtolower($searchValue));
                    }, ARRAY_FILTER_USE_BOTH);
                }

                return $return;
            },
            placeholder: "Type your command here...",
            default: $next['nextCommand'],
            required: "This field is required!",
            validate: fn (string $value)
                => $value === ''
                || preg_match($this->parallelInterfaceRegexp, $value)
                || preg_match($this->interfaceRegexp, $value)
                || preg_match($this->sliceRegexp, $value)
                    ? null : 'Invalid command format.',
            hint: 'Enter "/help" to see available commands.',
            transform: fn (string $value) => trim($value),
            history: $this->history,
            statusLine: fn() => $this->getStatusLine($response),
        );

        $response = $this->submit($response, $command);

        $this->draw($response);
    }

    /**
     * Async REPL loop using ReactPHP promises.
     *
     * Same as draw() but returns a promise instead of blocking recursively.
     * Uses CommandLinePrompt::promptAsync() for non-blocking input.
     *
     * @param Context $response Current execution context
     * @param LoopInterface $loop ReactPHP event loop
     * @return PromiseInterface<Context> Promise that resolves with next context
     */
    public function drawAsync(Context $response, LoopInterface $loop): PromiseInterface
    {
        // C-2 FIX: Check shouldStop at START to prevent infinite promise chain
        if ($this->command->shouldStop) {
            return resolve($response);
        }

        // Render static UI (same as draw())
        $this->drawHeader();
        $this->drawScreenResponseMessages($response);
        $this->renderVariablesPanel();
        $next = $this->drawScreenResponse($response);

        // Build CommandLinePrompt instance (copy options logic from draw())
        $prompt = new CommandLinePrompt(
            label: "Enter command",
            options: function (string $value) use ($next, $response) {
                // Generate autocomplete options from screen/command patterns
                $return = $next['nextVariants'];
                $searchValue = trim($value);

                if (preg_match($this->interfaceRegexp, $value, $matches)) {
                    $modifier = $matches['modifier'];
                    $direction = $matches['direction'];
                    $command = $matches['command'];
                    $argument = $matches['argument'] ?: null;

                    if ($direction === '/') {
                        foreach ($this->command->screens() as $screen) {
                            $cmdName = $modifier.$screen->commandName();
                            if ($screen->name === $command) {
                                if (
                                    method_exists($screen, 'options')
                                    && is_array($validateArguments = $screen->validateArguments($argument, $response))
                                ) {
                                    foreach ($screen->options($searchValue, ...$validateArguments) as $key => $option) {
                                        $return[$cmdName . ' ' . $key] = [
                                            'value' => $cmdName . ' ' . $key,
                                            'label' => $option,
                                        ];
                                    }
                                }
                            }
                            $return[$cmdName] = [
                                'value' => $cmdName,
                                'label' => $screen->label(),
                            ];
                        }
                    }
                }

                // If user is typing, filter the options
                if ($searchValue !== '') {
                    $return = array_filter($return, function ($option, $key) use ($searchValue) {
                        return Str::contains(mb_strtolower($key), mb_strtolower($searchValue))
                            || Str::contains(mb_strtolower($option['label']), mb_strtolower($searchValue));
                    }, ARRAY_FILTER_USE_BOTH);
                }

                return $return;
            },
            placeholder: "Type your command here...",
            default: $next['nextCommand'],
            validate: fn (string $value)
                => $value === ''
                || preg_match($this->parallelInterfaceRegexp, $value)
                || preg_match($this->interfaceRegexp, $value)
                || preg_match($this->sliceRegexp, $value)
                    ? null : 'Invalid command format.',
            hint: 'Enter "/help" to see available commands.',
            transform: fn (string $value) => trim($value),
            history: $this->history,
        );

        // Set status line callback
        $prompt->withStatusLine(fn() => $this->getStatusLine($response));

        // Promise chain: promptAsync() → submit() → drawAsync()
        // Errors: If promptAsync rejects, chain breaks and error propagates to LabCommand.otherwise()
        // Termination: Chain ends when shouldStop=true (via SIGINT/SIGTERM)
        // Return promise chain
        return $prompt->promptAsync($loop, 1000)
            ->then(function (string $command) use ($response) {
                // Process command synchronously for now (Step 6 will make async)
                $newResponse = $this->submit($response, $command);
                return $newResponse;
            })
            ->then(function (Context $newResponse) use ($loop) {
                // M-2 FIX: Check shouldStop BEFORE recursive call to prevent unbounded chain growth
                if ($this->command->shouldStop) {
                    return resolve($newResponse);
                }
                // Loop back via promise chain (NOT recursion)
                return $this->drawAsync($newResponse, $loop);
            });
    }

    /**
     * Count of lines in the last rendered dashboard (for cursor positioning).
     */
    protected int $dashboardLines = 0;

    /**
     * Render the real-time dashboard panel.
     *
     * Displays memory statistics, variable count, and system metrics
     * in a formatted panel using Termwind.
     *
     * @param Context $context Current execution context
     * @return void
     */
    protected function dashboard(Context $context): void
    {
        $html = $this->renderDashboard($context);

        // Count lines for later refresh
        $this->dashboardLines = $this->countTermwindLines($html);

        render($html);

        $this->devider();
        $this->dashboardLines++; // Add 1 for divider
    }

    /**
     * Update dashboard panel in-place using ANSI cursor control.
     *
     * Moves cursor up by dashboardLines count, clears lines,
     * and re-renders the dashboard without screen flicker.
     *
     * @param Context $context Current execution context
     * @param int $promptLines Number of prompt lines to account for
     * @return void
     */
    protected function refreshDashboard(Context $context, int $promptLines): void
    {
        // Calculate total lines to move up (dashboard + divider + prompt)
        $linesToMoveUp = $this->dashboardLines + $promptLines;

        // Save cursor position and move up
        echo "\033[s"; // Save cursor
        echo "\033[{$linesToMoveUp}A"; // Move up N lines
        echo "\033[0J"; // Clear from cursor to end of screen

        // Re-render dashboard
        render($this->renderDashboard($context));
        $this->devider();

        echo "\033[u"; // Restore cursor position
    }

    /**
     * Count approximate lines that Termwind HTML will produce.
     */
    protected function countTermwindLines(string $html): int
    {
        // Capture Termwind output to count lines
        ob_start();
        render($html);
        $output = ob_get_clean();

        return substr_count($output, "\n") + 1;
    }

    protected function renderDashboard(Context $context): string
    {
        $variables = $this->command->workSpace->variables;
        $varCount = count($variables);

        $now = date('H:i:s');
        $memoryUsage = Number::fileSize(
            strlen(json_encode($variables))
            + strlen(json_encode($context->toArray())),
        );

        return <<<HTML
<div>
    <table>
        <thead>
            <tr>
                <th class="text-cyan-400">&</th>
                <th class="text-green-400">$</th>
                <th class="text-purple-400">Mem</th>
                <th class="text-gray-400">Time</th>
            </tr>
        </thead>
        <tr>
            <td class="text-white font-bold">0</td>
            <td class="text-green-300">{$varCount}</td>
            <td class="text-yellow-300">{$memoryUsage}</td>
            <td class="text-gray-500">{$now}</td>
        </tr>
    </table>
</div>
HTML;
    }

    protected function renderVariablesPanel(): void
    {
        $variables = $this->command->workSpace->variables;
        $varCount = count($variables);

        if ($varCount === 0) {
            return;
        }

        $rows = '';
        foreach ($variables as $name => $value) {
            $type = $this->getVariableType($value);
            $typeColor = $this->getTypeColor($type);
            $preview = htmlspecialchars($this->getValuePreview($value));

            $rows .= <<<HTML
<tr>
    <td class="text-yellow-400 font-bold">\${$name}</td>
    <td class="{$typeColor}">{$preview}</td>
    <td class="text-gray-600">{$type}</td>
</tr>
HTML;
        }

        render(<<<HTML
<div class="mt-1">
    <table>
        <thead>
            <tr>
                <th class="text-gray-500">Name</th>
                <th class="text-gray-500">Value</th>
                <th class="text-gray-500">Type</th>
            </tr>
        </thead>
        {$rows}
    </table>
</div>
HTML);
    }

    protected function getVariableType(mixed $value): string
    {
        return match (true) {
            is_array($value) => 'array',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_string($value) => 'string',
            is_null($value) => 'null',
            is_object($value) => 'object',
            default => 'mixed',
        };
    }

    protected function getTypeColor(string $type): string
    {
        return match ($type) {
            'array' => 'text-blue-400',
            'bool' => 'text-red-400',
            'int', 'float' => 'text-green-400',
            'string' => 'text-yellow-300',
            'null' => 'text-gray-500',
            'object' => 'text-cyan-400',
            default => 'text-white',
        };
    }

    protected function getValuePreview(mixed $value): string
    {
        return match (true) {
            is_array($value) => Arr::isAssoc($value)
                ? 'Assoc(' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ')'
                : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => '"' . (mb_strlen($value) > self::SHORT_VALUE_LENGTH ? mb_substr($value, 0, 37) . '...' : $value) . '"',
            is_null($value) => 'null',
            is_object($value) => get_class($value),
            default => (string) $value,
        };
    }

    /**
     * Get status line for real-time display (updates every second).
     */
    protected function getStatusLine(Context $context): string
    {
        $variables = $this->command->workSpace->variables;
        $varCount = count($variables);
        $now = date('H:i:s');
        $memoryUsage = Number::fileSize(
            strlen(json_encode($variables))
            + strlen(json_encode($context->toArray())),
        );

        return "& 0 | \$ {$varCount} | Mem {$memoryUsage} | Time {$now}";
    }

    /**
     * DSL parsing and execution entry point.
     *
     * Processes user input through multiple patterns:
     * - Interface commands: [modifier][direction]command
     * - Parallel execution: *(cmd1) *(cmd2)
     * - Array slicing: -N (last N), --N (skip first N)
     * - Command chaining via << operator
     *
     * @param Context $response Current execution context
     * @param string $input Raw user input to parse and execute
     * @return Context Updated context after command processing
     */
    public function submit(Context $response, string $command): Context
    {
        // Handle tab navigation commands from keyboard events (Step 5)
        if (str_contains($command, CommandLinePrompt::EVENT_TAB_NEXT)) {
            return $this->switchToNextTab($response);
        }
        if (str_contains($command, CommandLinePrompt::EVENT_TAB_PREV)) {
            return $this->switchToPreviousTab($response);
        }

        if (preg_match($this->interfaceRegexp, $command, $matches)) {
            // Capture groups: modifier (+,&,*), direction (/,!,$,@,#,^), command, argument
            $modifier = $matches['modifier'];
            $direction = $matches['direction'];
            $command = $matches['command'];
            $argument = $matches['argument'] ?: null;
            $response = $this->submitInterface(
                $response, $modifier, $direction, $command, $argument
            );
        } elseif(preg_match_all($this->parallelInterfaceRegexp, $command, $matches)) {
            // Execute parallel commands sequentially, merge results into single context
            $allResponses = [];
            $count = count($matches['command']);
            $firstModifier = $matches['modifier'][0];
            for ($i = 0; $i < $count; $i++) {
                $direction = $matches['direction'][$i];
                $command = $matches['command'][$i];
                $argument = $matches['argument'][$i] ?: null;
                $allResponses[] = $this->submitInterface(
                    Context::fromEmpty()->merge($response),
                    $firstModifier, $direction, $command, $argument
                );
            }
            // Merge all responses into one
            foreach ($allResponses as $individualResponse) {
                $response->merge($individualResponse);
            }
        } elseif (preg_match($this->sliceRegexp, $command, $matches)) {
            $modifier = $matches['modifier'];
            $num = (int)$matches['num'];
            if (! $num) {
                $response->result(null);
            } else {
                if ($response->isNotEmpty('result')) {
                    $result = $response->get('result');
                    if (is_array($result)) {
                        if ($modifier === '-') {
                            $result = array_slice($result, 0, -$num);
                        } else {
                            $result = array_slice($result, $num);
                        }
                        $response->result($result);
                    }
                }
            }
        } else {
            $response->error("Invalid command format.");
        }
        return $response;
    }

    /**
     * Route parsed interface command through direction handler.
     *
     * Extracts modifier, direction, command, and arguments from parsed input,
     * then delegates to commandDirection() for execution. Handles pause states.
     *
     * @param Context $response Current execution context
     * @param string $modifier Command modifier (+, &, *)
     * @param string $direction Command direction (/, !, $, @, #, ^)
     * @param string $command Command name to execute
     * @param string|null $argument Optional command arguments
     * @return Context Updated context after command processing
     */
    protected function submitInterface(
        Context $response,
        string $modifier, string $direction, string $command, string|null $argument
    ): Context
    {
        $response = $this->commandDirection($response, $modifier, $direction, $command, $argument);

        if ($response->isNotEmpty('pause')) {
            $pauseMsg = $response->get('pause');
            pause(is_string($pauseMsg) && $pauseMsg ? $pauseMsg : 'Press ENTER to continue.');
        }

        return $response;
    }

    /**
     * Handle screen routing for '/' and '@' directions.
     * Extracts common logic for screen lookup, validation, and execution.
     */
    private function handleScreenRouting(
        string $command,
        string|null $argument,
        Context &$response,
        string $modifier,
        string $direction
    ): void {
        $validateArguments = [];
        /** @var ScreenAbstract|null $commandDetails */
        $commandDetails = $this->command->screens()->filter(function (ScreenAbstract $screen) use ($command, &$validateArguments) {
            if ($screen->detectRegexp && preg_match($screen->detectRegexp, $command, $matches)) {
                foreach ($matches as $key => $match) {
                    if (is_numeric($key) && $key > 0) {
                        $validateArguments[] = $match;
                    }
                }
                return true;
            }
            return $screen->name === $command;
        })->first();

        if ($commandDetails) {
            if (! method_exists($commandDetails, 'main')) {
                $response->error("Command not implemented: " . $command)
                    ->nextCommand($modifier.$direction.$command, $argument);
                return;
            }

            $args = $commandDetails->validateArguments($argument, $response);

            if (is_bool($args)) {
                if ($args === false) {
                    $response->error("Invalid argument for command: " . $command)
                        ->nextCommand($modifier.$direction.$command, $argument);
                    return;
                }
                $args = [];
            } elseif (is_string($args)) {
                $response->error(explode(PHP_EOL, $args))
                    ->nextCommand($modifier.$direction.$command, $argument);
                return;
            }

            foreach ($args as $key => $validateArgument) {
                if (is_numeric($key)) {
                    $validateArguments[] = $validateArgument;
                } else {
                    $validateArguments[$key] = $validateArgument;
                }
            }

            // Check if debug/isolated mode is enabled
            $isDebugMode = ($modifier === '&');

            // If debug mode, clone the response to work in isolation
            $workingResponse = $isDebugMode ? clone $response : $response;

            $this->drawHeader($commandDetails->title . ($isDebugMode ? ' [DEBUG MODE]' : ''));
            try {
                // Propagate meta from response to screen (for TransformScreen modifier support)
                if ($metaModifier = $workingResponse->getMeta('modifier')) {
                    $commandDetails->setMeta('modifier', $metaModifier);
                }

                $workingResponse = $commandDetails->main($workingResponse, ...$validateArguments);

                // Handle debug mode output
                if ($isDebugMode) {
                    // Show debug info but don't persist to main response
                    $response->info("[DEBUG] Executed in isolated mode - results not persisted");

                    // Show what the result would have been
                    if ($result = $workingResponse->getAsArray('result')) {
                        $response->info("[DEBUG] Result would be: " . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    }

                    // Show any errors that occurred
                    if ($errors = $workingResponse->getAsArray('error')) {
                        $response->info("[DEBUG] Errors: " . json_encode($errors, JSON_PRETTY_PRINT));
                    }

                    // Show any warnings
                    if ($warnings = $workingResponse->getAsArray('warning')) {
                        $response->info("[DEBUG] Warnings: " . json_encode($warnings, JSON_PRETTY_PRINT));
                    }

                    // Show any info messages
                    if ($infos = $workingResponse->getAsArray('info')) {
                        $response->info("[DEBUG] Info: " . json_encode($infos, JSON_PRETTY_PRINT));
                    }

                    // Show any success messages
                    if ($successes = $workingResponse->getAsArray('success')) {
                        $response->info("[DEBUG] Success: " . json_encode($successes, JSON_PRETTY_PRINT));
                    }
                } else {
                    // Normal mode - use the working response
                    $response = $workingResponse;
                }
            } catch (\Throwable $e) {
                if (Brain::isDebug()) {
                    dd($e);
                }
                $response->error("Error executing command '{$command}': " . $e->getMessage())
                    ->nextCommand($modifier.$direction.$command, $argument);

                if ($isDebugMode) {
                    $response->info("[DEBUG] Exception occurred in isolated mode - main context not affected");
                }
            }
        } else {
            $response->error("Unknown command: " . $command);
        }
    }

    /**
     * Direction router for DSL commands.
     *
     * Routes commands based on direction prefix:
     * - / : Screen commands (internal navigation)
     * - @ : Extension screen commands
     * - ! : Process/shell execution
     * - # : Comments and notes
     * - ^ : Transform pipeline
     * - $ : Variable management
     *
     * Includes recursion depth limiting (max 10) for ^ direction.
     *
     * @param Context $response Current execution context
     * @param string $modifier Command modifier (+, &, *)
     * @param string $direction Direction prefix character
     * @param string $command Command name
     * @param string|null $argument Optional arguments
     * @param int $depth Current recursion depth (default 0)
     * @return Context Updated context after direction handling
     */
    protected function commandDirection(
        Context $response,
        string $modifier, string $direction, string $command, string|null $argument,
        int $depth = 0
    ): Context {
        if ($depth > 10) {
            $response->error("Maximum recursion depth exceeded (10 levels). Aborting transform chain.");
            return $response;
        }

        match ($direction) {
            // Route to internal screen or extension screen based on direction and command name
            '/' => $this->handleScreenRouting($command, $argument, $response, $modifier, $direction),
            '@' => $this->handleScreenRouting($command, $argument, $response, $modifier, $direction),
            '!' => (function () use ($modifier, $command, $argument, &$response) {
                $debug = false;
                if ($argument) {
                    if (str_ends_with($argument, '-dbg')) {
                        $argument = trim(substr($argument, 0, -4)) ?: null;
                        $debug = true;
                    } elseif (str_ends_with($argument, '--debug')) {
                        $argument = trim(substr($argument, 0, -7)) ?: null;
                        $debug = true;
                    }
                }
                $output = $this->command->process($command, $argument);
                $results = $response->getAsArray('result');
                $response = $response
                    ->result(
                        $debug ? [$command . count($results) => $output] : $output['body'],
                        $modifier === '+' || $debug
                    );

                if ($output['status'] !== OK) {
                    $response->error("Command execution failed.");
                }
            })(),
            '#' => (function () use ($modifier, $command, $argument, &$response) {
                // Comment/note direction - logs to context for display
                // Format: #command text (e.g., #note TODO item, #warn check this)
                $text = trim($command . ' ' . $argument);

                // Route based on command prefix
                match (strtolower($command)) {
                    'warn', 'warning' => $response->warning($text),
                    'error', 'err' => $response->error($text),
                    'success', 'ok' => $response->success($text),
                    'info', 'note' => $response->info($text),
                    default => $response->info($text),
                };
            })(),
            '^' => (function () use ($modifier, $command, $argument, &$response, $depth) {
                // Route to TransformScreen - applies transformation to current result
                // Format: ^transform or ^transform:param (e.g., ^upper, ^pluck:name, ^take:5)

                // Parse command:param format
                $parts = explode(':', $command, 2);
                $transform = $parts[0];
                $param = $parts[1] ?? $argument ?? '';

                // Store modifier in response meta for TransformScreen to use
                $response->setMeta('modifier', $modifier);

                // Route to transform screen via commandDirection
                $response = $this->commandDirection(
                    $response,
                    $modifier,
                    '/',
                    'transform',
                    trim($transform . ' ' . $param),
                    $depth + 1
                );
            })(),
            '$' => (function () use ($modifier, $command, $argument, &$response) {
                $response = $this->commandDirection(
                    $response,
                    $modifier,
                    '/',
                    'var',
                    trim($command . ' ' . $argument)
                );
            })(),
        };
        return $response;
    }

    public function reboot(string $msg = null, string $component = 'success'): void
    {
        clear();
        $this->command->line('');
        if ($msg) {
            $this->devider();
            $this->components->$component($msg);
            $this->devider();
        }
    }

    public function devider(): void
    {
        render('<hr />');
    }

    /**
     * @param  \BrainCLI\Console\AiCommands\Lab\Dto\Context  $response
     */
    protected function drawScreenResponseMessages(Context $response): void
    {
        if ($response->isNotEmpty('info')) {
            foreach ($response->getAsArray('info') as $item) {
                $this->components->info($item);
                $this->devider();
            }
        }
        if ($response->isNotEmpty('error')) {
            foreach ($response->getAsArray('error') as $item) {
                $this->components->error($item);
                $this->devider();
            }
        }
        if ($response->isNotEmpty('success')) {
            foreach ($response->getAsArray('success') as $item) {
                $this->components->success($item);
                $this->devider();
            }
        }
        if ($response->isNotEmpty('warning')) {
            foreach ($response->getAsArray('warning') as $item) {
                $this->components->warn($item);
                $this->devider();
            }
        }
    }

    /**
     * @param  \BrainCLI\Console\AiCommands\Lab\Dto\Context  $response
     * @return array{nextCommand: string, nextVariants: array<string, array{value: string, label: string}>}
     */
    protected function drawScreenResponse(Context $response): array
    {
        if ($response->isNotEmpty('result')) {
            $result = $response->get('result');
            if (is_string($result)) {
                $this->command->line($result);
            } else {
                if (is_array($result)) {

                    foreach ($result as $key => $item) {
                        $dateTime = date('H:i');
                        if (! is_null($item)) {
                            if (! is_string($item)) {
                                try {
                                    $item = VarExporter::export($item);
                                } catch (\Throwable $e) {
                                    $item = print_r($item, true);
                                }
                            }
                        }

                        $key = "\$$key";

                        $mallValue = null;

                        if ($item === null) {
                            $mallValue = '<span class="text-gray-500">(null)</span>';
                        } elseif (! str_contains($item, "\n") && mb_strlen($item) <= self::VALUE_MAX_LENGTH) {
                            $mallValue = $item;
                            $item = null;
                        }

                        render(<<<HTML
<div>
<span class="mt-1 bg-blue-600">[$dateTime]</span>
<span class="text-green-300 pl-1">{$key}:</span>
<span class="pl-1">$mallValue</span>
</div>
HTML);

                        if ($item !== null) {
                            $lines = explode("\n", $item);
                            $lineCount = count($lines);
                            $cut = 100;
                            if ($lineCount > $cut) {
                                $dots = '';
                                foreach ($lines as $key => $line) {
                                    if ($key < $cut) {
                                        if ($dots) {
                                            $dots .= PHP_EOL;
                                        }
                                        $dots .= $line;
                                    } else {
                                        $dots .= ".";
                                    }
                                }
                                if ($dots !== '') $this->command->line($dots);
                            } else {
                                $this->command->line($item);
                            }
                        }
                    }


                } else {
                    dump($result);
                }
            }
            $this->devider();
        }

        $result = [
            'nextCommand' => $response->getAsString('nextCommand'),
            'nextVariants' => $response->getAsArray('nextVariants')
        ];

        $response->clearMeta();

        return $result;
    }
}
