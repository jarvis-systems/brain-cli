<?php

declare(strict_types=1);

namespace BrainCLI\Services\Mcp;

use BrainCLI\Console\Commands\DocsCommand;
use BrainCLI\Console\Commands\ListMastersCommand;

/**
 * Canonical schema definitions for MCP tools.
 *
 * Single source of truth for docs_search and list_masters input schemas.
 * Derived from actual command signatures via CommandSignatureIntrospector.
 * Used by McpServeCommand to generate tools/list responses.
 */
final class McpToolSchema
{
    /**
     * MCP-specific overrides for schema generation.
     *
     * These are options that exist in MCP but not in CLI (or are renamed).
     */
    private const DOCS_SEARCH_OVERRIDES = [
        'query' => ['type' => 'string', 'description' => 'Search query string (alternative to keywords)'],
        'keywords' => ['type' => 'array', 'description' => 'Search keywords (OR logic, case-insensitive)'],
    ];

    /**
     * Options to exclude from MCP schema (internal CLI-only).
     */
    private const DOCS_SEARCH_EXCLUDE = ['json'];

    /**
     * Description overrides for options where CLI description differs from MCP.
     */
    private const DOCS_SEARCH_DESCRIPTIONS = [
        'as' => 'Filename for download (default: URL basename)',
        'cache' => 'Cache mode: on (default), off (disable)',
        'cache-health' => 'Show cache health report with recommendations',
        'cache-stats' => 'Show cache statistics (entries, hit rate, timings)',
        'clear-cache' => 'Clear the docs index cache',
        'code' => 'Extract code blocks with detected language and line ranges',
        'download' => 'Download doc from URL to .docs/sources/',
        'exact' => 'Exact phrase search (case-insensitive, use strict for case-sensitive)',
        'freshness' => 'Include only docs modified within N days (0 = no filter)',
        'global' => 'Search all .docs/ folders in project subdirectories',
        'headers' => 'Extract headers with line ranges (1=H1, 2=H1+H2, 3=H1+H2+H3)',
        'limit' => 'Max results (0 = unlimited)',
        'links' => 'Extract internal/external links from document',
        'matches' => 'Show keyword match locations with context',
        'scaffold' => 'Scaffold doc files for undocumented classes (or specific class name)',
        'snippets' => 'Include preview of header section content (max 200 chars)',
        'stats' => 'Include file stats (lines, words, size, hash)',
        'strict' => 'Make exact case-sensitive',
        'trust' => 'Minimum trust level: low|med|high',
        'undocumented' => 'Scan codebase for classes/methods without docs',
        'update' => 'Update all downloaded docs from their source URLs',
        'validate' => 'Validate documentation files for required fields and quality',
        'extract-keywords' => 'Extract top 10 frequent terms (--keywords in CLI)',
    ];

    /**
     * Enum constraints for specific options.
     */
    private const DOCS_SEARCH_ENUMS = [
        'trust' => ['low', 'med', 'high'],
        'cache' => ['on', 'off'],
    ];

    /**
     * Type overrides for options where introspector type detection is incorrect.
     */
    private const DOCS_SEARCH_TYPE_OVERRIDES = [
        'cache' => 'string',
        'freshness' => 'integer',
        'headers' => 'integer',
        'limit' => 'integer',
    ];

    /**
     * Canonical docs_search schema derived from DocsCommand.
     *
     * @return array<string, array{type: string, description?: string, default?: mixed, enum?: array<string>}>
     */
    public static function docsSearch(): array
    {
        $schema = [];

        $commandOptions = CommandSignatureIntrospector::extractOptions(DocsCommand::class);

        foreach (self::DOCS_SEARCH_OVERRIDES as $name => $config) {
            $schema[$name] = $config;
        }

        foreach ($commandOptions as $name => $config) {
            if (in_array($name, self::DOCS_SEARCH_EXCLUDE, true)) {
                continue;
            }

            $mcpName = $name === 'keywords' ? 'extract-keywords' : $name;

            $schema[$mcpName] = [
                'type' => self::mapType($config['type'], $mcpName),
            ];

            if (isset(self::DOCS_SEARCH_DESCRIPTIONS[$mcpName])) {
                $schema[$mcpName]['description'] = self::DOCS_SEARCH_DESCRIPTIONS[$mcpName];
            }

            if ($config['has_default'] && $mcpName !== 'extract-keywords') {
                $schema[$mcpName]['default'] = $config['default'];
            }

            if (isset(self::DOCS_SEARCH_ENUMS[$mcpName])) {
                $schema[$mcpName]['enum'] = self::DOCS_SEARCH_ENUMS[$mcpName];
            }
        }

        uksort($schema, 'strcasecmp');

        return $schema;
    }

    /**
     * Canonical list_masters schema derived from ListMastersCommand.
     *
     * @return array<string, array{type: string, description?: string, default?: mixed}>
     */
    public static function listMasters(): array
    {
        $schema = [];

        $commandArgs = CommandSignatureIntrospector::extractArguments(ListMastersCommand::class);

        foreach ($commandArgs as $name => $config) {
            $schema[$name] = [
                'type' => 'string',
                'description' => 'Agent type (claude, codex, gemini, qwen)',
            ];

            if ($config['has_default']) {
                $schema[$name]['default'] = $config['default'];
            }
        }

        uksort($schema, 'strcasecmp');

        return $schema;
    }

    /**
     * Canonical diagnose schema (empty - no arguments).
     *
     * @return array<string, array>
     */
    public static function diagnose(): array
    {
        return [];
    }

    /**
     * Get option names for docs_search (alphabetically sorted).
     *
     * @return list<string>
     */
    public static function docsSearchOptionNames(): array
    {
        $names = array_keys(self::docsSearch());
        sort($names);
        return $names;
    }

    /**
     * Get option names for list_masters (alphabetically sorted).
     *
     * @return list<string>
     */
    public static function listMastersOptionNames(): array
    {
        $names = array_keys(self::listMasters());
        sort($names);
        return $names;
    }

    /**
     * Get the introspected option names from DocsCommand (for drift detection).
     *
     * @return list<string>
     */
    public static function getDocsCommandOptionNames(): array
    {
        return CommandSignatureIntrospector::getOptionNames(DocsCommand::class);
    }

    /**
     * Get the introspected argument names from ListMastersCommand (for drift detection).
     *
     * @return list<string>
     */
    public static function getListMastersCommandArgumentNames(): array
    {
        $args = CommandSignatureIntrospector::extractArguments(ListMastersCommand::class);
        $names = array_keys($args);
        sort($names);
        return $names;
    }

    /**
     * Map introspected type to MCP schema type.
     */
    private static function mapType(string $introspectedType, string $optionName): string
    {
        if (isset(self::DOCS_SEARCH_TYPE_OVERRIDES[$optionName])) {
            return self::DOCS_SEARCH_TYPE_OVERRIDES[$optionName];
        }

        return $introspectedType;
    }
}
