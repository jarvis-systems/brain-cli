<?php

declare(strict_types=1);

namespace BrainCLI\Services\MemoryHygiene;

use BrainCLI\Services\Mcp\McpStdioClient;

/**
 * Builds the memory hygiene ledger by calling MCP tools
 * and assembling structured data matching the ledger.json schema.
 */
class LedgerBuilder
{
    public function __construct(
        protected McpStdioClient $client,
    ) {
    }

    /**
     * Build the full ledger array from MCP data.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $stats = $this->client->call('get_memory_stats');
        $uniqueTags = $this->client->call('get_unique_tags');
        $canonicalTags = $this->client->call('get_canonical_tags');
        $recentMemories = $this->client->call('list_recent_memories', ['limit' => 10]);

        $totalMemories = $stats['total_memories'] ?? 0;
        $memoryLimit = $stats['memory_limit'] ?? 0;
        $usagePct = $memoryLimit > 0 ? round($totalMemories / $memoryLimit, 5) : 0;

        return [
            'snapshot_date' => gmdate('Y-m-d\TH:i:s\Z'),
            'namespace' => $stats['namespace'] ?? 'unknown',
            'total_memories' => $totalMemories,
            'memory_limit' => $memoryLimit,
            'usage_pct' => $usagePct,
            'db_size_mb' => $stats['db_size_mb'] ?? 0,
            'embedding_model' => $stats['embedding_model'] ?? 'unknown',
            'embedding_dimensions' => $stats['embedding_dimensions'] ?? 0,
            'health_status' => $stats['health_status'] ?? 'Unknown',
            'categories' => $stats['categories'] ?? [],
            'unique_tags' => $this->countTags($uniqueTags),
            'canonical_tags' => $this->countTags($canonicalTags),
            'recent_memories' => $this->formatRecentMemories($recentMemories),
        ];
    }

    /**
     * Count tags from MCP response.
     *
     * @param  array<string, mixed>  $response
     */
    protected function countTags(array $response): int
    {
        // Response may have 'tags' array or 'count' field
        if (isset($response['count'])) {
            return (int) $response['count'];
        }

        if (isset($response['tags']) && is_array($response['tags'])) {
            return count($response['tags']);
        }

        // If response is a flat array of tags
        if (array_is_list($response)) {
            return count($response);
        }

        return 0;
    }

    /**
     * Format recent memories for ledger output.
     *
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    protected function formatRecentMemories(array $response): array
    {
        $memories = $response['memories'] ?? $response;

        if (! is_array($memories) || ! array_is_list($memories)) {
            return [];
        }

        $result = [];

        foreach (array_slice($memories, 0, 10) as $memory) {
            if (! is_array($memory)) {
                continue;
            }

            $result[] = [
                'id' => $memory['id'] ?? null,
                'preview' => $memory['content'] ?? $memory['preview'] ?? '',
                'created' => $memory['created_at'] ?? $memory['created'] ?? '',
            ];
        }

        return $result;
    }
}
