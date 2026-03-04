<?php

declare(strict_types=1);

namespace BrainCLI\Services\Mcp;

use BrainCLI\Support\Brain;

final class BuiltinMcpServers
{
    public const ENV_ENTRYPOINT_JSON = 'BRAIN_CLI_ENTRYPOINT_JSON';
    public const ENV_DISABLE_MCP = 'BRAIN_DISABLE_MCP';

    public static function getBrainToolsEntry(string $agentId): array
    {
        $entry = [
            'type' => 'stdio',
            'command' => self::resolveCommand(),
            'args' => self::buildArgs($agentId),
        ];

        if ($config = Brain::getEnv('BRAIN_AI_CONFIG', false)) {
            if (! empty($config) && is_array($config)) {
                $entry['env'] = $config;
            }
        }

        return $entry;
    }

    public static function isEnabled(): bool
    {
        return ! Brain::getEnv(self::ENV_DISABLE_MCP, false);
    }

    private static function resolveCommand(): string
    {
        $envJson = getenv(self::ENV_ENTRYPOINT_JSON);
        if ($envJson !== false && $envJson !== '') {
            $parsed = json_decode($envJson, true);
            if (is_array($parsed) && isset($parsed[0]) && is_string($parsed[0])) {
                return $parsed[0];
            }
        }

        return 'brain';
    }

    private static function buildArgs(string $agentId): array
    {
        $envJson = getenv(self::ENV_ENTRYPOINT_JSON);
        $baseArgs = [];

        if ($envJson !== false && $envJson !== '') {
            $parsed = json_decode($envJson, true);
            if (is_array($parsed) && count($parsed) > 1) {
                $baseArgs = array_slice($parsed, 1);
            }
        }

        return array_merge(
            $baseArgs,
            ['mcp:serve', '--agent', $agentId]
        );
    }
}
