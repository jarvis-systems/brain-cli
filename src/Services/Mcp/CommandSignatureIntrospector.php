<?php

declare(strict_types=1);

namespace BrainCLI\Services\Mcp;

use Illuminate\Console\Command;

/**
 * Introspects Laravel Console Command signatures to extract option metadata.
 *
 * FLOW: Cache → DefinitionProvider → Source Parse Fallback
 *
 * Used to keep McpToolSchema in sync with actual commands.
 */
final class CommandSignatureIntrospector
{
    /**
     * Extract options from a command's signature.
     *
     * @param  class-string<Command>  $commandClass
     * @return array<string, array{type: string, default?: mixed, has_default: bool}>
     */
    public static function extractOptions(string $commandClass): array
    {
        $cached = self::getFromCache($commandClass);

        if ($cached !== null) {
            return $cached['options'];
        }

        $definition = CommandDefinitionProvider::getDefinition($commandClass);

        if ($definition !== null) {
            self::storeToCache($commandClass, $definition);

            return $definition['options'];
        }

        $signature = self::getSignatureFromClass($commandClass);

        if ($signature === null) {
            return [];
        }

        $options = self::parseOptions($signature);

        $data = [
            'options' => $options,
            'arguments' => self::parseArguments($signature),
        ];
        self::storeToCache($commandClass, $data);

        return $options;
    }

    /**
     * Extract arguments from a command's signature.
     *
     * @param  class-string<Command>  $commandClass
     * @return array<string, array{type: string, default?: mixed, has_default: bool}>
     */
    public static function extractArguments(string $commandClass): array
    {
        $cached = self::getFromCache($commandClass);

        if ($cached !== null) {
            return $cached['arguments'];
        }

        $definition = CommandDefinitionProvider::getDefinition($commandClass);

        if ($definition !== null) {
            self::storeToCache($commandClass, $definition);

            return $definition['arguments'];
        }

        $signature = self::getSignatureFromClass($commandClass);

        if ($signature === null) {
            return [];
        }

        $arguments = self::parseArguments($signature);

        $data = [
            'options' => self::parseOptions($signature),
            'arguments' => $arguments,
        ];
        self::storeToCache($commandClass, $data);

        return $arguments;
    }

    /**
     * Get sorted option names.
     *
     * @param  class-string<Command>  $commandClass
     * @return list<string>
     */
    public static function getOptionNames(string $commandClass): array
    {
        $options = self::extractOptions($commandClass);
        $names = array_keys($options);
        sort($names);
        return $names;
    }

    /**
     * Get from cache if valid.
     *
     * @param  class-string<Command>  $commandClass
     * @return array{options: array<string, array{type: string, has_default: bool, default?: mixed}>, arguments: array<string, array{type: string, has_default: bool, default?: mixed}>}|null
     */
    private static function getFromCache(string $commandClass): ?array
    {
        $mtime = CommandDefinitionProvider::getFileMtime($commandClass);

        if ($mtime === 0) {
            return null;
        }

        return CommandSignatureCache::get($commandClass, $mtime);
    }

    /**
     * Store to cache.
     *
     * @param  class-string<Command>  $commandClass
     * @param  array{options: array<string, array{type: string, has_default: bool, default?: mixed}>, arguments: array<string, array{type: string, has_default: bool, default?: mixed}>}  $data
     */
    private static function storeToCache(string $commandClass, array $data): void
    {
        $mtime = CommandDefinitionProvider::getFileMtime($commandClass);

        if ($mtime === 0) {
            return;
        }

        CommandSignatureCache::set($commandClass, $mtime, $data);
        CommandSignatureCache::persist();
    }

    /**
     * Get signature string from class by parsing source file.
     */
    private static function getSignatureFromClass(string $commandClass): ?string
    {
        $reflection = new \ReflectionClass($commandClass);
        $filename = $reflection->getFileName();

        if ($filename === false) {
            return null;
        }

        $source = file_get_contents($filename);

        if ($source === false) {
            return null;
        }

        if (preg_match('/protected\s+\$signature\s*=\s*([\'"])(.*?)\1\s*;/s', $source, $matches)) {
            return $matches[2];
        }

        if (preg_match('/protected\s+string\s+\$signature\s*=\s*([\'"])(.*?)\1\s*;/s', $source, $matches)) {
            return $matches[2];
        }

        if (preg_match('/protected\s+\$signature\s*=\s*<<<\s*[\'"]?(\w+)[\'"]?\s*\n(.*?)\n\1\s*;/s', $source, $matches)) {
            return $matches[2];
        }

        return null;
    }

    /**
     * Parse options from signature string.
     *
     * @return array<string, array{type: string, default?: mixed, has_default: bool}>
     */
    private static function parseOptions(string $signature): array
    {
        $options = [];

        preg_match_all('/\{--([\w-]+)(?:=([^:\}]*))?\s*(?::\s*([^\}]*))?\}/', $signature, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = $match[1];
            $defaultRaw = $match[2] ?? null;
            $description = $match[3] ?? '';

            $hasDefault = $defaultRaw !== null && $defaultRaw !== '';
            $default = null;

            if ($hasDefault) {
                $defaultRaw = trim($defaultRaw);

                if (ctype_digit($defaultRaw)) {
                    $default = (int) $defaultRaw;
                } elseif ($defaultRaw === 'true') {
                    $default = true;
                } elseif ($defaultRaw === 'false') {
                    $default = false;
                } else {
                    $default = $defaultRaw;
                }
            }

            $type = self::determineType($default, $hasDefault, $description);

            $options[$name] = [
                'type' => $type,
                'has_default' => $hasDefault,
            ];

            if ($hasDefault) {
                $options[$name]['default'] = $default;
            }
        }

        return $options;
    }

    /**
     * Parse arguments from signature string.
     *
     * @return array<string, array{type: string, default?: mixed, has_default: bool}>
     */
    private static function parseArguments(string $signature): array
    {
        $arguments = [];

        preg_match_all('/\{(\w+)(?:\?|\*)?(?:=([^:\}]*))?\s*(?::\s*([^\}]*))?\}/', $signature, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = $match[1];
            $defaultRaw = $match[2] ?? null;

            $hasDefault = $defaultRaw !== null && $defaultRaw !== '';
            $default = $hasDefault ? trim($defaultRaw) : null;

            $arguments[$name] = [
                'type' => 'string',
                'has_default' => $hasDefault,
            ];

            if ($hasDefault && $default !== null) {
                $arguments[$name]['default'] = $default;
            }
        }

        return $arguments;
    }

    /**
     * Determine the JSON Schema type from signature clues.
     */
    private static function determineType(mixed $default, bool $hasDefault, string $description): string
    {
        if ($hasDefault) {
            if (is_int($default)) {
                return 'integer';
            }
            if (is_bool($default)) {
                return 'boolean';
            }
        }

        $lowerDesc = strtolower($description);

        if (str_contains($lowerDesc, 'number') || str_contains($lowerDesc, 'count') || str_contains($lowerDesc, 'limit')) {
            return 'integer';
        }

        if (str_contains($lowerDesc, 'enable') || str_contains($lowerDesc, 'disable') || str_contains($lowerDesc, 'include')) {
            return 'boolean';
        }

        if (! $hasDefault) {
            return 'boolean';
        }

        return 'string';
    }
}
