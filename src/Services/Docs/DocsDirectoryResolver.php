<?php

declare(strict_types=1);

namespace BrainCLI\Services\Docs;

use BrainCLI\Support\Brain;
use Symfony\Component\Finder\Finder;

/**
 * Resolves .docs/ directories for search scope.
 *
 * Default mode: single root .docs/ directory.
 * Global mode: discovers all .docs/ directories at depth 1-3
 * from project root, excluding vendor/node_modules/.git etc.
 */
class DocsDirectoryResolver
{
    /**
     * Directories excluded from global .docs/ discovery.
     *
     * @var array<int, string>
     */
    protected const EXCLUDE_DIRS = [
        'vendor',
        'node_modules',
        '.git',
        '.idea',
        'storage',
        'cache',
        'dist',
        'build',
        '__pycache__',
        '.venv',
    ];

    /**
     * Maximum depth for recursive .docs/ directory discovery.
     */
    protected const MAX_DEPTH = 3;

    /**
     * Resolve .docs/ directories based on mode.
     *
     * @param  bool  $global  Whether to search all subdirectories
     * @param  string|null  $projectDir  Project root (defaults to Brain::projectDirectory())
     * @return array<int, array{dir: string, prefix: string}>
     */
    public function resolve(bool $global, ?string $projectDir = null): array
    {
        $projectDir ??= Brain::projectDirectory();
        $rootDocs = $projectDir . DS . '.docs';

        if (!$global) {
            return is_dir($rootDocs)
                ? [['dir' => $rootDocs, 'prefix' => '.docs']]
                : [];
        }

        return $this->discoverAll($projectDir, $rootDocs);
    }

    /**
     * Discover all .docs/ directories recursively (depth 1-3 from project root).
     *
     * @return array<int, array{dir: string, prefix: string}>
     */
    protected function discoverAll(string $projectDir, string $rootDocs): array
    {
        $results = [];

        if (is_dir($rootDocs)) {
            $results[] = ['dir' => $rootDocs, 'prefix' => '.docs'];
        }

        $finder = new Finder();
        $finder->directories()
            ->in($projectDir)
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->name('.docs')
            ->depth('>= 1')
            ->depth('<= ' . self::MAX_DEPTH);

        foreach (self::EXCLUDE_DIRS as $exclude) {
            $finder->exclude($exclude);
        }

        $rootDocsReal = is_dir($rootDocs) ? realpath($rootDocs) : null;

        foreach ($finder as $dir) {
            $absolutePath = $dir->getRealPath();

            if ($absolutePath === $rootDocsReal) {
                continue;
            }

            $relativePath = $dir->getRelativePathname();

            $results[] = [
                'dir' => $absolutePath,
                'prefix' => $relativePath,
            ];
        }

        usort($results, fn(array $a, array $b): int => strcmp($a['prefix'], $b['prefix']));

        return $results;
    }
}
