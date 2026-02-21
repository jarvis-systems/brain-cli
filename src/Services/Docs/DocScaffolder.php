<?php

declare(strict_types=1);

namespace BrainCLI\Services\Docs;

use BrainCLI\Support\Brain;

/**
 * Generates scaffold documentation files for undocumented classes.
 *
 * Takes output from UndocumentedScanner and creates .docs/*.md files
 * with YAML front matter and method stubs. Never overwrites existing files.
 */
class DocScaffolder
{
    /**
     * Scaffold documentation for all undocumented classes.
     *
     * @param array<int, array{class: string, fqn: string, file: string, methods: array<int, string>, method_count: int}> $classes
     * @return array{created: array<int, array{class: string, path: string, methods: int}>, skipped: array<int, array{class: string, path: string, reason: string}>, total_created: int, total_skipped: int}
     */
    public function scaffoldAll(array $classes): array
    {
        $result = [
            'created' => [],
            'skipped' => [],
            'total_created' => 0,
            'total_skipped' => 0,
        ];

        foreach ($classes as $classInfo) {
            $outcome = $this->scaffoldOne($classInfo);

            if ($outcome['status'] === 'created') {
                $result['created'][] = $outcome['data'];
                $result['total_created']++;
            } else {
                $result['skipped'][] = $outcome['data'];
                $result['total_skipped']++;
            }
        }

        return $result;
    }

    /**
     * Scaffold documentation for a single class.
     *
     * @param array{class: string, fqn: string, file: string, methods: array<int, string>, method_count: int} $classInfo
     * @return array{status: string, data: array<string, mixed>}
     */
    public function scaffoldOne(array $classInfo): array
    {
        $relativePath = '.docs/' . $classInfo['class'] . '.md';
        $fullPath = Brain::projectDirectory($relativePath);

        if (file_exists($fullPath)) {
            return [
                'status' => 'skipped',
                'data' => [
                    'class' => $classInfo['class'],
                    'path' => $relativePath,
                    'reason' => 'already exists',
                ],
            ];
        }

        $content = $this->buildTemplate($classInfo);

        $docsDir = Brain::projectDirectory('.docs');
        if (!is_dir($docsDir)) {
            mkdir($docsDir, 0755, true);
        }

        file_put_contents($fullPath, $content);

        return [
            'status' => 'created',
            'data' => [
                'class' => $classInfo['class'],
                'path' => $relativePath,
                'methods' => $classInfo['method_count'],
            ],
        ];
    }

    /**
     * Build the markdown template for a class.
     *
     * @param array{class: string, fqn: string, file: string, methods: array<int, string>, method_count: int} $classInfo
     */
    public function buildTemplate(array $classInfo): string
    {
        $date = date('Y-m-d');
        $className = $classInfo['class'];
        $fqn = $classInfo['fqn'];
        $file = ltrim($classInfo['file'], '/\\');

        $yaml = <<<YAML
---
name: "{$className}"
description: "API reference for {$className}"
type: "api"
date: "{$date}"
---
YAML;

        $header = <<<MD

# {$className}

> **FQN:** `{$fqn}`
> **Source:** `{$file}`

## Overview

<!-- TODO: Brief description of what this class does -->
MD;

        $methods = '';
        if (!empty($classInfo['methods'])) {
            $methods = "\n\n## Methods";
            foreach ($classInfo['methods'] as $method) {
                $methods .= "\n\n### {$method}\n\n<!-- TODO: Description -->";
            }
        }

        return $yaml . "\n" . $header . $methods . "\n";
    }
}
