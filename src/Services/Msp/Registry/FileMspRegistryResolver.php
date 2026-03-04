<?php

declare(strict_types=1);

namespace BrainCLI\Services\Msp\Registry;

final class FileMspRegistryResolver implements MspRegistryResolverInterface
{
    private const FILENAME = 'msp-registry.json';

    private ?string $resolvedPath = null;

    public function __construct(
        private string $projectRoot = '',
    ) {
        if ($projectRoot === '') {
            $this->projectRoot = $this->detectProjectRoot();
        }
    }

    public function resolve(): array
    {
        $path = $this->path();

        if (! file_exists($path)) {
            return $this->emptyRegistry();
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return $this->emptyRegistry();
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            return $this->emptyRegistry();
        }

        return $this->normalize($data);
    }

    public function path(): string
    {
        if ($this->resolvedPath !== null) {
            return $this->resolvedPath;
        }

        $candidates = [
            $this->projectRoot . '/.brain-config/' . self::FILENAME,
            $this->projectRoot . '/.brain/config/' . self::FILENAME,
            $this->projectRoot . '/cli/' . self::FILENAME,
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $this->resolvedPath = $candidate;
                return $candidate;
            }
        }

        $this->resolvedPath = $candidates[0];
        return $this->resolvedPath;
    }

    public function exists(): bool
    {
        return file_exists($this->path());
    }

    private function detectProjectRoot(): string
    {
        $dir = dirname(__DIR__, 5);
        if (file_exists($dir . '/.brain-config')) {
            return $dir;
        }
        $cwd = getcwd();
        return $cwd !== false ? $cwd : $dir;
    }

    private function emptyRegistry(): array
    {
        return [
            'schema_version' => '1.0.0',
            'providers' => [],
        ];
    }

    private function normalize(array $data): array
    {
        $providers = $data['providers'] ?? [];
        if (! is_array($providers)) {
            $providers = [];
        }

        usort($providers, fn ($a, $b) => strcmp(
            $a['id'] ?? '',
            $b['id'] ?? ''
        ));

        return [
            'schema_version' => $data['schema_version'] ?? '1.0.0',
            'providers' => $providers,
        ];
    }
}
