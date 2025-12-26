<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Screens;

use BrainCLI\Console\AiCommands\Lab\Abstracts\ScreenAbstract;
use BrainCLI\Console\AiCommands\Lab\Dto\Context;

/**
 * Transform pipeline screen for ^ direction.
 *
 * Provides 20+ built-in transforms: upper, lower, trim, json, keys, values,
 * first, last, count, sum, filter, pluck, reverse, sort, unique, flatten, etc.
 */
class TransformScreen extends ScreenAbstract
{
    /**
     * Initialize transform screen metadata.
     *
     * Sets screen name and display title for the transform pipeline.
     */
    public function __construct()
    {
        parent::__construct(
            name: 'transform',
            title: 'Transform Functions',
            description: 'Apply transformations to current result',
            argumentDescription: '<transform[:param]>',
            detectRegexp: null
        );
    }

    /**
     * Execute transform operation on context result.
     *
     * Parses transform:param syntax and applies transformation.
     * Supports 20+ operations:
     * - String: upper, lower, trim
     * - Array info: count, keys, values
     * - Array access: first, last
     * - Array manipulation: reverse, sort, unique, flatten
     * - Serialization: json, array
     * - Numeric: sum
     * - Parameterized: take:N, skip:N, chunk:N, pluck:field, filter:key=val
     *
     * @param Context $response Current execution context with result to transform
     * @param string $transform Transform name (e.g., 'upper', 'filter:status=active')
     * @param string $param Optional parameter for parameterized transforms
     * @return Context Updated context with transformed result
     */
    public function main(Context $response, string $transform = '', ?string $param = null): Context
    {
        $result = $response->result ?? [];

        // Parse transform and parameter from colon syntax: transform:param
        $parts = explode(':', $transform, 2);
        $transformName = strtolower($parts[0]);
        $param = $parts[1] ?? $param ?? null;

        try {
            $result = match ($transformName) {
                // String transforms (array-aware)
                'upper' => is_array($result)
                    ? array_map('strtoupper', $result)
                    : strtoupper((string)$result),

                'lower' => is_array($result)
                    ? array_map('strtolower', $result)
                    : strtolower((string)$result),

                'trim' => is_array($result)
                    ? array_map('trim', $result)
                    : trim((string)$result),

                // Array info
                'count' => ['count' => is_array($result)
                    ? count($result)
                    : strlen((string)$result)],

                'keys' => is_array($result)
                    ? array_keys($result)
                    : [],

                'values' => is_array($result)
                    ? array_values($result)
                    : [$result],

                // Array access
                'first' => is_array($result)
                    ? (reset($result) ?: null)
                    : $result,

                'last' => is_array($result)
                    ? (end($result) ?: null)
                    : $result,

                // Array manipulation
                'reverse' => is_array($result)
                    ? array_reverse($result)
                    : strrev((string)$result),

                'sort' => (function() use ($result) {
                    if (!is_array($result)) {
                        return $result;
                    }
                    sort($result);
                    return $result;
                })(),

                'unique' => is_array($result)
                    ? array_unique($result)
                    : $result,

                'flatten' => is_array($result)
                    ? array_merge(...array_map(fn($v) => is_array($v) ? $v : [$v], $result))
                    : [$result],

                // Serialization
                'json' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),

                'array' => (array)$result,

                // Parameterized transforms
                'take' => is_array($result)
                    ? array_slice($result, 0, (int)($param ?: 10))
                    : $result,

                'skip' => is_array($result)
                    ? array_slice($result, (int)($param ?: 0))
                    : $result,

                'chunk' => is_array($result)
                    ? array_chunk($result, (int)($param ?: 1))
                    : [$result],

                'pluck' => is_array($result)
                    ? array_column($result, $param)
                    : $result,

                'filter' => (function() use ($result, $param) {
                    if (!is_array($result) || !$param) {
                        return $result;
                    }
                    [$field, $val] = explode('=', $param, 2) + [null, null];
                    return array_filter($result, fn($item) =>
                        is_array($item) && isset($item[$field]) && $item[$field] == $val
                    );
                })(),

                'map' => is_array($result)
                    ? array_map(fn($item) => is_array($item) ? ($item[$param] ?? null) : null, $result)
                    : $result,

                // Numeric
                'sum' => ['sum' => is_array($result)
                    ? array_sum(array_map(fn($v) => is_numeric($v) ? (float)$v : 0, $result))
                    : (is_numeric($result) ? (float)$result : 0)],

                default => throw new \InvalidArgumentException(sprintf('Unknown transform: %s', $transformName)),
            };

            // Check for modifier in context meta or assume no modifier
            // You can get meta data from dto only with this method "getMeta"
            // DTO don't have a real "meta" property only (setMeta and getMeta)
            $modifier = $this->getMeta('modifier', '');

            return $response->result($result, $modifier === '+');

        } catch (\Throwable $e) {
            return $response->error("Transform '^{$transformName}' failed: {$e->getMessage()}");
        }
    }

    /**
     * Return autocomplete options for available transforms.
     *
     * Provides list of all supported transform commands for
     * command completion in the REPL interface.
     *
     * @param string ...$args Optional filter arguments
     * @return array List of transform command strings
     */
    public function options(string ...$args): array
    {
        $transforms = [
            // String transforms
            'upper' => 'Uppercase string',
            'lower' => 'Lowercase string',
            'trim' => 'Trim whitespace',

            // Array info
            'count' => 'Count elements',
            'keys' => 'Get array keys',
            'values' => 'Get array values',

            // Array access
            'first' => 'First element',
            'last' => 'Last element',

            // Array manipulation
            'reverse' => 'Reverse order',
            'sort' => 'Sort array',
            'unique' => 'Remove duplicates',
            'flatten' => 'Flatten nested array',

            // Serialization
            'json' => 'Convert to JSON',
            'array' => 'Cast to array',

            // Numeric
            'sum' => 'Sum numeric values',

            // Parameterized (show with example syntax)
            'take:N' => 'Take first N elements',
            'skip:N' => 'Skip first N elements',
            'chunk:N' => 'Split into N-sized chunks',
            'pluck:field' => 'Extract field from items',
            'filter:key=val' => 'Filter by field value',
            'map:field' => 'Extract field from each item',
        ];

        // If argument provided, filter by prefix
        $prefix = $args[0] ?? '';
        if ($prefix !== '') {
            $transforms = array_filter(
                $transforms,
                fn($key) => str_starts_with($key, $prefix),
                ARRAY_FILTER_USE_KEY
            );
        }

        return $transforms;
    }
}