<?php

declare(strict_types=1);

namespace BrainCLI\Services\Docs;

class TrustResolver
{
    /**
     * @return array{source: string, trust: array{level: string, reason: string}}
     */
    public function resolve(string $relativePath, string $pathPrefix, ?string $yamlUrl): array
    {
        $source = $this->inferSource($relativePath, $pathPrefix, $yamlUrl);
        $trust = $this->inferTrust($source, $yamlUrl);

        return [
            'source' => $source,
            'trust' => $trust,
        ];
    }

    public function inferSource(string $relativePath, string $pathPrefix, ?string $yamlUrl): string
    {
        if ($pathPrefix !== '.docs') {
            return 'external';
        }

        if (str_starts_with($relativePath, 'sources/') || $yamlUrl !== null) {
            return 'downloaded';
        }

        return 'local';
    }

    /**
     * @return array{level: string, reason: string}
     */
    public function inferTrust(string $source, ?string $yamlUrl): array
    {
        return match ($source) {
            'local' => [
                'level' => 'high',
                'reason' => 'Local project documentation',
            ],
            'downloaded' => $yamlUrl !== null
                ? ['level' => 'med', 'reason' => 'Downloaded from known URL']
                : ['level' => 'low', 'reason' => 'Downloaded without source URL'],
            'external' => [
                'level' => 'med',
                'reason' => 'External project documentation',
            ],
            default => [
                'level' => 'low',
                'reason' => 'Unknown source',
            ],
        };
    }
}
