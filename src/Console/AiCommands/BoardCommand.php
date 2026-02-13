<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Console\AiCommands\Lab\Prompts\CommandHistory;
use BrainCLI\Dto\ProcessOutput\Init;
use BrainCLI\Dto\ProcessOutput\Message;
use BrainCLI\Dto\ProcessOutput\Result;
use BrainCLI\Support\Brain;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

use function BrainCLI\Console\AiCommands\Lab\Prompts\commandline;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\textarea;
use function Laravel\Prompts\warning;

class BoardCommand extends CommandBridgeAbstract
{
    protected array $signatureParts = [
        '{name? : Board name for create/resume}',
    ];

    /**
     * Board state.
     *
     * @var array{
     *     id: string,
     *     name: string|null,
     *     created_at: string,
     *     updated_at: string,
     *     agents: array<string, array{session_id: string|null, role: string|null, yaml_file: string}>,
     *     responses: array<string, list<string>>
     * }
     */
    protected array $state = [];

    /**
     * Path to the current board state file.
     */
    protected string $statePath = '';

    /**
     * Input history for command recall (up/down arrows).
     */
    protected CommandHistory $inputHistory;

    /**
     * Available board slash commands.
     *
     * @var list<string>
     */
    protected array $commands = [
        '/add',
        '/remove',
        '/fire',
        '/role',
        '/compose',
        '/list',
        '/help',
        '/exit',
        '/boards',
    ];

    public function __construct()
    {
        $this->signature = 'board';
        foreach ($this->signatureParts as $part) {
            $this->signature .= ' ' . $part;
        }
        $this->description = 'Interactive multi-agent chat board';
        parent::__construct();
    }

    protected function handleBridge(): int|array
    {
        $name = $this->argument('name');

        $this->ensureBoardsDirectory();

        if ($name) {
            $id = md5($name);
        } else {
            $id = $this->pickOrCreateBoard();
            if ($id === null) {
                return OK;
            }
        }

        $this->statePath = $this->boardsPath($id . '.json');
        $this->inputHistory = new CommandHistory($this->boardsPath($id . '.history'), 100);
        $this->loadState($id, is_string($name) ? $name : null);

        $displayName = $this->state['name'] ?? $this->state['id'];
        info("Board: {$displayName}");

        if (count($this->state['agents'])) {
            $this->showAgentList();
        } else {
            warning('No agents on board. Use /add {name} to add an agent.');
        }

        $this->interactiveLoop();

        return OK;
    }

    // ──────────────────────────────────────────────
    // Interactive loop
    // ──────────────────────────────────────────────

    protected function interactiveLoop(): void
    {
        while (true) {
            $input = commandline(
                label: 'Message',
                options: function (string $value) {
                    if (str_starts_with($value, '/')) {
                        return array_filter(
                            $this->commands,
                            fn (string $cmd) => str_starts_with($cmd, $value)
                        );
                    }

                    if (str_starts_with($value, '@')) {
                        // Chain continuation: @agent->@ or @agent+@
                        if (preg_match('/(?:->|[+])@([a-zA-Z0-9_\-]*)$/', $value, $chainMatch)) {
                            return $this->buildChainSuggestions($value, $chainMatch[1]);
                        }

                        // Single agent prefix
                        $prefix = strtolower(substr($value, 1));
                        $agents = [];
                        foreach (array_keys($this->state['agents']) as $agent) {
                            if ($prefix === '' || str_starts_with(strtolower($agent), $prefix)) {
                                $agents[] = '@' . $agent . ' ';
                            }
                        }
                        return $agents;
                    }

                    // Suggest @name[N] refs when typing @ mid-message
                    if (preg_match('/@(\S*)$/', $value)) {
                        return $this->buildResponseRefSuggestions($value);
                    }

                    return [];
                },
                placeholder: $this->inputPlaceholder(),
                history: $this->inputHistory,
            );

            $input = trim($input);
            if ($input === '') {
                continue;
            }

            if (str_starts_with($input, '/')) {
                if ($this->handleSlashCommand($input) === false) {
                    break;
                }
                continue;
            }

            if (str_starts_with($input, '!')) {
                $this->executeSystemCommand(substr($input, 1));
                continue;
            }

            $this->handleMessage($input);
        }
    }

    protected function inputPlaceholder(): string
    {
        if (count($this->state['agents']) === 0) {
            return 'note, /add {name}, or /command...';
        }
        return 'note, @agent message, !cmd, or /command...';
    }

    // ──────────────────────────────────────────────
    // Slash command handling
    // ──────────────────────────────────────────────

    /**
     * @return bool False to exit the loop.
     */
    protected function handleSlashCommand(string $input): bool
    {
        $parts = preg_split('/\s+/', $input, 3);
        $command = strtolower($parts[0] ?? '');
        $arg1 = $parts[1] ?? null;
        $arg2 = $parts[2] ?? null;

        return match ($command) {
            '/add' => $this->cmdAdd($arg1, $arg2),
            '/remove' => $this->cmdRemoveMessage($arg1),
            '/fire' => $this->cmdFire($arg1),
            '/compose' => $this->cmdCompose($arg1),
            '/role' => $this->cmdRole($arg1, $arg2),
            '/list' => $this->cmdList(),
            '/help' => $this->cmdHelp(),
            '/boards' => $this->cmdBoards(),
            '/exit' => false,
            default => $this->cmdUnknown($command),
        };
    }

    protected function cmdAdd(?string $name, ?string $role): bool
    {
        if (! $name) {
            $available = $this->availableYamlAgents();
            if (empty($available)) {
                error('No .ai/*.yaml agents found.');
                return true;
            }
            $name = select(
                label: 'Select agent to add',
                options: $available,
            );
        }

        // Parse alias syntax: @source(alias) or source(alias)
        $boardName = ltrim($name, '@');
        $sourceName = $boardName;

        if (preg_match('/^([a-zA-Z0-9_\-]+)\(([a-zA-Z0-9_\-]+)\)$/', $boardName, $aliasMatch)) {
            $sourceName = $aliasMatch[1];
            $boardName = $aliasMatch[2];
        }

        $yamlFile = $sourceName . '.yaml';
        $yamlPath = Brain::projectDirectory('.ai' . DS . $yamlFile);

        if (! is_file($yamlPath)) {
            $yamlFile = $sourceName . '.yml';
            $yamlPath = Brain::projectDirectory('.ai' . DS . $yamlFile);
        }

        if (! is_file($yamlPath)) {
            error("Agent file not found: .ai/{$sourceName}.yaml");
            return true;
        }

        if (isset($this->state['agents'][$boardName])) {
            warning("Agent '{$boardName}' is already on the board.");
            return true;
        }

        // Restore fired agent if same board name exists
        if (isset($this->state['fired'][$boardName])) {
            $fired = $this->state['fired'][$boardName];
            $this->state['agents'][$boardName] = [
                'session_id' => $fired['session_id'],
                'role' => $role ?? $fired['role'],
                'yaml_file' => $yamlFile,
            ];
            unset($this->state['fired'][$boardName]);
            $this->saveState();

            $label = "@{$boardName}";
            if ($boardName !== $sourceName) {
                $label .= " (from {$sourceName})";
            }
            info("Restored {$label} with previous session" . ($role ? ", role: {$role}" : ''));
            return true;
        }

        $this->state['agents'][$boardName] = [
            'session_id' => null,
            'role' => $role,
            'yaml_file' => $yamlFile,
        ];
        $this->saveState();

        $label = "@{$boardName}";
        if ($boardName !== $sourceName) {
            $label .= " (from {$sourceName})";
        }
        info("Added {$label}" . ($role ? " with role: {$role}" : ''));
        return true;
    }

    protected function cmdRemoveMessage(?string $ref): bool
    {
        if (! $ref || ! preg_match('/^@([a-zA-Z0-9_\-]+)\[(\d+)\]$/', $ref, $matches)) {
            error('Usage: /remove @name[N]  (e.g. /remove @user[1], /remove @executor[0])');
            return true;
        }

        $entity = $matches[1];
        $index = (int) $matches[2];

        $responses = $this->state['responses'][$entity] ?? [];
        if (! isset($responses[$index]) || $responses[$index] === null) {
            error("@{$entity}[{$index}] not found or already removed.");
            return true;
        }

        $preview = mb_substr($responses[$index], 0, 60);
        if (mb_strlen($responses[$index]) > 60) {
            $preview .= '...';
        }

        if (! confirm("Remove @{$entity}[{$index}]: \"{$preview}\"?", default: false)) {
            return true;
        }

        $this->state['responses'][$entity][$index] = null;
        $this->saveState();
        $this->redrawBoard();

        return true;
    }

    protected function cmdFire(?string $name): bool
    {
        if (! $name) {
            if (empty($this->state['agents'])) {
                warning('No agents on the board.');
                return true;
            }
            $name = select(
                label: 'Select agent to fire',
                options: array_keys($this->state['agents']),
            );
        }

        $name = ltrim($name, '@');

        if (! isset($this->state['agents'][$name])) {
            error("Agent '{$name}' is not on the board.");
            return true;
        }

        if (! confirm("Fire @{$name} from the board? (data preserved for re-add)", default: false)) {
            return true;
        }

        $this->state['fired'][$name] = $this->state['agents'][$name];
        unset($this->state['agents'][$name]);
        $this->saveState();
        info("Fired @{$name}. Use /add {$name} to restore.");
        return true;
    }

    protected function cmdRole(?string $name, ?string $roleText): bool
    {
        if (! $name) {
            if (empty($this->state['agents'])) {
                warning('No agents on the board.');
                return true;
            }
            $name = select(
                label: 'Select agent',
                options: array_keys($this->state['agents']),
            );
        }

        if (! isset($this->state['agents'][$name])) {
            error("Agent '{$name}' is not on the board.");
            return true;
        }

        // No role text — show current, then prompt for new
        if ($roleText === null) {
            $boardRole = $this->state['agents'][$name]['role'] ?? null;
            $yamlRole = $this->readYamlRole($name);

            if ($boardRole) {
                info("@{$name} role (board override): {$boardRole}");
                if ($yamlRole) {
                    $this->line($this->dim("  yaml default: {$yamlRole}"));
                }
            } elseif ($yamlRole) {
                info("@{$name} role (from yaml): {$yamlRole}");
            } else {
                warning("@{$name} has no role.");
            }

            $roleText = trim(commandline(
                label: "New role for @{$name}",
                placeholder: 'Type role, "-" to reset, or empty to skip...',
            ));

            if ($roleText === '') {
                return true;
            }
        }

        // Reset: "/role agent -" removes board override
        if ($roleText === '-') {
            $this->state['agents'][$name]['role'] = null;
            $this->saveState();
            $yamlRole = $this->readYamlRole($name);
            if ($yamlRole) {
                info("@{$name} role reset to yaml default: {$yamlRole}");
            } else {
                info("@{$name} board role removed (no yaml default).");
            }
            return true;
        }

        // Set board override
        $this->state['agents'][$name]['role'] = $roleText;
        $this->saveState();
        info("@{$name} role set: {$roleText}");
        return true;
    }

    protected function cmdCompose(?string $target): bool
    {
        // Determine target agent(s)
        if ($target) {
            $target = ltrim($target, '@');
        } elseif (count($this->state['agents']) === 1) {
            $target = array_key_first($this->state['agents']);
        } elseif (count($this->state['agents']) > 1) {
            $target = select(
                label: 'Compose message to',
                options: array_keys($this->state['agents']),
            );
        } else {
            warning('No agents on the board.');
            return true;
        }

        $text = trim(textarea(
            label: "Compose to @{$target}",
            placeholder: 'Write your message (Enter = newline, Ctrl+D = send)...',
        ));

        if ($text === '') {
            return true;
        }

        $this->handleMessage("@{$target} {$text}");
        return true;
    }

    protected function cmdList(): bool
    {
        $this->showAgentList();
        return true;
    }

    protected function cmdHelp(): bool
    {
        info('Board commands:');
        $this->line('  /add @agent [role]            - Add agent (restores if fired)');
        $this->line('  /add @agent({alias}) [role]  - Add alias based on source agent');
        $this->line('  /remove @name[N]              - Remove message (with confirm)');
        $this->line('  /fire @agent                  - Fire agent (data preserved)');
        $this->line('  /role @agent [text]           - Show/set board role override');
        $this->line('  /role @agent -                - Reset to yaml default role');
        $this->line('  /compose [@agent]             - Multiline message (Enter=newline, Ctrl+D=send)');
        $this->line('  /list                         - Show agents on board');
        $this->line('  /boards                       - List all saved boards');
        $this->line('  /help                         - Show this help');
        $this->line('  /exit                         - Save and exit');
        $this->line('');
        $this->line('  @agent message             - Send message to specific agent');
        $this->line('  @a->@b->@c message         - Pipeline: response chains forward');
        $this->line('  @a+@b+@c message           - Broadcast: same message to each');
        $this->line('  !command                   - Run shell command, store as @system[N]');
        $this->line('  plain text                 - Store as @user[N] note');
        $this->line('');
        $this->line('  @agent[N]                  - Reference agent response #N');
        $this->line('  @user[N]                   - Reference user message #N');
        $this->line('  @system[N]                 - Reference system output #N');
        $this->line('');
        $this->line('  Pipeline:  @executor->@validator Fix this bug');
        $this->line('    executor gets message, validator gets message + executor reply');
        $this->line('');
        $this->line('  Broadcast: @executor+@validator Review this code');
        $this->line('    both get the same message independently, sequentially');
        $this->line('');
        $this->line('  Alias:     /add executor(explorer) Code explorer');
        $this->line('    creates @explorer based on executor.yaml with custom role');
        return true;
    }

    protected function cmdBoards(): bool
    {
        $boards = $this->listAllBoards();
        if (empty($boards)) {
            warning('No saved boards.');
            return true;
        }

        table(
            headers: ['Name', 'Agents', 'Updated'],
            rows: array_map(fn (array $b) => [
                $b['name'] ?? $b['id'],
                (string) count($b['agents'] ?? []),
                $b['updated_at'] ?? '-',
            ], $boards),
        );

        return true;
    }

    protected function cmdUnknown(string $command): bool
    {
        error("Unknown command: {$command}. Type /help for available commands.");
        return true;
    }

    // ──────────────────────────────────────────────
    // Message handling
    // ──────────────────────────────────────────────

    protected function handleMessage(string $input): void
    {
        // Plain text without @target — just a user note
        if (! str_starts_with($input, '@')) {
            $userIndex = $this->storeResponse('user', $input);
            $this->displayMessage('user', $userIndex, $input);

            $this->saveState();
            return;
        }

        // Parse: @target message
        if (preg_match('/^(@\S+)\s+(.+)$/s', $input, $matches)) {
            $targetRaw = $matches[1];
            $message = $matches[2];
        } elseif (preg_match('/^(@\S+)\s*$/', $input, $matches)) {
            $targetRaw = $matches[1];
            $message = $this->promptForMessage($targetRaw);
            if ($message === null) {
                return;
            }
        } else {
            error('Usage: @agent message');
            return;
        }

        // Parse target into agent list and chain mode
        $chain = $this->parseChainTarget($targetRaw);

        if ($chain === null) {
            return;
        }

        // Store user message with index
        $userIndex = $this->storeResponse('user', $message);
        $this->displayMessage('user', $userIndex, $message);

        $message = $this->resolveResponseReferences($message);

        match ($chain['mode']) {
            'single' => $this->executeSingle($chain['agents'][0], $message),
            'pipeline' => $this->executePipeline($chain['agents'], $message),
            'broadcast' => $this->executeBroadcast($chain['agents'], $message),
        };
    }

    /**
     * Prompt user for message when only target was provided (no message body).
     */
    protected function promptForMessage(string $targetRaw): ?string
    {
        $message = commandline(
            label: "Message to {$targetRaw}",
            options: function (string $value) {
                if (preg_match('/@\S*$/', $value)) {
                    return $this->buildResponseRefSuggestions($value);
                }
                return [];
            },
            placeholder: 'Type your message...',
        );
        $message = trim($message);

        return $message !== '' ? $message : null;
    }

    /**
     * Parse target string into chain mode and agent list.
     *
     * Supports:
     *   @agent                      → single
     *   @agent1->@agent2->@agent3   → pipeline (response chains forward)
     *   @agent1+@agent2+@agent3     → broadcast (same message to each)
     *   null                        → single with auto-select
     *
     * @return array{mode: 'single'|'pipeline'|'broadcast', agents: list<string>}|null
     */
    protected function parseChainTarget(?string $targetRaw): ?array
    {
        // No target — auto-select single agent
        if ($targetRaw === null) {
            $agentNames = array_keys($this->state['agents']);

            if (count($agentNames) === 1) {
                return ['mode' => 'single', 'agents' => [$agentNames[0]]];
            }

            $selected = select(
                label: 'Send to which agent?',
                options: $agentNames,
            );

            return ['mode' => 'single', 'agents' => [$selected]];
        }

        // Detect chain operator
        if (str_contains($targetRaw, '->')) {
            $mode = 'pipeline';
            $parts = explode('->', $targetRaw);
        } elseif (str_contains($targetRaw, '+')) {
            $mode = 'broadcast';
            $parts = explode('+', $targetRaw);
        } else {
            $mode = 'single';
            $parts = [$targetRaw];
        }

        // Clean agent names (strip leading @)
        $agents = [];
        foreach ($parts as $part) {
            $name = ltrim(trim($part), '@');
            if ($name === '') {
                continue;
            }
            if (! isset($this->state['agents'][$name])) {
                error("Agent '{$name}' is not on the board.");
                return null;
            }
            $agents[] = $name;
        }

        if (count($agents) < 1) {
            error('No valid agents in target.');
            return null;
        }

        // Downgrade to single if only one agent after parsing
        if (count($agents) === 1) {
            $mode = 'single';
        }

        return ['mode' => $mode, 'agents' => $agents];
    }

    // ──────────────────────────────────────────────
    // Chain executors
    // ──────────────────────────────────────────────

    /**
     * Single agent message.
     */
    protected function executeSingle(string $agent, string $message): void
    {
        $this->sendToAgent($agent, $message);
    }

    /**
     * Pipeline chain: @a->@b->@c
     *
     * Each agent receives the original message + all previous responses as context.
     * Response of each step is appended for the next agent.
     */
    protected function executePipeline(array $agents, string $message): void
    {
        $chainLabel = '@' . implode('->@', $agents);
        $total = count($agents);
        info("Pipeline: {$chainLabel} ({$total} steps)");

        $originalMessage = $message;
        $contextParts = [];

        foreach ($agents as $i => $agent) {
            // Build message for this step
            if ($i === 0) {
                $agentMessage = $originalMessage;
            } else {
                $agentMessage = "Original request:\n{$originalMessage}\n\n"
                    . implode("\n\n", $contextParts)
                    . "\n\nNow it's your turn. Please respond.";
            }

            // Pipeline context for system prompt
            $pipelineContext = [
                'agents' => $agents,
                'current' => $i,
            ];

                $response = $this->sendToAgentAndCapture($agent, $agentMessage, $pipelineContext);

            if ($response === null) {
                warning("Pipeline stopped: no response from @{$agent}");
                return;
            }

            // Build context block for next agents
            $pipeRole = $this->resolveAgentRole($agent);
            $header = "Response from @{$agent}";
            if ($pipeRole) {
                $header .= " (role: {$pipeRole})";
            }
            $contextParts[] = "--- {$header} ---\n{$response}\n--- end ---";
        }
    }

    /**
     * Broadcast chain: @a+@b+@c
     *
     * Same original message sent to each agent independently, sequentially.
     */
    protected function executeBroadcast(array $agents, string $message): void
    {
        $chainLabel = '@' . implode('+@', $agents);
        info("Broadcast: {$chainLabel}");

        foreach ($agents as $agent) {
                $response = $this->sendToAgentAndCapture($agent, $message);

            if ($response === null) {
                warning("No response from @{$agent}, continuing...");
            }
        }
    }

    /**
     * Execute a shell command and store output as @system[N].
     */
    protected function executeSystemCommand(string $command): void
    {
        $command = trim($command);
        if ($command === '') {
            error('Usage: !command');
            return;
        }

        $this->line('');
        $output = spin(
            callback: function () use ($command) {
                $process = Process::fromShellCommandline(
                    $command,
                    Brain::projectDirectory(),
                );
                $process->setTimeout(30);
                $process->run();

                return $process->getOutput() . $process->getErrorOutput();
            },
            message: "Running: {$command}",
        );

        $output = trim((string) $output);
        if ($output === '') {
            $output = '(no output)';
        }

        // Truncate very long output
        if (mb_strlen($output) > 50000) {
            $output = mb_substr($output, 0, 50000) . "\n... (truncated)";
        }

        $fullContent = "$ {$command}\n{$output}";
        $index = $this->storeResponse('system', $fullContent);
        $this->displayMessage('system', $index, $fullContent);
        $this->saveState();
    }

    /**
     * Store a response/message and return its index.
     */
    protected function storeResponse(string $name, string $content): int
    {
        if (! isset($this->state['responses'][$name])) {
            $this->state['responses'][$name] = [];
        }
        $index = count($this->state['responses'][$name]);
        $this->state['responses'][$name][] = $content;
        $this->state['display_order'][] = ['entity' => $name, 'index' => $index];

        return $index;
    }

    /**
     * Send message to agent and display response. Convenience wrapper.
     */
    protected function sendToAgent(string $agentName, string $message): void
    {
        $this->sendToAgentAndCapture($agentName, $message);
    }

    /**
     * Send message to agent, display response, store index, return response text.
     *
     * @return string|null Response content or null on failure.
     */
    protected function sendToAgentAndCapture(string $agentName, string $message, ?array $pipelineContext = null): ?string
    {
        $agentState = $this->state['agents'][$agentName];
        $yamlPath = Brain::projectDirectory('.ai' . DS . $agentState['yaml_file']);

        if (! is_file($yamlPath)) {
            error("Agent YAML not found: {$agentState['yaml_file']}");
            return null;
        }

        $yamlData = Yaml::parse(file_get_contents($yamlPath), Yaml::PARSE_CUSTOM_TAGS);

        if (! is_array($yamlData) || empty($yamlData['client'])) {
            error("Invalid agent YAML: {$agentState['yaml_file']}");
            return null;
        }

        $callName = pathinfo($agentState['yaml_file'], PATHINFO_FILENAME);

        $cmd = new CustomRunCommand($callName, $yamlData, $agentState['yaml_file']);

        // Inject message and JSON mode
        $params = $cmd->params ?? [];
        if (! is_array($params)) {
            $params = [];
        }
        $params['ask'] = $message;
        $params['json'] = true;
        // Remove any conditional prompt keys to avoid conflicts
        foreach (array_keys($params) as $key) {
            if (is_string($key) && str_starts_with($key, 'prompt')) {
                unset($params[$key]);
            }
        }
        $cmd->params = $params;

        // Build system-append: role + pipeline context
        $systemParts = [];
        $systemAppend = $params['system-append'] ?? ($params['systemAppend'] ?? '');
        if ($systemAppend !== '') {
            $systemParts[] = $systemAppend;
        }

        $effectiveRole = $this->resolveAgentRole($agentName);
        if ($effectiveRole) {
            $systemParts[] = "Your role on this board: {$effectiveRole}";
        }

        if ($pipelineContext) {
            $systemParts[] = $this->buildPipelineMeta($pipelineContext, $agentName);
        }

        if ($systemParts) {
            $cmd->params = array_merge($cmd->params, [
                'system-append' => implode("\n\n", $systemParts),
            ]);
        }

        // Resume session if exists
        if ($agentState['session_id']) {
            $cmd->resume = $agentState['session_id'];
        }

        // Collect response DTOs
        $sessionId = null;
        $responseContent = '';

        $cmd->setAccumulateCallback(function (mixed $dto) use (&$sessionId, &$responseContent) {
            if ($dto instanceof Init) {
                $sessionId = $dto->sessionId;
            } elseif ($dto instanceof Message) {
                $content = $dto->content;
                if (is_array($content)) {
                    $content = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                $responseContent .= $content;
            }
        });

        $this->line('');
        $responseContent = spin(
            callback: function () use ($cmd, &$sessionId, &$responseContent) {
                $this->callSilently($cmd, ['--json' => true, '--no-update' => true]);
                return $responseContent;
            },
            message: "Waiting for @{$agentName}...",
        );

        // Update session ID
        if ($sessionId) {
            $this->state['agents'][$agentName]['session_id'] = $sessionId;
        }

        // Store indexed response and display
        if ($responseContent) {
            $index = $this->storeResponse($agentName, $responseContent);
            $this->displayMessage($agentName, $index, $responseContent);
        } else {
            warning("No response from @{$agentName}");
        }

        $this->saveState();

        return $responseContent !== '' ? $responseContent : null;
    }

    // ──────────────────────────────────────────────
    // Response references
    // ──────────────────────────────────────────────

    /**
     * Build autocomplete suggestions for chain continuation (->@agent or +@agent).
     *
     * @return list<string>
     */
    protected function buildChainSuggestions(string $currentValue, string $typedPrefix): array
    {
        // Determine chain operator from current value
        $isArrow = str_contains($currentValue, '->');
        $operator = $isArrow ? '->' : '+';

        // Text before the last partial @name
        $textBefore = preg_replace('/(?:->|[+])@[a-zA-Z0-9_\-]*$/', '', $currentValue);

        // Agents already in chain — extract from target part
        $targetPart = explode(' ', $currentValue, 2)[0];
        $usedAgents = [];
        preg_match_all('/@([a-zA-Z0-9_\-]+)/', $targetPart, $usedMatches);
        if (! empty($usedMatches[1])) {
            $usedAgents = $usedMatches[1];
        }

        $suggestions = [];
        $prefix = strtolower($typedPrefix);

        foreach (array_keys($this->state['agents']) as $agent) {
            if (in_array($agent, $usedAgents, true)) {
                continue;
            }
            if ($prefix !== '' && ! str_starts_with(strtolower($agent), $prefix)) {
                continue;
            }
            $suggestions[] = $textBefore . $operator . '@' . $agent . ' ';
        }

        return $suggestions;
    }

    /**
     * Build autocomplete suggestions for @agent[N] response references.
     *
     * @return list<string>
     */
    protected function buildResponseRefSuggestions(string $currentValue): array
    {
        // Extract the @prefix the user is typing at the end of input
        if (! preg_match('/@([a-zA-Z0-9_\-]*)$/', $currentValue, $tailMatch)) {
            return [];
        }

        $typedPrefix = strtolower($tailMatch[1]);
        $textBefore = substr($currentValue, 0, -strlen($tailMatch[0]));
        $suggestions = [];

        foreach ($this->state['responses'] as $agentName => $responses) {
            if ($typedPrefix !== '' && ! str_starts_with(strtolower($agentName), $typedPrefix)) {
                continue;
            }
            foreach ($responses as $index => $content) {
                if ($content === null) {
                    continue;
                }
                $suggestions[] = $textBefore . "@{$agentName}[{$index}]";
            }
        }

        return $suggestions;
    }

    /**
     * Resolve @agent[N] references in a message.
     *
     * Replaces patterns like @executor[0] with a quoted block
     * containing agent metadata and the response content.
     */
    protected function resolveResponseReferences(string $message): string
    {
        return (string) preg_replace_callback(
            '/@([a-zA-Z0-9_\-]+)\[(\d+)\]/',
            function (array $matches) {
                $name = $matches[1];
                $index = (int) $matches[2];

                $responses = $this->state['responses'][$name] ?? [];

                if (! isset($responses[$index]) || $responses[$index] === null) {
                    return $matches[0] . ' [removed]';
                }

                // Semantic label per entity type
                if ($name === 'user') {
                    $header = "--- @user [message #{$index}] ---";
                } elseif ($name === 'system') {
                    $header = "--- @system [output #{$index}] ---";
                } else {
                    $refRole = $this->resolveAgentRole($name);
                    $header = "--- @{$name}";
                    if ($refRole) {
                        $header .= " (role: {$refRole})";
                    }
                    $header .= " [response #{$index}] ---";
                }

                return "`\n{$header}\n{$responses[$index]}\n--- end ---\n`";
            },
            $message
        );
    }

    // ──────────────────────────────────────────────
    // Pipeline context
    // ──────────────────────────────────────────────

    /**
     * Build compact pipeline meta for system prompt.
     *
     * @param  array{agents: list<string>, current: int}  $ctx
     */
    protected function buildPipelineMeta(array $ctx, string $agentName): string
    {
        $agents = $ctx['agents'];
        $current = $ctx['current'];
        $total = count($agents);
        $step = $current + 1;

        // Phase guidance
        if ($current === 0) {
            $phase = 'opener — you open the discussion';
        } elseif ($current === $total - 1) {
            $phase = 'closer — give the final conclusion, synthesize all previous inputs';
        } else {
            $phase = 'middle — analyze previous inputs, add your unique perspective';
        }

        // Chain: only names, roles only for completed (context already seen) and current
        $chainParts = [];
        foreach ($agents as $j => $a) {
            $label = '@' . $a;
            if ($j <= $current) {
                $role = $this->resolveAgentRole($a);
                if ($role) {
                    $label .= '(' . mb_substr($role, 0, 30) . ')';
                }
            }
            if ($j < $current) {
                $chainParts[] = $label . ' ✓';
            } elseif ($j === $current) {
                $chainParts[] = '>> ' . $label . ' <<';
            } else {
                $chainParts[] = $label;
            }
        }

        $lines = [
            "IMPORTANT: Respond ONLY as @{$agentName}. Do NOT simulate, call, or speak for other agents.",
            "[Pipeline step {$step}/{$total}] {$phase}",
            'Chain: ' . implode(' → ', $chainParts),
        ];

        // Remaining count (no roles — prevent agent from role-playing next)
        $remaining = $total - $current - 1;
        if ($remaining > 0) {
            $lines[] = "{$remaining} agent(s) will respond after you. The system handles routing.";
        }

        return implode("\n", $lines);
    }

    // ──────────────────────────────────────────────
    // Role resolution
    // ──────────────────────────────────────────────

    /**
     * Resolve effective role for agent.
     *
     * Priority: board override > yaml default > null.
     */
    protected function resolveAgentRole(string $agentName): ?string
    {
        $boardRole = $this->state['agents'][$agentName]['role'] ?? null;
        if ($boardRole !== null) {
            return $boardRole;
        }

        return $this->readYamlRole($agentName);
    }

    /**
     * Read default role from agent's YAML file.
     */
    protected function readYamlRole(string $agentName): ?string
    {
        $agentState = $this->state['agents'][$agentName] ?? null;
        if (! $agentState) {
            return null;
        }

        $yamlPath = Brain::projectDirectory('.ai' . DS . $agentState['yaml_file']);
        if (! is_file($yamlPath)) {
            return null;
        }

        $yamlData = Yaml::parse(file_get_contents($yamlPath), Yaml::PARSE_CUSTOM_TAGS);
        if (! is_array($yamlData)) {
            return null;
        }

        $role = $yamlData['role'] ?? null;

        return is_string($role) && $role !== '' ? $role : null;
    }

    // ──────────────────────────────────────────────
    // Display helpers
    // ──────────────────────────────────────────────

    protected function showAgentList(): void
    {
        $rows = [];
        foreach ($this->state['agents'] as $name => $agent) {
            $yamlPath = Brain::projectDirectory('.ai' . DS . $agent['yaml_file']);
            $client = '-';
            if (is_file($yamlPath)) {
                $yaml = Yaml::parse(file_get_contents($yamlPath), Yaml::PARSE_CUSTOM_TAGS);
                $client = $yaml['client'] ?? '-';
            }

            // Show source name if agent is an alias
            $sourceName = pathinfo($agent['yaml_file'], PATHINFO_FILENAME);
            $nameDisplay = $name;
            if ($sourceName !== $name) {
                $nameDisplay = $name . ' ← ' . $sourceName;
            }

            $boardRole = $agent['role'] ?? null;
            $yamlRole = $this->readYamlRole($name);
            if ($boardRole) {
                $roleDisplay = $boardRole . ' *';
            } elseif ($yamlRole) {
                $roleDisplay = $yamlRole;
            } else {
                $roleDisplay = '-';
            }

            // Truncate long roles to prevent table stretching
            if (mb_strlen($roleDisplay) > 50) {
                $roleDisplay = mb_substr($roleDisplay, 0, 47) . '...';
            }

            $rows[] = [
                $nameDisplay,
                $client,
                $roleDisplay,
                $agent['session_id'] ? 'active' : 'new',
            ];
        }

        table(
            headers: ['Agent', 'Client', 'Role (* = board override)', 'Session'],
            rows: $rows,
        );
    }

    // ──────────────────────────────────────────────
    // Board redraw
    // ──────────────────────────────────────────────

    /**
     * Display a single message with colored entity prefix.
     */
    protected function displayMessage(string $entity, int $index, string $content): void
    {
        $tag = match ($entity) {
            'user' => $this->green("@user"),
            'system' => $this->yellow("@system"),
            default => $this->cyan("@{$entity}"),
        };

        $this->line("{$tag}" . $this->dim("[{$index}]") . ': ' . $content);
    }

    /**
     * Clear terminal and redraw the board with all non-removed messages.
     */
    protected function redrawBoard(): void
    {
        $this->output->write("\033c");

        $displayName = $this->state['name'] ?? $this->state['id'];
        info("Board: {$displayName}");

        if (count($this->state['agents'])) {
            $this->showAgentList();
        }

        // Replay all non-removed messages in chronological order
        foreach ($this->state['display_order'] as $entry) {
            $entity = $entry['entity'];
            $index = $entry['index'];
            $content = $this->state['responses'][$entity][$index] ?? null;

            if ($content === null) {
                continue;
            }

            $this->displayMessage($entity, $index, $content);
        }
    }

    // ──────────────────────────────────────────────
    // State management
    // ──────────────────────────────────────────────

    protected function loadState(string $id, ?string $name): void
    {
        if (is_file($this->statePath)) {
            $content = file_get_contents($this->statePath);
            $decoded = json_decode($content ?: '', true);
            if (is_array($decoded)) {
                $decoded['responses'] ??= [];
                $decoded['display_order'] ??= [];
                $decoded['fired'] ??= [];
                $this->state = $decoded;
                return;
            }
        }

        $this->state = [
            'id' => $id,
            'name' => $name,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'agents' => [],
            'fired' => [],
            'responses' => [],
            'display_order' => [],
        ];
        $this->saveState();
    }

    protected function saveState(): void
    {
        $this->state['updated_at'] = date('c');
        file_put_contents(
            $this->statePath,
            json_encode($this->state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    protected function ensureBoardsDirectory(): void
    {
        $dir = $this->boardsPath();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    protected function boardsPath(string $append = ''): string
    {
        return Brain::workingDirectory('boards')
            . ($append ? DS . $append : '');
    }

    protected function listAllBoards(): array
    {
        $dir = $this->boardsPath();
        if (! is_dir($dir)) {
            return [];
        }

        $boards = [];
        foreach (File::files($dir) as $file) {
            if ($file->getExtension() === 'json') {
                $content = file_get_contents($file->getPathname());
                $decoded = json_decode($content ?: '', true);
                if (is_array($decoded)) {
                    $boards[] = $decoded;
                }
            }
        }

        usort($boards, fn (array $a, array $b) => ($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? ''));

        return $boards;
    }

    /**
     * Pick an existing board or create a new one.
     *
     * @return string|null Board ID or null to abort.
     */
    protected function pickOrCreateBoard(): ?string
    {
        $boards = $this->listAllBoards();

        if (empty($boards)) {
            return $this->generateBoardId();
        }

        $options = ['+ New board' => '+ New board'];
        foreach ($boards as $board) {
            $label = ($board['name'] ?? $board['id'])
                . ' (' . count($board['agents'] ?? []) . ' agents)';
            $options[$board['id']] = $label;
        }

        $choice = select(
            label: 'Select board',
            options: $options,
        );

        if ($choice === '+ New board') {
            return $this->generateBoardId();
        }

        return $choice;
    }

    protected function generateBoardId(): string
    {
        return md5(uniqid((string) time(), true));
    }

    /**
     * @return list<string>
     */
    protected function availableYamlAgents(): array
    {
        $dir = Brain::projectDirectory('.ai');
        if (! is_dir($dir)) {
            return [];
        }

        $agents = [];
        foreach (File::files($dir) as $file) {
            $ext = $file->getExtension();
            if ($ext === 'yaml' || $ext === 'yml') {
                $agents[] = $file->getFilenameWithoutExtension();
            }
        }

        sort($agents);
        return $agents;
    }
}
