<?php

declare(strict_types=1);

namespace BrainCLI\Services\Docs;

use BrainCLI\Support\Brain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Polyglot scanner for classes/functions without documentation.
 *
 * Supports: PHP, JavaScript/TypeScript, Python, Go.
 * Scans source directories, extracts public API, cross-references with .docs/*.md.
 */
class UndocumentedScanner
{
    /**
     * Language configurations for class/function extraction.
     *
     * Each config: [extensions, class_pattern, function_pattern, namespace_pattern, suffixes_for_doc_check]
     *
     * @var array<string, array{
     *     extensions: array<int, string>,
     *     class_pattern: string,
     *     function_pattern: string,
     *     namespace_pattern: string|null,
     *     suffixes: array<int, string>
     * }>
     */
    protected const LANGUAGE_CONFIGS = [
        'php' => [
            'extensions' => ['.php'],
            'class_pattern' => '/^(?:abstract\s+)?class\s+(\w+)/m',
            'function_pattern' => '/public\s+function\s+(\w+)\s*\(/',
            'namespace_pattern' => '/namespace\s+([^;]+);/',
            'suffixes' => ['Controller', 'Service', 'Repository', 'Model', 'Helper', 'Factory', 'Provider', 'Middleware', 'Command', 'Job', 'Event', 'Listener', 'Policy', 'Request', 'Resource', 'Exception', 'Master', 'Trait', 'Include', 'Skill', 'Mcp'],
        ],
        'javascript' => [
            'extensions' => ['.js', '.mjs', '.cjs', '.jsx'],
            'class_pattern' => '/^\s*(?:export\s+)?(?:default\s+)?class\s+(\w+)/m',
            'function_pattern' => '/^\s*(?:export\s+)?(?:async\s+)?function\s+(\w+)\s*\(/m',
            'namespace_pattern' => null,
            'suffixes' => ['Controller', 'Service', 'Repository', 'Model', 'Helper', 'Factory', 'Provider', 'Middleware', 'Handler', 'Plugin', 'Module', 'Component'],
        ],
        'typescript' => [
            'extensions' => ['.ts', '.mts', '.cts', '.tsx'],
            'class_pattern' => '/^\s*(?:export\s+)?(?:default\s+)?(?:abstract\s+)?class\s+(\w+)/m',
            'function_pattern' => '/^\s*(?:export\s+)?(?:async\s+)?function\s+(\w+)\s*[<(]/m',
            'namespace_pattern' => null,
            'suffixes' => ['Controller', 'Service', 'Repository', 'Model', 'Helper', 'Factory', 'Provider', 'Middleware', 'Handler', 'Plugin', 'Module', 'Component', 'Interface', 'Type'],
        ],
        'python' => [
            'extensions' => ['.py'],
            'class_pattern' => '/^\s*class\s+(\w+)\s*[:(]/m',
            'function_pattern' => '/^\s*def\s+(\w+)\s*\(/m',
            'namespace_pattern' => null,
            'suffixes' => ['Controller', 'Service', 'Repository', 'Model', 'Helper', 'Factory', 'Provider', 'Middleware', 'Handler', 'View', 'Serializer', 'Form', 'Admin', 'Manager'],
        ],
        'go' => [
            'extensions' => ['.go'],
            'class_pattern' => '/^\s*type\s+(\w+)\s+struct\s*\{/m',
            'function_pattern' => '/^\s*func\s+(?:\(\w+\s+\*?\w+\)\s+)?(\w+)\s*\(/m',
            'namespace_pattern' => '/^\s*package\s+(\w+)/m',
            'suffixes' => ['Controller', 'Service', 'Repository', 'Model', 'Helper', 'Handler', 'Middleware', 'Server', 'Client', 'Store'],
        ],
    ];

    /**
     * Directories to exclude from scanning.
     *
     * @var array<int, string>
     */
    protected const EXCLUDE_PATTERNS = [
        '/vendor/',
        '/.git/',
        '/node_modules/',
        '/.idea/',
        '/storage/',
        '/cache/',
        '/dist/',
        '/build/',
        '/__pycache__/',
        '/.venv/',
    ];

    /**
     * Default scan directories (relative to project root).
     *
     * @var array<int, string>
     */
    protected const DEFAULT_SCAN_DIRS = ['src', 'app', 'lib', 'classes', 'node'];

    /**
     * Package source directories (package/src pattern).
     *
     * @var array<int, string>
     */
    protected const PACKAGE_DIRS = ['cli', 'core'];

    /**
     * Scan for undocumented classes/structs/modules.
     *
     * @param int $limit Maximum number of results (0 = unlimited)
     * @return array{classes: array<int, array<string, mixed>>, total_scanned: int, total_undocumented: int, scan_dirs: array<int, string>}
     */
    public function scan(int $limit = 20): array
    {
        $projectDir = Brain::projectDirectory();
        $docsDir = Brain::projectDirectory('.docs');

        $existingDocs = $this->collectDocumentedNames($docsDir);
        $scanDirs = $this->resolveScanDirs($projectDir);

        $result = [
            'classes' => [],
            'total_scanned' => 0,
            'scan_dirs' => array_map(
                fn(string $d) => str_replace($projectDir, '', $d),
                $scanDirs,
            ),
        ];

        foreach ($scanDirs as $scanDir) {
            $files = File::allFiles($scanDir);

            foreach ($files as $file) {
                $filePath = $file->getPathname();

                if ($this->shouldExclude($filePath)) {
                    continue;
                }

                $language = $this->detectFileLanguage($filePath);
                if ($language === null) {
                    continue;
                }

                $result['total_scanned']++;
                $content = file_get_contents($filePath);
                if (!$content) {
                    continue;
                }

                $config = self::LANGUAGE_CONFIGS[$language];
                $extracted = $this->extractClassInfo($content, $config, $filePath, $projectDir, $existingDocs);

                if ($extracted !== null) {
                    $result['classes'][] = $extracted;
                }
            }
        }

        usort($result['classes'], fn(array $a, array $b) => $b['method_count'] <=> $a['method_count']);

        if ($limit > 0) {
            $result['classes'] = array_slice($result['classes'], 0, $limit);
        }

        $result['total_undocumented'] = count($result['classes']);

        return $result;
    }

    /**
     * Collect documented class/struct names from .docs/*.md files.
     *
     * @return array<int, string>
     */
    protected function collectDocumentedNames(string $docsDir): array
    {
        $names = [];

        if (!is_dir($docsDir)) {
            return $names;
        }

        $allSuffixes = [];
        foreach (self::LANGUAGE_CONFIGS as $config) {
            $allSuffixes = array_merge($allSuffixes, $config['suffixes']);
        }
        $allSuffixes = array_unique($allSuffixes);
        $suffixPattern = implode('|', $allSuffixes);

        $docFiles = File::allFiles($docsDir);
        foreach ($docFiles as $file) {
            if (!$this->isMarkdownFile($file->getPathname())) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if (!$content) {
                continue;
            }

            preg_match_all(
                '/\b([A-Z][a-zA-Z]+(?:' . $suffixPattern . '))\b/',
                $content,
                $classMatches,
            );

            $names = array_merge($names, $classMatches[1] ?? []);
        }

        return array_values(array_unique($names));
    }

    /**
     * Resolve directories to scan based on project structure and env config.
     *
     * @return array<int, string>
     */
    protected function resolveScanDirs(string $projectDir): array
    {
        $scanDirs = [];

        // Check env for custom scan dirs
        $envDirs = Brain::getEnv('DOCS_SCAN_DIRS');
        $dirList = $envDirs !== null
            ? array_filter(array_map('trim', explode(',', (string) $envDirs)))
            : self::DEFAULT_SCAN_DIRS;

        foreach ($dirList as $dir) {
            $fullPath = $projectDir . DS . $dir;
            if (is_dir($fullPath)) {
                $scanDirs[] = $fullPath;
            }
        }

        foreach (self::PACKAGE_DIRS as $package) {
            $packageSrc = $projectDir . DS . $package . DS . 'src';
            if (is_dir($packageSrc)) {
                $scanDirs[] = $packageSrc;
            }
        }

        return $scanDirs;
    }

    /**
     * Detect file language from its extension.
     */
    protected function detectFileLanguage(string $filePath): ?string
    {
        foreach (self::LANGUAGE_CONFIGS as $language => $config) {
            foreach ($config['extensions'] as $ext) {
                if (str_ends_with($filePath, $ext)) {
                    return $language;
                }
            }
        }

        return null;
    }

    /**
     * Check if a file path should be excluded.
     */
    protected function shouldExclude(string $filePath): bool
    {
        foreach (self::EXCLUDE_PATTERNS as $pattern) {
            if (Str::contains($filePath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a file path has a supported markdown extension (.md or .mdx).
     */
    protected function isMarkdownFile(string $path): bool
    {
        return str_ends_with($path, '.md') || str_ends_with($path, '.mdx');
    }

    /**
     * Extract class/struct info from file content.
     *
     * @param array<string, mixed> $config Language config
     * @param array<int, string> $existingDocs Already-documented names
     * @return array<string, mixed>|null Null if no class found or class is documented
     */
    protected function extractClassInfo(
        string $content,
        array $config,
        string $filePath,
        string $projectDir,
        array $existingDocs,
    ): ?array {
        preg_match($config['class_pattern'], $content, $classMatch);

        if (empty($classMatch)) {
            return null;
        }

        $className = $classMatch[1];

        if (in_array($className, $existingDocs, true)) {
            return null;
        }

        $fqn = $className;
        if ($config['namespace_pattern'] !== null) {
            preg_match($config['namespace_pattern'], $content, $nsMatch);
            if (!empty($nsMatch[1])) {
                $fqn = $nsMatch[1] . '\\' . $className;
            }
        }

        $publicMethods = [];
        preg_match_all($config['function_pattern'], $content, $methodMatches);

        foreach ($methodMatches[1] ?? [] as $method) {
            // Exclude magic methods for PHP, private/protected-ish for Python
            if (!str_starts_with($method, '__') && !str_starts_with($method, '_')) {
                $publicMethods[] = $method;
            }
        }

        return [
            'class' => $className,
            'fqn' => $fqn,
            'file' => str_replace($projectDir, '', $filePath),
            'methods' => $publicMethods,
            'method_count' => count($publicMethods),
        ];
    }
}
