<?php

declare(strict_types=1);

namespace BrainCLI\Services;

use BackedEnum;
use BrainCLI\Dto\Compile\Collect;
use BrainCLI\Dto\Compile\Data;
use BrainCLI\Enums\Agent;
use BrainCLI\Support\Brain;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Generates project-specific JSON Schema with dynamic autocomplete values.
 *
 * Enhances the base agent-schema.json with runtime values:
 * - Client enum from Agent::cases()
 * - Model examples from agent's models()
 * - Agent/command references from compiled files
 */
class SchemaGenerator
{
    protected array $baseSchema = [];

    /**
     * Create a new SchemaGenerator instance.
     *
     * @param string $baseSchemaPath Path to base agent-schema.json
     */
    public function __construct(
        protected string $baseSchemaPath
    ) {
        $this->loadBaseSchema();
    }

    /**
     * Load the base schema from the core package.
     */
    protected function loadBaseSchema(): void
    {
        if (!is_file($this->baseSchemaPath)) {
            throw new \RuntimeException("Base schema not found: {$this->baseSchemaPath}");
        }

        $content = file_get_contents($this->baseSchemaPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read base schema: {$this->baseSchemaPath}");
        }

        $schema = json_decode($content, true);
        if (!is_array($schema)) {
            throw new \RuntimeException("Invalid JSON in base schema: {$this->baseSchemaPath}");
        }

        $this->baseSchema = $schema;
    }

    /**
     * Generate enhanced schema with dynamic values.
     *
     * @param Collect $files Compiled files collection
     * @param Agent $agent Current agent for model context
     * @return array Enhanced schema array
     */
    public function generate(Collect $files, Agent $agent): array
    {
        $schema = $this->baseSchema;

        // Enhance client property with Agent enum values
        $schema = $this->enhanceClientProperty($schema);

        // Enhance params.model with examples from agent's models
        $schema = $this->enhanceModelProperty($schema, $agent);

        // Add $defs for agent and command references
        $schema = $this->addAgentReferenceDef($schema, $files->agents);
        $schema = $this->addCommandReferenceDef($schema, $files->commands);
        $schema = $this->addAvailableModelsDef($schema, $agent);
        $schema = $this->addBuiltInVariablesDef($schema, $agent);
        $schema = $this->addEnvVariablesDef($schema, $files);

        return $schema;
    }

    /**
     * Enhance the client property with Agent enum values.
     *
     * @param array $schema Current schema
     * @return array Enhanced schema
     */
    protected function enhanceClientProperty(array $schema): array
    {
        $clientValues = $this->getClientValues();

        if (isset($schema['properties']['client'])) {
            $schema['properties']['client']['enum'] = $clientValues;
        }

        return $schema;
    }

    /**
     * Enhance the params.model property with examples from ALL agents.
     *
     * @param array $schema Current schema
     * @param Agent $agent Agent (unused, we collect from ALL agents)
     * @return array Enhanced schema
     */
    protected function enhanceModelProperty(array $schema, Agent $agent): array
    {
        $modelValues = $this->getAllModelValues();

        if (isset($schema['properties']['params']['properties']['model'])) {
            $schema['properties']['params']['properties']['model']['examples'] = $modelValues;
        }

        return $schema;
    }

    /**
     * Add $defs.agentReference with pattern for available agents.
     *
     * @param array $schema Current schema
     * @param Collection $agents Compiled agents collection
     * @return array Enhanced schema
     */
    protected function addAgentReferenceDef(array $schema, iterable $agents): array
    {
        $agentNames = $this->getAgentNames(collect($agents));

        if (!isset($schema['$defs'])) {
            $schema['$defs'] = [];
        }

        $schema['$defs']['agentReference'] = [
            'type' => 'string',
            'description' => 'Reference to a compiled agent. Available agents: ' . implode(', ', $agentNames),
            'pattern' => $this->buildEnumPattern($agentNames),
            'examples' => array_slice($agentNames, 0, 5),
        ];

        return $schema;
    }

    /**
     * Add $defs.commandReference with pattern for available commands.
     *
     * @param array $schema Current schema
     * @param Collection $commands Compiled commands collection
     * @return array Enhanced schema
     */
    protected function addCommandReferenceDef(array $schema, iterable $commands): array
    {
        $commandNames = $this->getCommandNames(collect($commands));

        if (!isset($schema['$defs'])) {
            $schema['$defs'] = [];
        }

        $schema['$defs']['commandReference'] = [
            'type' => 'string',
            'description' => 'Reference to a compiled command. Available commands: ' . implode(', ', $commandNames),
            'pattern' => $this->buildEnumPattern($commandNames),
            'examples' => array_slice($commandNames, 0, 5),
        ];

        return $schema;
    }

    /**
     * Add $defs.availableModels with all model values from ALL agents.
     *
     * @param array $schema Current schema
     * @param Agent $agent Agent (unused, we collect from ALL agents)
     * @return array Enhanced schema
     */
    protected function addAvailableModelsDef(array $schema, Agent $agent): array
    {
        $models = $this->getAllModelValues();

        if (!isset($schema['$defs'])) {
            $schema['$defs'] = [];
        }

        $schema['$defs']['availableModels'] = [
            'type' => 'string',
            'description' => 'Available models from all clients: ' . implode(', ', array_slice($models, 0, 10)) . '...',
            'enum' => $models,
        ];

        return $schema;
    }

    /**
     * Add $defs.builtInVariables with all runtime variables.
     *
     * @param array $schema Current schema
     * @param Agent $agent Agent to get models from (unused, we collect from ALL agents)
     * @return array Enhanced schema
     */
    protected function addBuiltInVariablesDef(array $schema, Agent $agent): array
    {
        if (!isset($schema['$defs'])) {
            $schema['$defs'] = [];
        }

        // Build model variable keys from ALL agents (client can be dynamic)
        $modelKeys = [];
        foreach (Agent::cases() as $agentCase) {
            foreach ($agentCase->models() as $model) {
                $modelKeys['$_model.' . $model->name] = $agentCase->label();
            }
        }

        // All built-in variables with descriptions
        $variables = [
            // File and time
            '$_file' => 'Current YAML file path',
            '$_date' => 'Current date (YYYY-MM-DD)',
            '$_datetime' => 'Current datetime (YYYY-MM-DD HH:MM:SS)',
            '$_time' => 'Current time (HH:MM:SS)',
            '$_timestamp' => 'Unix timestamp',

            // Context
            '$_call_name' => 'Name of the calling command',
            '$_default_client' => 'Default AI client from config',

            // Paths
            '$_path.brain' => 'Brain working directory path',
            '$_path.cli' => 'CLI local directory path',
            '$_path.home' => 'User home directory path',
            '$_path.cwd' => 'Current working directory path',

            // System
            '$_system.uname' => 'Full uname string',
            '$_system.os' => 'Operating system name (PHP_OS_FAMILY)',
            '$_system.architecture' => 'System architecture',
            '$_system.processor' => 'Processor type',
            '$_system.hostname' => 'Machine hostname',
            '$_system.name' => 'System name',
            '$_system.release' => 'System release',
            '$_system.version' => 'System version',

            // User and environment
            '$_user_name' => 'Current username',
            '$_php_version' => 'PHP version',

            // Models
            '$_model' => 'Associative array of all models (NAME => value)',
            '$_model.general' => 'General purpose model for current agent',
        ];

        // Add dynamic model keys (key = $_model.NAME, value = agent label)
        foreach ($modelKeys as $modelKey => $agentLabel) {
            $modelName = str_replace('$_model.', '', $modelKey);
            $variables[$modelKey] = "Model constant: {$modelName} ({$agentLabel})";
        }

        $schema['$defs']['builtInVariables'] = [
            'type' => 'object',
            'description' => 'Built-in runtime variables available in YAML DSL. Access via $var or {$var} syntax.',
            'properties' => array_map(
                static fn(string $desc): array => [
                    'type' => 'string',
                    'description' => $desc,
                ],
                $variables
            ),
            'examples' => array_keys($variables),
        ];

        // Also add as enum for autocomplete
        $schema['$defs']['builtInVariableNames'] = [
            'type' => 'string',
            'description' => 'Available built-in variable names for autocomplete',
            'enum' => array_keys($variables),
        ];

        return $schema;
    }

    /**
     * Add $defs.envVariables with ENV configuration patterns for all archetypes.
     *
     * Generates patterns for:
     * - {CLASS_NAME}_RULE_{N} - Custom rules injection
     * - {CLASS_NAME}_GUIDELINE_{N} - Custom guidelines injection
     * - {CLASS_NAME}_MODEL - Model override per class
     * - MASTER_MODEL - Global master model
     * - {AGENT}_MASTER_MODEL - Per-agent type model (CLAUDE_MASTER_MODEL, etc.)
     * - {NAMESPACE}_{CLASS_NAME}_DISABLE - Disable archetype compilation
     *
     * @param array $schema Current schema
     * @param Collect $files Compiled files collection
     * @return array Enhanced schema
     */
    protected function addEnvVariablesDef(array $schema, Collect $files): array
    {
        if (!isset($schema['$defs'])) {
            $schema['$defs'] = [];
        }

        $envVars = [];

        // Global model variables
        $envVars['MASTER_MODEL'] = 'Global model override for all masters/agents';
        foreach (Agent::cases() as $agent) {
            $agentUpper = strtoupper($agent->value);
            $envVars["{$agentUpper}_MASTER_MODEL"] = "Default model for {$agent->label()} agents";
        }

        // Process all archetype types
        $archetypes = [
            'AGENTS' => $files->agents,
            'COMMANDS' => $files->commands,
            'SKILLS' => $files->skills,
            'MCP' => $files->mcp,
        ];

        foreach ($archetypes as $namespace => $collection) {
            foreach ($collection as $file) {
                $className = $this->getEnvClassName($file->classBasename);

                // Disable flag
                $envVars["{$namespace}_{$className}_DISABLE"] = "Disable {$file->classBasename} from compilation (true/false)";

                // Model override
                $envVars["{$className}_MODEL"] = "Model override for {$file->classBasename}";

                // Rules (indexed 0-9 as examples)
                for ($i = 0; $i < 3; $i++) {
                    $envVars["{$className}_RULE_{$i}"] = "Custom CRITICAL rule #{$i} for {$file->classBasename}";
                }

                // Guidelines (indexed 0-9 as examples)
                for ($i = 0; $i < 3; $i++) {
                    $envVars["{$className}_GUIDELINE_{$i}"] = "Custom guideline #{$i} for {$file->classBasename}";
                }
            }
        }

        // Add Brain-specific variables
        $envVars['BRAIN_DISABLE'] = 'Disable Brain compilation (true/false)';
        $envVars['BRAIN_MODEL'] = 'Model override for Brain';
        for ($i = 0; $i < 3; $i++) {
            $envVars["BRAIN_RULE_{$i}"] = "Custom CRITICAL rule #{$i} for Brain";
            $envVars["BRAIN_GUIDELINE_{$i}"] = "Custom guideline #{$i} for Brain";
        }

        // Sort for consistent output
        ksort($envVars);

        $schema['$defs']['envVariables'] = [
            'type' => 'object',
            'description' => 'Environment variables for .brain/.env file. Configure rules, guidelines, models, and disable flags per archetype.',
            'properties' => array_map(
                static fn(string $desc): array => [
                    'type' => 'string',
                    'description' => $desc,
                ],
                $envVars
            ),
            'examples' => array_slice(array_keys($envVars), 0, 20),
        ];

        // Also add patterns for autocomplete
        $schema['$defs']['envVariablePatterns'] = [
            'type' => 'object',
            'description' => 'ENV variable naming patterns',
            'properties' => [
                'rule' => [
                    'type' => 'string',
                    'description' => 'Pattern: {CLASS_NAME}_RULE_{N} where N is 0-based index',
                    'pattern' => '^[A-Z_]+_RULE_\\d+$',
                ],
                'guideline' => [
                    'type' => 'string',
                    'description' => 'Pattern: {CLASS_NAME}_GUIDELINE_{N} where N is 0-based index',
                    'pattern' => '^[A-Z_]+_GUIDELINE_\\d+$',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Pattern: {CLASS_NAME}_MODEL for per-class model override',
                    'pattern' => '^[A-Z_]+_MODEL$',
                ],
                'disable' => [
                    'type' => 'string',
                    'description' => 'Pattern: {NAMESPACE}_{CLASS_NAME}_DISABLE (true/false)',
                    'pattern' => '^(AGENTS|COMMANDS|SKILLS|MCP|INCLUDES)_[A-Z_]+_DISABLE$',
                ],
            ],
        ];

        return $schema;
    }

    /**
     * Convert class basename to ENV variable format.
     *
     * Follows the same logic as ArchetypeArchitecture::loadEnvInstructions():
     * - Remove namespace
     * - Replace \ with _
     * - Snake case
     * - Replace __ with _
     * - Uppercase
     * - Trim underscores
     *
     * @param string $classBasename Class basename (e.g., 'ExploreMaster')
     * @return string ENV format (e.g., 'EXPLORE_MASTER')
     */
    protected function getEnvClassName(string $classBasename): string
    {
        return Str::of($classBasename)
            ->snake()
            ->replace('__', '_')
            ->upper()
            ->trim('_')
            ->toString();
    }

    /**
     * Extract Agent enum values.
     *
     * @return array<string> Agent case values
     */
    public function getClientValues(): array
    {
        return array_map(
            static fn(Agent $agent): string => $agent->value,
            Agent::cases()
        );
    }

    /**
     * Extract model enum values from agent.
     *
     * @param Agent $agent Agent to get models from
     * @return array<string> Model values
     */
    public function getModelValues(Agent $agent): array
    {
        return array_map(
            static fn(BackedEnum $model): string => (string) $model->value,
            $agent->models()
        );
    }

    /**
     * Extract ALL model values from ALL agents.
     *
     * @return array<string> Unique model values from all agents
     */
    public function getAllModelValues(): array
    {
        $models = [];

        foreach (Agent::cases() as $agent) {
            foreach ($agent->models() as $model) {
                $models[] = (string) $model->value;
            }
        }

        return array_values(array_unique($models));
    }

    /**
     * Extract agent IDs from compiled agents.
     *
     * @param Collection<int, Data> $agents Compiled agents
     * @return array<string> Agent IDs
     */
    public function getAgentNames(Collection $agents): array
    {
        return $agents
            ->map(static fn(Data $data): string => $data->id)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Extract command IDs from compiled commands.
     *
     * @param Collection<int, Data> $commands Compiled commands
     * @return array<string> Command IDs
     */
    public function getCommandNames(Collection $commands): array
    {
        return $commands
            ->map(static fn(Data $data): string => $data->id)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Build a regex pattern that matches any of the given values.
     *
     * @param array<string> $values Values to match
     * @return string Regex pattern
     */
    protected function buildEnumPattern(array $values): string
    {
        if (empty($values)) {
            return '^$';
        }

        $escaped = array_map(
            static fn(string $value): string => preg_quote($value, '/'),
            $values
        );

        return '^(' . implode('|', $escaped) . ')$';
    }

    /**
     * Save generated schema to output path.
     *
     * @param string $outputPath Path to save schema
     * @return bool Success status
     */
    public function save(string $outputPath): bool
    {
        return (bool) file_put_contents(
            $outputPath,
            json_encode(
                $this->baseSchema,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
    }

    /**
     * Generate and save schema in one call.
     *
     * @param Collect $files Compiled files collection
     * @param Agent $agent Current agent for model context
     * @param string|null $outputPath Output path (defaults to .brain/agent-schema.json)
     * @return bool Success status
     */
    public function generateAndSave(Collect $files, Agent $agent, ?string $outputPath = null): bool
    {
        $schema = $this->generate($files, $agent);
        $this->baseSchema = $schema;

        $outputPath ??= Brain::workingDirectory('agent-schema.json');

        return $this->save($outputPath);
    }

    /**
     * Get the current schema array.
     *
     * @return array Current schema
     */
    public function getSchema(): array
    {
        return $this->baseSchema;
    }

    /**
     * Create a SchemaGenerator with the default base schema path.
     *
     * @return static
     */
    public static function createDefault(): static
    {
        $baseSchemaPath = Brain::workingDirectory(['vendor', 'jarvis-brain', 'core', 'agent-schema.json']);

        return new static($baseSchemaPath);
    }
}