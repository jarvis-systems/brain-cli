<?php

declare(strict_types=1);

namespace BrainCLI\Services\Mcp;

use Illuminate\Console\Command;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Console\Input\InputOption;

/**
 * Provides command definitions safely without execution.
 *
 * PRIMARY (opt-in via BRAIN_MCP_INTROSPECT_VIA_CONTAINER=1): Resolve via Laravel container.
 * FALLBACK: Use reflection without constructor instantiation.
 */
final class CommandDefinitionProvider
{
    private const CONTAINER_ENV = 'BRAIN_MCP_INTROSPECT_VIA_CONTAINER';

    /**
     * Known integer options (type override for schema).
     */
    private const KNOWN_INT_OPTIONS = ['limit', 'headers', 'freshness'];

    /**
     * Get command definition safely.
     *
     * @param  class-string<Command>  $commandClass
     * @return array{options: array<string, array{type: string, has_default: bool, default?: mixed}>, arguments: array<string, array{type: string, has_default: bool, default?: mixed}>}|null
     */
    public static function getDefinition(string $commandClass): ?array
    {
        if (self::shouldUseContainer()) {
            $fromContainer = self::getDefinitionFromContainer($commandClass);
            if ($fromContainer !== null) {
                return $fromContainer;
            }
        }

        return self::getDefinitionFromReflection($commandClass);
    }

    /**
     * Get file modification time for cache invalidation.
     *
     * @param  class-string<Command>  $commandClass
     */
    public static function getFileMtime(string $commandClass): int
    {
        try {
            $reflection = new ReflectionClass($commandClass);
            $filename = $reflection->getFileName();

            if ($filename === false) {
                return 0;
            }

            $mtime = @filemtime($filename);

            return $mtime !== false ? $mtime : 0;
        } catch (ReflectionException) {
            return 0;
        }
    }

    /**
     * Check if container path should be used.
     */
    private static function shouldUseContainer(): bool
    {
        $env = getenv(self::CONTAINER_ENV);

        return $env === '1' || $env === 'true';
    }

    /**
     * Get definition from Laravel container.
     *
     * @param  class-string<Command>  $commandClass
     * @return array{options: array<string, array{type: string, has_default: bool, default?: mixed}>, arguments: array<string, array{type: string, has_default: bool, default?: mixed}>}|null
     */
    private static function getDefinitionFromContainer(string $commandClass): ?array
    {
        try {
            $app = app();

            if (! $app->bound($commandClass)) {
                return null;
            }

            $command = $app->make($commandClass);

            if (! $command instanceof Command) {
                return null;
            }

            return self::extractDefinitionFromCommand($command);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get definition from reflection without container.
     *
     * @param  class-string<Command>  $commandClass
     * @return array{options: array<string, array{type: string, has_default: bool, default?: mixed}>, arguments: array<string, array{type: string, has_default: bool, default?: mixed}>}|null
     */
    private static function getDefinitionFromReflection(string $commandClass): ?array
    {
        try {
            $reflection = new ReflectionClass($commandClass);

            if (! $reflection->isInstantiable()) {
                return null;
            }

            $constructor = $reflection->getConstructor();

            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                return null;
            }

            /** @var Command $command */
            $command = $reflection->newInstanceWithoutConstructor();

            return self::extractDefinitionFromCommand($command);
        } catch (ReflectionException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extract definition from command instance (no execution).
     *
     * @return array{options: array<string, array{type: string, has_default: bool, default?: mixed}>, arguments: array<string, array{type: string, has_default: bool, default?: mixed}>}
     */
    private static function extractDefinitionFromCommand(Command $command): array
    {
        $definition = $command->getDefinition();

        $options = [];
        foreach ($definition->getOptions() as $option) {
            $name = $option->getName();

            if (self::isBuiltInOption($name)) {
                continue;
            }

            $hasDefault = $option->isValueOptional() || $option->getDefault() !== null;
            $default = $option->getDefault();
            $type = self::determineTypeFromOption($option, $name);

            $options[$name] = [
                'type' => $type,
                'has_default' => $hasDefault,
            ];

            if ($hasDefault && $default !== null) {
                $options[$name]['default'] = $default;
            }
        }

        $arguments = [];
        foreach ($definition->getArguments() as $argument) {
            $name = $argument->getName();
            $hasDefault = $argument->getDefault() !== null;
            $default = $argument->getDefault();

            $arguments[$name] = [
                'type' => 'string',
                'has_default' => $hasDefault,
            ];

            if ($hasDefault && $default !== null) {
                $arguments[$name]['default'] = $default;
            }
        }

        return [
            'options' => $options,
            'arguments' => $arguments,
        ];
    }

    /**
     * Check if option is a built-in Symfony option.
     */
    private static function isBuiltInOption(string $name): bool
    {
        return in_array($name, ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-interaction'], true);
    }

    /**
     * Determine type from InputOption.
     */
    private static function determineTypeFromOption(InputOption $option, string $name): string
    {
        if (! $option->acceptValue()) {
            return 'boolean';
        }

        if (in_array($name, self::KNOWN_INT_OPTIONS, true)) {
            return 'integer';
        }

        $default = $option->getDefault();

        if (is_int($default)) {
            return 'integer';
        }

        if (is_bool($default)) {
            return 'boolean';
        }

        return 'string';
    }
}
