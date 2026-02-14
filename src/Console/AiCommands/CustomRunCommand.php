<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Console\Commands\CompileCommand;
use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Process\Type;
use BrainCLI\Support\Brain;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class CustomRunCommand extends CommandBridgeAbstract
{
    protected array $signatureParts = [
        '{args?* : Arguments for the custom AI command}',
        '{--r|resume= : Resume a previous session by providing the session ID}',
        '{--x|ctx= : Set the context data for the command in JSON format}',
        '{--c|continue : Continue the last session}',
        '{--w|working-dir= : Set the working directory for file references}',
        '{--d|dump : Dump the processed data before execution}',
        '{--j|json : Get output as JSON}',
        '{--health : Check the health of the specified client agent and its configuration}',
        '{--no-update : Do not check for brain updates before running the command}',
    ];

    protected string $originName;

    protected mixed $accumulateCallback = null;

    /**
     * Current recursion depth for variable detection.
     * Used to prevent stack overflow on circular variable references.
     */
    protected int $recursionDepth = 0;

    /**
     * Maximum allowed recursion depth for variable detection.
     */
    protected const MAX_RECURSION_DEPTH = 100;

    /**
     * Current processing path for error context.
     * Tracks the hierarchical path through YAML structure during processing.
     */
    protected array $processingPath = [];

    /**
     * Format error context for improved error messages.
     *
     * Provides detailed context including the processing path, input preview,
     * and the underlying error message for better debugging.
     *
     * @param string $type The type of operation that failed (e.g., 'PHP eval', 'Command', 'File')
     * @param string $value The input value that caused the error
     * @param \Throwable $e The caught exception
     * @return string The formatted error message with context
     */
    protected function formatErrorContext(string $type, string $value, \Throwable $e): string
    {
        $path = $this->processingPath ? implode('.', $this->processingPath) : 'root';
        $preview = strlen($value) > 80 ? substr($value, 0, 80) . '...' : $value;
        return "{$type} failed at '{$path}':\n  Input: {$preview}\n  Error: {$e->getMessage()}";
    }

    public function __construct(
        protected string $callName,
        protected array $data,
        protected string $filename,
    ) {
        $this->originName = $this->callName;
        $this->callName = $this->variablesDetectString($this->data['name'] ?? $this->callName);
        $this->data['env'] = array_merge(
            Brain::allEnv(),
            (isset($this->data['env']) && is_array($this->data['env']) ? $this->data['env'] : [])
        );
        $this->data['_file'] = $this->filename;
        $this->data['_date'] = date('Y-m-d');
        $this->data['_datetime'] = date('Y-m-d H:i:s');
        $this->data['_time'] = date('H:i:s');
        $this->data['_timestamp'] = time();
        $this->data['_call_name'] = $this->callName;
        $this->data['_default_client'] = Agent::defaultAgent()->value;
        $this->data['_path.brain'] = Brain::workingDirectory();
        $this->data['_path.cli'] = Brain::localDirectory();
        $this->data['_path.home'] = getenv('HOME') ?: getenv('USERPROFILE');
        $this->data['_path.cwd'] = getcwd();
        $this->data['_system'] = [
            'uname' => php_uname(),
            'os' => PHP_OS_FAMILY,
            'architecture' => PHP_INT_SIZE * 8 . '-bit',
            'processor' => php_uname('m'),
            'hostname' => gethostname(),
            'name' => php_uname('n'),
            'release' => php_uname('r'),
            'version' => php_uname('v'),
        ];
        $this->data['_user_name'] = get_current_user();
        $this->data['_php_version'] = PHP_VERSION;

        $this->aliases = $this->variablesDetectArray($this->data['aliases'] ?? []);
        $this->signature = $this->callName;
        foreach ($this->signatureParts as $part) {
            $this->signature .= " " . $part;
        }
        $this->description = $this->variablesDetectString($this->data['description'] ?? 'Custom AI agent command');
        parent::__construct();
    }

    public function setAccumulateCallback(callable $accumulateCallback): static
    {
        $this->accumulateCallback = $accumulateCallback;

        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    protected function handleBridge(): int|array
    {
        if ($wd = $this->option('working-dir')) {
            chdir($wd);
        }

        if ($ctx = ($this->option('ctx') || Brain::getEnv('BRAIN_AI_COMMAND_CTX'))) {
            try {
                $ctxData = json_decode((string) $ctx, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($ctxData)) {
                    $this->data = array_merge_recursive($this->data, $ctxData);
                } else {
                    $this->components->error("The provided context data is not a valid JSON.");
                    return ERROR;
                }
            } catch (\JsonException $e) {
                if (Brain::isDebug()) {
                    dd($e);
                }
                $this->components->error("Failed to parse context JSON: " . $e->getMessage());
                return ERROR;
            }
        }

        $this->data['client'] = $this->variablesDetectString($this->data['client']);

        $agent = Agent::tryFrom($this->data['client']);

        if (! $agent || ! $agent->isEnabled()) {
            $this->components->error("The specified client agent '{$this->data['client']}' is not supported or enabled.");
            return ERROR;
        }

        $this->initFor($agent);

        $this->data['_model'] = $this->agent->modelsAssoc();
        $this->data['_model.general'] = $this->agent->generalModel()->value;

        foreach (($this->argument('args') ?: []) as $index => $arg) {
            $decodable = is_string($arg) && (
                in_array($arg, ['false', 'true', 'null'])
                || is_numeric($arg)
                || Str::of($arg)->isJson()
                || str_starts_with($arg, '"')
            );
            $this->data['args'][$index] = $decodable ? json_decode($arg, true) : $arg;
        }

        // Map args_names to args for named access (e.g., $args.task_id alongside $args.0)
        if (isset($this->data['args_names']) && is_array($this->data['args_names'])) {
            foreach ($this->data['args_names'] as $index => $name) {
                if (is_string($name) && $name !== '' && isset($this->data['args'][$index])) {
                    $this->data['args'][$name] = $this->data['args'][$index];
                }
            }
        }

        $this->variablesDetectArrayData();
        $this->processCommandsInstructions();

        if ($this->option('dump')) {
            dump($this->data);
        }

        $compileNeeded = isset($this->data['env'])
            && is_array($this->data['env'])
            && count($this->data['env']);

        if ($compileNeeded && ! $this->option('dump')) {
            $this->callSilent(new CompileCommand($this->data['env']), [
                'agent' => $this->agent->value,
            ]);
        }

        $this->data['env']['BRAIN_AI_AGENT_NAME'] = $this->filename;

        $type = Type::customDetect($this->data);

        $process = $this->client->process($type);
        $resume = $this->option('resume') ?: ($this->data['resume'] ?? null);
        $continue = $this->option('continue') || ($this->data['continue'] ?? false);
        $dump = $this->option('dump') || ($this->data['dump'] ?? false);

        $options = $process->payload->defaultOptions([
            'ask' => $this->data['params']['ask'] ?? null,
            'prompt' => $this->data['params']['prompt'] ?? null,
            'json' => $this->option('json') || ($this->data['params']['json'] ?? ($this->data['params']['serialize'] ?? false)),
            'serialize' => $this->data['params']['serialize'] ?? false,
            'yolo' => $this->data['params']['yolo'] ?? false,
            'model' => $this->data['params']['model'] ?? null,
            'system' => $this->data['params']['system'] ?? null,
            'systemAppend' => $this->data['params']['system-append'] ?? ($this->data['params']['systemAppend'] ?? null),
            'schema' => $this->data['params']['schema'] ?? null,
            'dump' => $dump,
            'resume' => $resume,
            'continue' => $continue,
            'no-mcp' => $this->data['params']['no-mcp'] ?? ($this->data['params']['noMcp'] ?? false),
        ]);

        $process
            ->program()
            ->env($this->data['env'])
            ->askWhen($options['ask'], $options['ask'])
            ->promptWhen($options['prompt'], $options['prompt'])
            ->jsonWhen($options['json'])
            ->resumeWhen($options['resume'], $options['resume'])
            ->continueWhen($options['continue'])
            ->yoloWhen($options['yolo'])
            ->modelWhen($options['model'], $options['model'])
            ->systemWhen($options['system'], $options['system'])
            ->systemAppendWhen($options['systemAppend'], $options['systemAppend'])
            ->noMcpWhen($options['no-mcp'])
            ->schemaWhen($options['schema'], function () use ($options) {
                if (is_array($jsonSchema = json_decode($options['schema'], true))) {
                    return $jsonSchema;
                }
                $this->components->error("The provided schema is not a valid JSON.");
                exit(1);
            })->dump($options['dump']);

        if ($this->option('dump') || $this->option('health')) {
            $data = $process->toArray();
            if ($this->option('health')) {

                $data['client-health'] = $this->checkProcessHealth($data) ? 'healthy' : 'unhealthy';

                echo json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL;
            } else {
                dump($data);
            }
            return OK;
        }

        if ($process->reflection->isUsed('json')) {
            return $process->run(function (string $output) use ($process, $options) {
                $result = $this->client->processParseOutput($process, $output);
                foreach ($result as $dto) {
                    if ($this->accumulateCallback) {
                        call_user_func($this->accumulateCallback, $dto, $this);
                    } elseif ($options['dump']) {
                        dump($dto);
                    } elseif ($options['serialize']) {
                        $this->line($dto->toSerialize());
                    } else {
                        $this->line($dto->toJson(JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                    }
                }
            });
        }

        return $process->open();
    }

    protected function checkProcessHealth(array $processData): bool
    {
        $command = is_array($processData['command']) ? $processData['command'][0] : $processData['command'];
        if (is_file($command)) {
            return is_executable($command);
        }
        $checkCommand = (str_starts_with(strtolower(PHP_OS_FAMILY), 'win') ? 'where' : 'which') . " $command";
        exec($checkCommand, $output, $returnVar);
        if ($returnVar === 0) {
            //return true; // Command exists and is executable
            if (is_array($output) && count($output) > 0) {
                $executablePath = $output[0];
                return is_executable($executablePath);
            }
        }

        return false;
    }

     /**
     * Process archetype instructions from YAML configuration.
     *
     * Supports all archetype types:
     *   - brain (BrainArchetype)
     *   - agents (AgentArchetype)
     *   - commands (CommandArchetype)
     *   - skills (SkillArchetype)
     *   - includes (IncludeArchetype)
     *
     * YAML structure examples:
     *   brain:
     *     rule:
     *       - "Brain rule text"
     *     guideline:
     *       - "Brain guideline text"
     *
     *   agents:
     *     explore_master:
     *       rule:
     *         - "Agent rule text"
     *       guideline:
     *         - "Agent guideline text"
     *
     *   commands:
     *     task_validate_command:
     *       rule:
     *         - "Command rule text"
     *
     * Converts to environment variables:
     *   BRAIN_RULE_0 = "Brain rule text"
     *   EXPLORE_MASTER_RULE_0 = "Agent rule text"
     *   TASK_VALIDATE_COMMAND_RULE_0 = "Command rule text"
     *
     * This integrates with ArchetypeArchitecture::loadEnvInstructions() which reads
     * {CLASS}_RULE_N and {CLASS}_GUIDELINE_N environment variables.
     *
     * @return void
     */
    protected function processCommandsInstructions(): void
    {
        $this->data['env'] ??= [];

        // Process brain archetype (special case - no nested ID)
        if (isset($this->data['brain']) && is_array($this->data['brain'])) {
            $this->injectArchetypeInstructions('BRAIN', $this->data['brain']);
        }

        // Process nested archetypes: agents, commands, skills, includes
        $archetypeKeys = ['agents', 'commands', 'skills', 'includes'];

        foreach ($archetypeKeys as $archetypeKey) {
            if (!isset($this->data[$archetypeKey]) || !is_array($this->data[$archetypeKey])) {
                continue;
            }

            foreach ($this->data[$archetypeKey] as $archetypeId => $config) {
                if (!is_array($config)) {
                    continue;
                }

                // Convert archetype ID to UPPER_SNAKE_CASE prefix
                // explore_master → EXPLORE_MASTER
                // task_validate_command → TASK_VALIDATE_COMMAND
                $prefix = Str::of($archetypeId)
                    ->snake()
                    ->upper()
                    ->toString();

                $this->injectArchetypeInstructions($prefix, $config);
            }
        }
    }

    /**
     * Inject rules and guidelines as environment variables for an archetype.
     *
     * @param string $prefix The UPPER_SNAKE_CASE prefix for env var names
     * @param array $config The archetype config containing 'rule' and/or 'guideline' arrays
     * @return void
     */
    protected function injectArchetypeInstructions(string $prefix, array $config): void
    {
        // Process rules
        if (isset($config['rule']) && is_array($config['rule'])) {
            foreach (array_values($config['rule']) as $index => $rule) {
                $this->data['env']["{$prefix}_RULE_{$index}"] = (string) $rule;
            }
        }

        // Process guidelines
        if (isset($config['guideline']) && is_array($config['guideline'])) {
            foreach (array_values($config['guideline']) as $index => $guideline) {
                $this->data['env']["{$prefix}_GUIDELINE_{$index}"] = (string) $guideline;
            }
        }
    }

    protected function variablesDetectArrayData(): void
    {
        $data = Arr::dot($this->data);
        $new = [
            'id' => $this->data['id'] ?? md5(uniqid((string) time(), true)),
            'args' => $this->data['args'] ?? [],
        ];
        $oldCwd = getcwd();
        chdir(Brain::projectDirectory('.ai'));

        foreach ($data as $path => $value) {
            if ($path === '$schema' || $path === 'id') {
                continue;
            }

            // Track processing path for error context
            $this->processingPath[] = (string) $path;
            try {
                // Check for conditional key pattern: key_name?{condition}
                if (is_string($path) && preg_match('/^(.+)\?\{(.+)\}$/', $path, $conditionalMatches)) {
                    $cleanPath = $conditionalMatches[1];
                    $condition = $conditionalMatches[2];

                    // Evaluate the condition - if false, skip this key entirely
                    if (!$this->evaluateCondition($condition)) {
                        continue;
                    }

                    // Condition is true - use the clean path name
                    $path = $cleanPath;
                }

                if (Str::startsWith($path, '_')) {
                    data_set($new, $path, $value);
                    continue;
                }
                if (
                    preg_match('/^(.*)(\\\\$.*)$/', $path, $matches)
                    || preg_match('/^(.*)(\\\\@.*)$/', $path, $matches)
                    || preg_match('/^(.*)(\\\\!.*)$/', $path, $matches)
                ) {
                    $firstPart = $this->variablesDetectString($matches[1]);
                    if (! is_string($firstPart)) {
                        throw new \InvalidArgumentException("Invalid path part '{$matches[1]}' in '$path'.");
                    }
                    $mergeData = $this->variablesDetectString($matches[2]);

                    if (is_array($mergeData)) {
                        $newData = Arr::dot($mergeData);
                        foreach ($newData as $newPath => $newValue) {
                            data_set($this->data, $firstPart . $newPath, $newValue);
                            data_set($new, $firstPart . $newPath, $newValue);
                        }
                    } else {
                        $path = $mergeData;
                        data_set($this->data, $path, $value);
                        data_set($new, $path, $value);
                    }
                    continue;
                }
                if (is_string($value)) {
                    $value = $this->variablesDetectString($value);
                }
                data_set($this->data, $path, $value);
                data_set($new, $path, $value);
            } finally {
                array_pop($this->processingPath);
            }
        }

        $this->data = $new;

        chdir($oldCwd);
    }

    protected function variablesDetectArray(array $data): array
    {
        $newData = [];
        foreach ($data as $key => $value) {
            if ($key === '$schema') {
                continue;
            }

            // Track processing path for error context
            $originalKey = $key;
            $this->processingPath[] = is_string($key) ? $key : (string) $key;
            try {
                // Check for conditional key pattern: key_name?{condition}
                if (is_string($key) && preg_match('/^(.+)\?\{(.+)\}$/', $key, $conditionalMatches)) {
                    $cleanKey = $conditionalMatches[1];
                    $condition = $conditionalMatches[2];

                    // Evaluate the condition - if false, skip this key entirely
                    if (!$this->evaluateCondition($condition)) {
                        continue;
                    }

                    // Condition is true - use the clean key name
                    $key = $cleanKey;
                }

                if (is_string($key)) {
                    $key = $this->variablesDetectString($key);
                    if (is_array($key)) {
                        $newData = array_merge($newData, $this->variablesDetectArray($key));
                        continue;
                    }
                }
                if (is_string($value)) {
                    $newData[$key] = $this->variablesDetectString($value);
                } elseif (is_array($value)) {
                    $newData[$key] = $this->variablesDetectArray($value);
                } else {
                    $newData[$key] = $value;
                }
            } finally {
                array_pop($this->processingPath);
            }
        }
        return $newData;
    }

    protected function variablesDetectString(mixed $value): string|int|float|null|bool|array
    {
        // Guard against circular variable references causing stack overflow
        if (++$this->recursionDepth > self::MAX_RECURSION_DEPTH) {
            $preview = is_string($value) ? substr($value, 0, 100) : gettype($value);
            throw new \RuntimeException(
                "Maximum recursion depth (" . self::MAX_RECURSION_DEPTH . ") exceeded. " .
                "Possible circular variable reference. Last value: {$preview}"
            );
        }

        try {
            // Handle non-string values early
            if (!is_string($value)) {
                return $value;
            }

            $variableRegexp = '\$([a-zA-Z\d_\-\.\$\{\}]+)';
            $fileRegexp = '\@(?!agent-)(.+)'; // Negative lookahead excludes @agent- prefix
            $cmdRegexp = '\!(.+)';
            $evalRegexp = '\>(.+)';
            $brainCommandRegexp = '\/([a-zA-Z\d_\-\:]+)';
            $agentRegexp = '@agent-([a-zA-Z\d_\-]+)';

        // Expression callback: handles ??, ?:, and ternary operators
        // Priority: ?? (null-coalescing) → ?: (elvis) → ? : (ternary)
        $ternaryReplace = function (string $content): mixed {
            // 1. Check for null-coalescing (??) FIRST
            if ($this->findNullCoalescingPosition($content) !== -1) {
                return $this->parseNullCoalescing($content);
            }

            // 2. Check for elvis (?:) SECOND
            if ($this->findElvisPosition($content) !== -1) {
                return $this->parseElvis($content);
            }

            // 3. Check for standard ternary (? :) LAST
            if (!$this->containsTernaryOperator($content)) {
                return null; // Signal: not a conditional expression, skip
            }
            return $this->parseTernary($content);
        };

        // Replacement callbacks
        $variableReplace = function ($matches) use ($variableRegexp, $fileRegexp, $cmdRegexp) {
            $name = trim($matches[1]);
            $name = $this->variablesDetectString($name);

            if (Brain::hasEnv($name)) {
                $return = (string) Brain::getEnv($name);
            } elseif (array_key_exists($name, $this->data)) {
                $return = $this->data[$name];
            } else {
                $return = data_get($this->data, $name, $matches[0]);
            }
            while (
                is_string($return)
                && (
                    preg_match("/\\\\{$variableRegexp}/", $return)
                    || preg_match("/\\\\{$fileRegexp}/", $return)
                    || preg_match("/\\\\{$cmdRegexp}/", $return)
                    || preg_match("/^$variableRegexp$/", $return)
                    || preg_match("/^$fileRegexp$/", $return)
                    || preg_match("/^$cmdRegexp$/", $return)
                )
            ) {
                $return = $this->variablesDetectString($return);
            }

            return $return;
        };

        // File replacement callback
        $fileReplace = function ($matches, bool $full = false) {
            $file = trim($matches[1]);
            $file = $this->variablesDetectString($file);

            $fileArguments = null;
            if (str_contains($file, '<')) {
                [$file, $fileArguments] = explode('<', $file, 2);
                $file = trim($file);
                $fileArguments = $this->variablesDetectString(trim($fileArguments));
            }

            if (is_file($file)) {
                $content = file_get_contents($file) ?: $matches[0];

                if ($fileArguments !== null) {
                    $content = str_replace('$ARGUMENTS', "`$fileArguments`", $content);
                }
                if ($full) {
                    $ext = pathinfo($file, PATHINFO_EXTENSION);
                    if (in_array($ext, ['json', 'jsonl'])) {
                        $decoded = json_decode($content, true);
                        if (is_array($decoded)) {
                            return $this->variablesDetectArray($decoded);
                        }
                    } elseif (in_array($ext, ['yaml', 'yml'])) {
                        $decoded = Yaml::parse($content);
                        if (is_array($decoded)) {
                            return $this->variablesDetectArray($decoded);
                        }
                    } elseif ($ext === 'env') {
                        $lines = explode("\n", $content);
                        $envData = [];
                        foreach ($lines as $line) {
                            if (str_contains($line, '=')) {
                                [$envKey, $envValue] = explode('=', $line, 2);
                                $envData[trim($envKey)] = trim($envValue);
                            }
                        }
                        return $this->variablesDetectArray($envData);
                    }
                }
                return $this->variablesDetectString($content);
            }
            $path = $this->processingPath ? implode('.', $this->processingPath) : 'root';
            throw new \InvalidArgumentException("File reference failed at '{$path}':\n  File: {$file}\n  Error: File not found.");
        };

        // Command replacement callback
        $cmdReplace = function ($matches) use ($variableRegexp, $fileRegexp, $cmdRegexp) {
            $command = trim($matches[1]);
            $command = $this->variablesDetectString($command);
            $output = shell_exec($command);
            if ($output === null) {
                $path = $this->processingPath ? implode('.', $this->processingPath) : 'root';
                $preview = strlen($command) > 80 ? substr($command, 0, 80) . '...' : $command;
                throw new \RuntimeException("Command execution failed at '{$path}':\n  Command: {$preview}\n  Error: Command returned null (execution failed or produced no output).");
            }
            $value = $this->variablesDetectString(trim($output));
            while (
                is_string($value)
                && (
                    preg_match("/\\{$variableRegexp}/", $value)
                    || preg_match("/\\{$fileRegexp}/", $value)
                    || preg_match("/\\{$cmdRegexp}/", $value)
                    || preg_match("/^$variableRegexp$/", $value)
                    || preg_match("/^$fileRegexp$/", $value)
                    || preg_match("/^$cmdRegexp$/", $value)
                )
            ) {
                $value = $this->variablesDetectString($value);
            }
            return $value;
        };

        // Eval replacement
        $evalReplace = function ($matches) use ($variableRegexp, $fileRegexp, $cmdRegexp) {
            $code = trim($matches[1]);
            if (! str_ends_with($code, ';')) {
                $code .= ';';
            }
            // if not a return statement, add it
            if (! preg_match('/^\s*return\s+/m', $code)) {
                $code = 'return ' . $code;
            }
            $code = $this->variablesDetectString($code);
            try {
                // Use output buffering to capture any echoed output
                ob_start();
                $result = eval($code);
                $output = ob_get_clean();
                if ($output !== false && trim($output) !== '') {
                    return $this->variablesDetectString(trim($output));
                }
                return $result ? $this->variablesDetectString((string) $result) : '';
            } catch (\Throwable $e) {
                throw new \RuntimeException($this->formatErrorContext('PHP eval', $matches[1], $e));
            }
        };

        // Brain command replacement callback - compiles Brain commands at RUNTIME via CLI
        // Syntax: /path:command args → compiles .brain/node/Commands/{Path}/{Command}Command.php with current env vars
        $brainCommandReplace = function ($matches) {
            $fullMatch = $matches[0];
            $commandPath = trim($matches[1]);
            $commandArgs = isset($matches[2]) ? trim($matches[2]) : null;

            // Interpolate command path (may contain variables)
            $commandPath = $this->variablesDetectString($commandPath);

            // Interpolate args if present
            if ($commandArgs !== null) {
                $commandArgs = $this->variablesDetectString($commandArgs);
            }

            // Parse command path: "task:validate" → "Task", "Validate"
            $segments = explode(':', $commandPath, 2);
            if (count($segments) !== 2) {
                return $fullMatch; // Invalid format
            }

            [$category, $command] = $segments;

            // Build file path: Commands/Task/ValidateCommand.php (relative to node/)
            $relativePath = 'Commands/'
                . Str::studly($category) . '/'
                . Str::studly($command) . 'Command.php';

            // IMPORTANT: Temporarily restore project root directory for getWorkingFile()
            // because variablesDetectArrayData() changes CWD to .ai/ and
            // Brain::projectDirectory() relies on getcwd()
            $currentCwd = getcwd();

            // Detect if we're in .ai subdirectory and need to go up
            // Cannot use Brain::projectDirectory() here as it also uses getcwd()
            if (str_ends_with($currentCwd, DIRECTORY_SEPARATOR . '.ai') || str_ends_with($currentCwd, '/.ai')) {
                chdir(dirname($currentCwd));
            }

            try {
                // Use getWorkingFile to get proper path with format suffix
                $commandFile = $this->getWorkingFile($relativePath);

                if ($commandFile === null) {
                    return $fullMatch; // File not found
                }

                Brain::setEnv('BRAIN_COMPILE_WITHOUT_META', 1);
                // Use parent's convertFiles method - it handles ALL variables properly
                $result = $this->convertFiles($commandFile, null, $this->data['env'] ?? []);
                Brain::setEnv('BRAIN_COMPILE_WITHOUT_META', 0);

                if ($result->isEmpty()) {
                    return $fullMatch; // File not found or conversion failed
                }

                $data = $result->first();
                $content = $data->structure ?? null;

                if ($content === null) {
                    return $fullMatch; // No structure in result
                }

                // Replace $ARGUMENTS placeholder with interpolated args
                if ($commandArgs !== null) {
                    $content = str_replace('$ARGUMENTS', "`$commandArgs`", $content);
                    //dd($content);
                    //$content = str_replace("'", "`", $content); // Prevent shell issues
                    //dd(escapeshellcmd($content));
                }

                return $content;
            } catch (\Throwable $e) {
                return $fullMatch; // Conversion failed
            } finally {
                // Restore original CWD
                chdir($currentCwd);
            }
        };

        // Agent replacement callback - compiles Agent files at RUNTIME via CLI
        // Syntax: @agent-{name} → compiles .brain/node/Agents/{Name}Master.php with current env vars
        // STRICT: Throws RuntimeException if agent not found (unlike commands which silently fail)
        $agentReplace = function ($matches) {
            $fullMatch = $matches[0];
            $agentName = trim($matches[1]);

            // Interpolate agent name (may contain variables)
            $agentName = $this->variablesDetectString($agentName);

            // Build file name based on naming convention:
            // - If name ends with '-master': just Str::studly() (e.g., 'commit-master' → 'CommitMaster')
            // - Otherwise: Str::studly() + 'Master' (e.g., 'explore' → 'ExploreMaster')
            if (str_ends_with(strtolower($agentName), '-master')) {
                $fileName = Str::studly($agentName) . '.php';
            } else {
                $fileName = Str::studly($agentName) . 'Master.php';
            }

            // Build file path: Agents/{Name}Master.php (relative to node/)
            $relativePath = 'Agents/' . $fileName;

            // IMPORTANT: Temporarily restore project root directory for getWorkingFile()
            // because variablesDetectArrayData() changes CWD to .ai/ and
            // Brain::projectDirectory() relies on getcwd()
            $currentCwd = getcwd();

            // Detect if we're in .ai subdirectory and need to go up
            // Cannot use Brain::projectDirectory() here as it also uses getcwd()
            if (str_ends_with($currentCwd, DIRECTORY_SEPARATOR . '.ai') || str_ends_with($currentCwd, '/.ai')) {
                chdir(dirname($currentCwd));
            }

            try {
                // Use getWorkingFile to get proper path with format suffix
                $agentFile = $this->getWorkingFile($relativePath);

                if ($agentFile === null) {
                    throw new \RuntimeException("Agent not found: {$agentName} (expected file: {$relativePath})");
                }

                Brain::setEnv('BRAIN_COMPILE_WITHOUT_META', 1);
                // Use parent's convertFiles method - it handles ALL variables properly
                $result = $this->convertFiles($agentFile, null, $this->data['env'] ?? []);
                Brain::setEnv('BRAIN_COMPILE_WITHOUT_META', 0);

                if ($result->isEmpty()) {
                    throw new \RuntimeException("Agent conversion failed: {$agentName} (file: {$relativePath})");
                }

                $data = $result->first();
                $content = $data->structure ?? null;

                if ($content === null) {
                    throw new \RuntimeException("Agent has no structure: {$agentName} (file: {$relativePath})");
                }

                // NOTE: Agents do NOT use $ARGUMENTS substitution (unlike commands)
                return $content;
            } finally {
                // Restore original CWD
                chdir($currentCwd);
            }
        };

        if (preg_match("/^\\\\".$evalRegexp."$/", $value, $matches)) {
            $value = $evalReplace($matches);
        }
        if (is_string($value) && preg_match("/^\\\\".$cmdRegexp."$/", $value, $matches)) {
            $value = $cmdReplace($matches);
        }
        if (is_string($value) && preg_match("/^\\\\".$variableRegexp."$/", $value, $matches)) {
            $value = $variableReplace($matches);
        }
        if (is_string($value) && preg_match("/^\\\\".$fileRegexp."$/", $value, $matches)) {
            $value = $fileReplace($matches, true);
        }
        // Brain command full-value pattern: \/{category}:{command} args
        if (is_string($value) && preg_match("/^\\\\".$brainCommandRegexp."(?:\\s+(.+))?$/", $value, $matches)) {
            $value = $brainCommandReplace($matches);
        }
        // Agent full-value pattern: \@agent-{name} (backslash escapes @ for YAML compatibility)
        if (is_string($value) && preg_match("/^\\\\".$agentRegexp."$/", $value, $matches)) {
            $value = $agentReplace($matches);
        }

        // Process ternary expressions FIRST (before other patterns)
        // Uses balanced brace extraction to handle nested {$var} patterns inside ternary expressions
        // Example: {$a ? ($b ? {$c} : {$d}) : {$e}} - properly handles nested braces
        $maxIterations = 10; // Prevent infinite loops for nested ternaries
        $iteration = 0;
        $previousValue = null;
        while ($iteration < $maxIterations && is_string($value) && $value !== $previousValue) {
            $previousValue = $value;
            $value = $this->processBalancedTernaryExpressions($value, $ternaryReplace);
            $iteration++;
        }

        if (is_string($value) || is_array($value)) {
            $value = preg_replace_callback("/\{$evalRegexp}/", $evalReplace, $value) ?: $value;
            $value = preg_replace_callback("/\{$cmdRegexp}/", $cmdReplace, $value) ?: $value;
            $value = preg_replace_callback("/\{$variableRegexp}/", $variableReplace, $value) ?: $value;
            $value = preg_replace_callback("/\{$fileRegexp}/", $fileReplace, $value) ?: $value;
            // Brain command inline pattern (processed LAST): {/{category}:{command} args}
            $value = preg_replace_callback("/\{\\\\".$brainCommandRegexp."(?:\\s+(.+))?\}/", $brainCommandReplace, $value) ?: $value;
            // Agent inline pattern: {@agent-{name}}
            $value = preg_replace_callback("/\{".$agentRegexp."\}/", $agentReplace, $value) ?: $value;
        }

            $decodable = is_string($value) && (in_array($value, ['false', 'true', 'null'])
                || is_numeric($value)
                || Str::of($value)->isJson()
                || str_starts_with($value, '"'));

            return $decodable ? json_decode($value, true) : $value;
        } finally {
            $this->recursionDepth--;
        }
    }

    /**
     * Evaluate a conditional expression with variable substitution.
     *
     * Supports:
     * - Simple truthy check: $var
     * - Negation: !$var
     * - Comparisons: $a == $b, $a != $b, $a < $b, $a > $b, $a <= $b, $a >= $b
     * - Logical AND: $a && $b
     * - Logical OR: $a || $b
     * - Parentheses grouping: ($a && $b) || $c
     * - Mixed: $count > 5 && $enabled
     *
     * Variable resolution order:
     * 1. Brain::hasEnv($name) -> Brain::getEnv($name)
     * 2. array_key_exists($name, $this->data) -> $this->data[$name]
     * 3. data_get($this->data, $name)
     *
     * @param string $condition The condition expression to evaluate
     * @return bool The evaluation result
     */
    protected function evaluateCondition(string $condition): bool
    {
        $condition = trim($condition);

        if ($condition === '') {
            return false;
        }

        // Resolve all variables in the condition first
        $resolved = $this->resolveConditionVariables($condition);

        // Parse and evaluate the resolved expression
        return $this->parseConditionExpression($resolved);
    }

    /**
     * Resolve all $variable references in a condition string.
     *
     * @param string $condition The condition with variable references
     * @return string The condition with resolved values
     */
    protected function resolveConditionVariables(string $condition): string
    {
        // Replace all $varname patterns with their resolved values
        return preg_replace_callback(
            '/\$([a-zA-Z_][a-zA-Z0-9_\.\-]*)/',
            function ($matches) {
                $name = $matches[1];
                $value = $this->resolveVariable($name);

                return $this->valueToConditionString($value);
            },
            $condition
        ) ?? $condition;
    }

    /**
     * Resolve a single variable name to its value.
     *
     * Resolution order:
     * 1. Environment variable (Brain::getEnv)
     * 2. Direct data key (flat keys with dots)
     * 3. Nested data path (dot notation)
     *
     * @param string $name The variable name
     * @return mixed The resolved value or null if not found
     */
    protected function resolveVariable(string $name): mixed
    {
        if (Brain::hasEnv($name)) {
            return Brain::getEnv($name);
        }

        if (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }

        return data_get($this->data, $name);
    }

    /**
     * Check if a variable exists (not just has value).
     * Used for null-coalescing operator (??).
     *
     * Resolution order:
     * 1. Environment variable exists
     * 2. Direct data key exists
     * 3. Nested data path exists (using Arr::has)
     *
     * @param string $name The variable name
     * @return bool True if variable exists, false otherwise
     */
    protected function variableExists(string $name): bool
    {
        if (Brain::hasEnv($name)) {
            return true;
        }

        if (array_key_exists($name, $this->data)) {
            return true;
        }

        return Arr::has($this->data, $name);
    }

    /**
     * Resolve an expression value - handles bare variables ($var) and general expressions.
     * Used by elvis and null-coalescing operators to properly resolve values.
     *
     * @param string $expression The expression to resolve
     * @return mixed The resolved value
     */
    protected function resolveExpressionValue(string $expression): mixed
    {
        $expression = trim($expression);

        // Check if it's a bare variable reference ($var)
        if (preg_match('/^\$([a-zA-Z_][a-zA-Z0-9_\.\-]*)$/', $expression, $matches)) {
            return $this->resolveVariable($matches[1]);
        }

        // Check if it's a braced variable ({$var})
        if (preg_match('/^\{\$([a-zA-Z_][a-zA-Z0-9_\.\-]*)\}$/', $expression, $matches)) {
            return $this->resolveVariable($matches[1]);
        }

        // Otherwise use variablesDetectString for complex expressions
        return $this->variablesDetectString($expression);
    }

    /**
     * Convert a PHP value to a safe string representation for condition parsing.
     *
     * @param mixed $value The value to convert
     * @return string The string representation
     */
    protected function valueToConditionString(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            // Handle boolean strings
            if (in_array(strtolower($value), ['true', 'false', 'null'], true)) {
                return strtolower($value);
            }
            // Escape and quote strings
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
            return '"' . $escaped . '"';
        }

        if (is_array($value)) {
            return empty($value) ? 'false' : 'true';
        }

        return 'false';
    }

    /**
     * Parse and evaluate a condition expression (variables already resolved).
     *
     * @param string $expression The resolved expression
     * @return bool The evaluation result
     */
    protected function parseConditionExpression(string $expression): bool
    {
        $expression = trim($expression);

        if ($expression === '') {
            return false;
        }

        // Handle parentheses recursively
        while (preg_match('/\(([^()]+)\)/', $expression, $matches)) {
            $innerResult = $this->parseConditionExpression($matches[1]);
            $replacement = $innerResult ? 'true' : 'false';
            $expression = str_replace($matches[0], $replacement, $expression);
        }

        // Handle OR (lowest precedence)
        if (str_contains($expression, '||')) {
            $parts = preg_split('/\s*\|\|\s*/', $expression, 2);
            if (count($parts) === 2) {
                return $this->parseConditionExpression($parts[0])
                    || $this->parseConditionExpression($parts[1]);
            }
        }

        // Handle AND (higher precedence than OR)
        if (str_contains($expression, '&&')) {
            $parts = preg_split('/\s*&&\s*/', $expression, 2);
            if (count($parts) === 2) {
                return $this->parseConditionExpression($parts[0])
                    && $this->parseConditionExpression($parts[1]);
            }
        }

        // Handle comparison operators
        $comparisonPatterns = [
            '/^(.+?)\s*===\s*(.+)$/' => 'strict_equal',
            '/^(.+?)\s*!==\s*(.+)$/' => 'strict_not_equal',
            '/^(.+?)\s*==\s*(.+)$/' => 'equal',
            '/^(.+?)\s*!=\s*(.+)$/' => 'not_equal',
            '/^(.+?)\s*<=\s*(.+)$/' => 'less_equal',
            '/^(.+?)\s*>=\s*(.+)$/' => 'greater_equal',
            '/^(.+?)\s*<\s*(.+)$/' => 'less',
            '/^(.+?)\s*>\s*(.+)$/' => 'greater',
        ];

        foreach ($comparisonPatterns as $pattern => $operator) {
            if (preg_match($pattern, $expression, $matches)) {
                $left = $this->parseConditionValue(trim($matches[1]));
                $right = $this->parseConditionValue(trim($matches[2]));

                return $this->compareValues($left, $right, $operator);
            }
        }

        // Handle negation
        if (preg_match('/^!\s*(.+)$/', $expression, $matches)) {
            return !$this->parseConditionExpression($matches[1]);
        }

        // Simple truthy check
        $value = $this->parseConditionValue($expression);
        return $this->isTruthy($value);
    }

    /**
     * Parse a value token from a condition expression.
     *
     * @param string $token The token to parse
     * @return mixed The parsed value
     */
    protected function parseConditionValue(string $token): mixed
    {
        $token = trim($token);

        // Boolean literals
        if ($token === 'true') {
            return true;
        }
        if ($token === 'false') {
            return false;
        }

        // Null literal
        if ($token === 'null') {
            return null;
        }

        // Quoted string
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $token, $matches)) {
            return stripcslashes($matches[1]);
        }
        if (preg_match("/^'((?:[^'\\\\]|\\\\.)*)'\$/", $token, $matches)) {
            return stripcslashes($matches[1]);
        }

        // Numeric values
        if (is_numeric($token)) {
            if (str_contains($token, '.')) {
                return (float) $token;
            }
            return (int) $token;
        }

        // Unquoted string (or unresolved variable reference)
        return $token;
    }

    /**
     * Compare two values using the specified operator.
     *
     * @param mixed $left Left operand
     * @param mixed $right Right operand
     * @param string $operator The comparison operator
     * @return bool The comparison result
     */
    protected function compareValues(mixed $left, mixed $right, string $operator): bool
    {
        // Numeric comparison when both are numeric
        $numericComparison = is_numeric($left) && is_numeric($right);

        if ($numericComparison) {
            $left = is_float($left) || is_float($right) ? (float) $left : (int) $left;
            $right = is_float($left) || is_float($right) ? (float) $right : (int) $right;
        }

        return match ($operator) {
            'strict_equal' => $left === $right,
            'strict_not_equal' => $left !== $right,
            'equal' => $left == $right,
            'not_equal' => $left != $right,
            'less' => $left < $right,
            'greater' => $left > $right,
            'less_equal' => $left <= $right,
            'greater_equal' => $left >= $right,
            default => false,
        };
    }

    /**
     * Check if a value is truthy.
     *
     * @param mixed $value The value to check
     * @return bool Whether the value is truthy
     */
    protected function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }

        if ($value === '') {
            return false;
        }

        if ($value === 0 || $value === 0.0 || $value === '0') {
            return false;
        }

        if (is_array($value) && empty($value)) {
            return false;
        }

        return true;
    }

    /**
     * Parse and evaluate a ternary expression with nested support.
     *
     * Supports:
     * - Simple ternary: $var ? value1 : value2
     * - Nested ternary with parentheses: $a ? ($b ? x : y) : z
     * - Deep nesting: $a ? ($b ? ($c ? 1 : 2) : 3) : 4
     * - Complex conditions: $count > 5 && $enabled ? many : few
     * - Variables in values: $debug ? $verbose_output : $short_output
     * - Nested ternaries without parentheses (right-associative): $a ? $b ? x : y : z
     *
     * @param string $expression The ternary expression to parse and evaluate
     * @return mixed The evaluated result (string, int, float, bool, null, or array)
     */
    protected function parseTernary(string $expression): mixed
    {
        $expression = trim($expression);

        if ($expression === '') {
            return '';
        }

        // Find the condition part (everything before first `?` at depth 0)
        $conditionEnd = $this->findTernaryOperatorPosition($expression, '?');

        // If no ternary operator found, resolve variables and return as-is
        if ($conditionEnd === -1) {
            return $this->variablesDetectString($expression);
        }

        $condition = trim(substr($expression, 0, $conditionEnd));
        $remainder = trim(substr($expression, $conditionEnd + 1));

        // Find the colon that separates true/false parts at depth 0
        $colonPosition = $this->findTernaryColonPosition($remainder);

        if ($colonPosition === -1) {
            // Malformed ternary - no colon found, treat as simple expression
            return $this->variablesDetectString($expression);
        }

        $truePart = trim(substr($remainder, 0, $colonPosition));
        $falsePart = trim(substr($remainder, $colonPosition + 1));

        // Evaluate the condition using evaluateCondition()
        $conditionResult = $this->evaluateCondition($condition);

        // Select the appropriate branch and recursively process
        $selectedPart = $conditionResult ? $truePart : $falsePart;

        // Remove outer parentheses if present
        $selectedPart = $this->stripOuterParentheses($selectedPart);

        // Check if the selected part contains another ternary
        if ($this->containsTernaryOperator($selectedPart)) {
            return $this->parseTernary($selectedPart);
        }

        // Resolve variables in the result
        return $this->variablesDetectString($selectedPart);
    }

    /**
     * Parse and evaluate a null-coalescing expression (??) with chaining support.
     *
     * PHP-style semantics:
     * - $a ?? $b - returns $a if $a EXISTS and is not null, otherwise $b
     * - Supports chaining: $a ?? $b ?? $c
     * - Supports nesting: $a ?? ($b ? x : y)
     *
     * @param string $expression The expression to parse
     * @return mixed The evaluated result
     */
    protected function parseNullCoalescing(string $expression): mixed
    {
        $expression = trim($expression);

        if ($expression === '') {
            return '';
        }

        // Find ?? operator at depth 0
        $operatorPos = $this->findNullCoalescingPosition($expression);

        if ($operatorPos === -1) {
            // No ?? found - this shouldn't happen if called correctly
            return $this->variablesDetectString($expression);
        }

        $leftPart = trim(substr($expression, 0, $operatorPos));
        $rightPart = trim(substr($expression, $operatorPos + 2)); // +2 for '??'

        // Strip outer parentheses from left part
        $leftPart = $this->stripOuterParentheses($leftPart);

        // Check if left part is a variable reference
        if (preg_match('/^\$([a-zA-Z_][a-zA-Z0-9_\.\-]*)$/', $leftPart, $matches)) {
            $varName = $matches[1];

            // Check if variable EXISTS and is not null
            if ($this->variableExists($varName)) {
                $value = $this->resolveVariable($varName);
                if ($value !== null) {
                    return $value;
                }
            }
        } else {
            // Left part is an expression - evaluate it
            $leftValue = $this->variablesDetectString($leftPart);
            if ($leftValue !== null && $leftValue !== '') {
                return $leftValue;
            }
        }

        // Left is null/undefined - process right part
        // Strip outer parentheses from right part
        $rightPart = $this->stripOuterParentheses($rightPart);

        // Check if right part contains another ?? (chaining)
        if ($this->findNullCoalescingPosition($rightPart) !== -1) {
            return $this->parseNullCoalescing($rightPart);
        }

        // Check if right part contains ternary
        if ($this->containsTernaryOperator($rightPart)) {
            return $this->parseTernary($rightPart);
        }

        // Check if right part contains elvis
        if ($this->findElvisPosition($rightPart) !== -1) {
            return $this->parseElvis($rightPart);
        }

        // Resolve variables in right part
        return $this->variablesDetectString($rightPart);
    }

    /**
     * Find the position of ?? operator at depth 0.
     * Respects parentheses nesting and quoted strings.
     *
     * @param string $expression The expression to search
     * @return int The position, or -1 if not found
     */
    protected function findNullCoalescingPosition(string $expression): int
    {
        $depth = 0;
        $length = strlen($expression);
        $inSingleQuote = false;
        $inDoubleQuote = false;

        for ($i = 0; $i < $length - 1; $i++) {
            $char = $expression[$i];
            $nextChar = $expression[$i + 1];
            $prevChar = $i > 0 ? $expression[$i - 1] : '';

            // Handle escape sequences
            if ($prevChar === '\\') {
                continue;
            }

            // Handle quotes
            if ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
                continue;
            }
            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
                continue;
            }

            // Skip if inside quotes
            if ($inSingleQuote || $inDoubleQuote) {
                continue;
            }

            // Handle parentheses
            if ($char === '(') {
                $depth++;
                continue;
            }
            if ($char === ')') {
                $depth--;
                continue;
            }

            // Found ?? at depth 0
            if ($char === '?' && $nextChar === '?' && $depth === 0) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * Parse and evaluate an elvis expression (?:).
     *
     * PHP-style semantics:
     * - $a ?: $b - returns $a if $a is truthy, otherwise $b
     * - Supports nesting: $a ?: ($b ?? c)
     *
     * @param string $expression The expression to parse
     * @return mixed The evaluated result
     */
    protected function parseElvis(string $expression): mixed
    {
        $expression = trim($expression);

        if ($expression === '') {
            return '';
        }

        // Find ?: operator at depth 0
        $operatorPos = $this->findElvisPosition($expression);

        if ($operatorPos === -1) {
            // No ?: found
            return $this->variablesDetectString($expression);
        }

        $leftPart = trim(substr($expression, 0, $operatorPos));
        $rightPart = trim(substr($expression, $operatorPos + 2)); // +2 for '?:'

        // Strip outer parentheses
        $leftPart = $this->stripOuterParentheses($leftPart);
        $rightPart = $this->stripOuterParentheses($rightPart);

        // Evaluate left part - resolve bare variable ($var) if needed
        $leftValue = $this->resolveExpressionValue($leftPart);

        // If left is truthy, return it
        if ($this->isTruthy($leftValue)) {
            return $leftValue;
        }

        // Left is falsy - process right part
        // Check if right part contains ?? (null-coalescing)
        if ($this->findNullCoalescingPosition($rightPart) !== -1) {
            return $this->parseNullCoalescing($rightPart);
        }

        // Check if right part contains another ?: (chaining)
        if ($this->findElvisPosition($rightPart) !== -1) {
            return $this->parseElvis($rightPart);
        }

        // Check if right part contains ternary
        if ($this->containsTernaryOperator($rightPart)) {
            return $this->parseTernary($rightPart);
        }

        // Resolve right part
        return $this->resolveExpressionValue($rightPart);
    }

    /**
     * Find the position of ?: (elvis) operator at depth 0.
     * Distinguishes ?: from ? : (ternary with content).
     *
     * @param string $expression The expression to search
     * @return int The position, or -1 if not found
     */
    protected function findElvisPosition(string $expression): int
    {
        $depth = 0;
        $length = strlen($expression);
        $inSingleQuote = false;
        $inDoubleQuote = false;

        for ($i = 0; $i < $length - 1; $i++) {
            $char = $expression[$i];
            $nextChar = $expression[$i + 1];
            $prevChar = $i > 0 ? $expression[$i - 1] : '';

            // Handle escape sequences
            if ($prevChar === '\\') {
                continue;
            }

            // Handle quotes
            if ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
                continue;
            }
            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
                continue;
            }

            // Skip if inside quotes
            if ($inSingleQuote || $inDoubleQuote) {
                continue;
            }

            // Handle parentheses
            if ($char === '(') {
                $depth++;
                continue;
            }
            if ($char === ')') {
                $depth--;
                continue;
            }

            // Found ?: at depth 0 (but NOT ??)
            if ($char === '?' && $nextChar === ':' && $depth === 0) {
                // Make sure it's not followed by another : (which would be weird but check anyway)
                return $i;
            }
        }

        return -1;
    }

    /**
     * Find the position of a ternary operator (? or :) at depth 0.
     * Respects parentheses nesting and quoted strings.
     *
     * @param string $expression The expression to search
     * @param string $operator The operator to find ('?' or ':')
     * @return int The position, or -1 if not found
     */
    protected function findTernaryOperatorPosition(string $expression, string $operator): int
    {
        $depth = 0;
        $length = strlen($expression);
        $inSingleQuote = false;
        $inDoubleQuote = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];
            $prevChar = $i > 0 ? $expression[$i - 1] : '';

            // Handle escape sequences
            if ($prevChar === '\\') {
                continue;
            }

            // Handle quotes
            if ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
                continue;
            }
            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
                continue;
            }

            // Skip if inside quotes
            if ($inSingleQuote || $inDoubleQuote) {
                continue;
            }

            // Handle parentheses
            if ($char === '(') {
                $depth++;
                continue;
            }
            if ($char === ')') {
                $depth--;
                continue;
            }

            // Found operator at depth 0
            if ($char === $operator && $depth === 0) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * Find the position of the colon that separates true/false parts in a ternary.
     * Handles nested ternaries by tracking question mark depth.
     *
     * @param string $expression The expression after the first '?'
     * @return int The position of the matching colon, or -1 if not found
     */
    protected function findTernaryColonPosition(string $expression): int
    {
        $parenDepth = 0;
        $ternaryDepth = 0;
        $length = strlen($expression);
        $inSingleQuote = false;
        $inDoubleQuote = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];
            $prevChar = $i > 0 ? $expression[$i - 1] : '';

            // Handle escape sequences
            if ($prevChar === '\\') {
                continue;
            }

            // Handle quotes
            if ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
                continue;
            }
            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
                continue;
            }

            // Skip if inside quotes
            if ($inSingleQuote || $inDoubleQuote) {
                continue;
            }

            // Handle parentheses
            if ($char === '(') {
                $parenDepth++;
                continue;
            }
            if ($char === ')') {
                $parenDepth--;
                continue;
            }

            // Skip if inside parentheses
            if ($parenDepth > 0) {
                continue;
            }

            // Track nested ternary operators (for right-associativity)
            if ($char === '?') {
                $ternaryDepth++;
                continue;
            }

            // Found colon - check if it's our matching colon
            if ($char === ':') {
                if ($ternaryDepth === 0) {
                    return $i;
                }
                $ternaryDepth--;
            }
        }

        return -1;
    }

    /**
     * Strip outer parentheses from an expression if present.
     *
     * @param string $expression The expression to process
     * @return string The expression without outer parentheses
     */
    protected function stripOuterParentheses(string $expression): string
    {
        $expression = trim($expression);

        if (!str_starts_with($expression, '(') || !str_ends_with($expression, ')')) {
            return $expression;
        }

        // Verify the parentheses are matching (not separate groups)
        $depth = 0;
        $length = strlen($expression);

        for ($i = 0; $i < $length - 1; $i++) {
            $char = $expression[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            // If depth reaches 0 before the end, parentheses are separate groups
            if ($depth === 0) {
                return $expression;
            }
        }

        // Parentheses wrap the entire expression - strip them
        return trim(substr($expression, 1, -1));
    }

    /**
     * Check if an expression contains a ternary operator at depth 0.
     *
     * @param string $expression The expression to check
     * @return bool True if a ternary operator exists at depth 0
     */
    protected function containsTernaryOperator(string $expression): bool
    {
        return $this->findTernaryOperatorPosition($expression, '?') !== -1;
    }

    /**
     * Extract all balanced brace expressions from a string.
     *
     * Finds all {content} patterns with properly balanced nested braces.
     * Returns array of [start_position, end_position, content] tuples.
     *
     * @param string $value The string to search
     * @return array<array{0: int, 1: int, 2: string}> Array of [start, end, content] tuples
     */
    protected function extractBalancedBraceExpressions(string $value): array
    {
        $expressions = [];
        $length = strlen($value);
        $i = 0;

        while ($i < $length) {
            // Find next opening brace
            $openPos = strpos($value, '{', $i);
            if ($openPos === false) {
                break;
            }

            // Track depth to find matching close brace
            $depth = 1;
            $pos = $openPos + 1;
            $inSingleQuote = false;
            $inDoubleQuote = false;

            while ($pos < $length && $depth > 0) {
                $char = $value[$pos];
                $prevChar = $pos > 0 ? $value[$pos - 1] : '';

                // Handle escape sequences (but not escaped backslash)
                if ($prevChar === '\\' && ($pos < 2 || $value[$pos - 2] !== '\\')) {
                    $pos++;
                    continue;
                }

                // Handle quotes
                if ($char === '"' && !$inSingleQuote) {
                    $inDoubleQuote = !$inDoubleQuote;
                    $pos++;
                    continue;
                }
                if ($char === "'" && !$inDoubleQuote) {
                    $inSingleQuote = !$inSingleQuote;
                    $pos++;
                    continue;
                }

                // Skip if inside quotes
                if ($inSingleQuote || $inDoubleQuote) {
                    $pos++;
                    continue;
                }

                // Track brace depth
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                }

                $pos++;
            }

            if ($depth === 0) {
                // Found a complete balanced expression
                $content = substr($value, $openPos + 1, $pos - $openPos - 2);
                $expressions[] = [$openPos, $pos - 1, $content];
            }

            $i = $pos;
        }

        return $expressions;
    }

    /**
     * Process a string replacing balanced brace ternary expressions.
     *
     * This method properly handles nested {$var} patterns inside ternary expressions
     * by using brace depth tracking instead of simple regex.
     *
     * @param string $value The string to process
     * @param callable $ternaryReplace The ternary replacement callback
     * @return string The processed string
     */
    protected function processBalancedTernaryExpressions(string $value, callable $ternaryReplace): string
    {
        // Process from right to left to preserve positions during replacement
        $expressions = $this->extractBalancedBraceExpressions($value);

        // Sort by start position descending (process from end to start)
        usort($expressions, fn($a, $b) => $b[0] <=> $a[0]);

        foreach ($expressions as [$start, $end, $content]) {
            // Check if this content is a conditional expression (??, ?:, or ternary)
            $hasNullCoalescing = str_contains($content, '??');
            $hasElvis = str_contains($content, '?:');
            $hasTernary = str_contains($content, '?') && str_contains($content, ':');

            if (!$hasNullCoalescing && !$hasElvis && !$hasTernary) {
                continue;
            }

            // Verify it has operators at depth 0
            $hasValidOperator = $this->findNullCoalescingPosition($content) !== -1
                || $this->findElvisPosition($content) !== -1
                || $this->containsTernaryOperator($content);

            if (!$hasValidOperator) {
                continue;
            }

            $result = $ternaryReplace($content);

            // If null returned, it wasn't a valid ternary - skip
            if ($result === null) {
                continue;
            }

            // Convert result to string for substitution
            $replacement = match (true) {
                is_bool($result) => $result ? 'true' : 'false',
                $result === null => 'null',
                is_array($result) => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                default => (string) $result,
            };

            // Replace the expression in the value
            $value = substr($value, 0, $start) . $replacement . substr($value, $end + 1);
        }

        return $value;
    }

    public function __get(string $name)
    {
        return $this->data[$name] ?? null;
    }

    public function __set(string $name, $value): void
    {
        $this->data[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->data[$name]);
    }
}

