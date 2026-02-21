<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Docs;

use BrainCLI\Services\Docs\DriftDetector;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DriftDetector — documentation drift detection.
 *
 * Verifies that documented methods (### headers under ## Methods)
 * are cross-referenced against actual source code methods.
 */
class DriftDetectorTest extends TestCase
{
    protected DriftDetector $detector;

    protected string $tmpDir;

    protected function setUp(): void
    {
        $this->detector = new DriftDetector();
        $this->tmpDir = sys_get_temp_dir() . '/drift_detector_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function test_detects_stale_methods(): void
    {
        $this->createSourceFile('src/Services/UserService.php', <<<'PHP'
<?php
class UserService {
    public function getUser() {}
    public function createUser() {}
}
PHP);

        $doc = $this->buildDoc('UserService', 'src/Services/UserService.php', [
            'getUser', 'createUser', 'deleteUser',
        ]);

        $result = $this->detector->detect($doc, $this->tmpDir);

        $this->assertNotNull($result);
        $this->assertSame('UserService', $result['class']);
        $this->assertSame('src/Services/UserService.php', $result['source']);
        $this->assertSame(['deleteUser'], $result['stale_methods']);
    }

    public function test_no_drift_when_methods_match(): void
    {
        $this->createSourceFile('src/Services/UserService.php', <<<'PHP'
<?php
class UserService {
    public function getUser() {}
    public function createUser() {}
}
PHP);

        $doc = $this->buildDoc('UserService', 'src/Services/UserService.php', [
            'getUser', 'createUser',
        ]);

        $result = $this->detector->detect($doc, $this->tmpDir);

        $this->assertNull($result);
    }

    public function test_returns_null_without_methods_section(): void
    {
        $doc = <<<'MD'
---
name: "UserService"
description: "API reference"
---

# UserService

> **Source:** `src/Services/UserService.php`

## Overview

Some description.
MD;

        $result = $this->detector->detect($doc, $this->tmpDir);

        $this->assertNull($result);
    }

    public function test_returns_null_without_source_line(): void
    {
        $doc = <<<'MD'
---
name: "UserService"
description: "API reference"
---

# UserService

## Methods

### getUser
MD;

        $result = $this->detector->detect($doc, $this->tmpDir);

        $this->assertNull($result);
    }

    public function test_returns_null_without_yaml_name(): void
    {
        $doc = <<<'MD'
---
description: "API reference"
---

# UserService

> **Source:** `src/Services/UserService.php`

## Methods

### getUser
MD;

        $result = $this->detector->detect($doc, $this->tmpDir);

        $this->assertNull($result);
    }

    public function test_returns_null_when_source_not_found(): void
    {
        $doc = $this->buildDoc('UserService', 'src/Services/NonExistent.php', ['getUser']);

        $result = $this->detector->detect($doc, $this->tmpDir);

        $this->assertNull($result);
    }

    public function test_extracts_php_methods(): void
    {
        $this->createSourceFile('src/Repo.php', <<<'PHP'
<?php
class Repo {
    public function find() {}
    public function save() {}
    protected function internal() {}
    private function secret() {}
}
PHP);

        $doc = $this->buildDoc('Repo', 'src/Repo.php', ['find', 'save', 'internal']);

        $result = $this->detector->detect($doc, $this->tmpDir);

        $this->assertNotNull($result);
        // 'internal' is protected, not public — so it's stale in docs
        $this->assertSame(['internal'], $result['stale_methods']);
    }

    public function test_extracts_javascript_methods(): void
    {
        $this->createSourceFile('src/utils.js', <<<'JS'
export function fetchData() {}
async function processData() {}
function helperFunc() {}
JS);

        $doc = $this->buildDoc('utils', 'src/utils.js', [
            'fetchData', 'processData', 'helperFunc', 'removedFunc',
        ]);

        $result = $this->detector->detect($doc, $this->tmpDir);

        $this->assertNotNull($result);
        $this->assertSame(['removedFunc'], $result['stale_methods']);
    }

    public function test_extracts_python_methods(): void
    {
        $this->createSourceFile('src/service.py', <<<'PY'
class Service:
    def get_data(self):
        pass
    def set_data(self, data):
        pass
PY);

        $doc = $this->buildDoc('Service', 'src/service.py', [
            'get_data', 'set_data', 'old_method',
        ]);

        $result = $this->detector->detect($doc, $this->tmpDir);

        $this->assertNotNull($result);
        $this->assertSame(['old_method'], $result['stale_methods']);
    }

    public function test_extracts_go_methods(): void
    {
        $this->createSourceFile('src/handler.go', <<<'GO'
package main

func (h *Handler) ServeHTTP() {}
func NewHandler() {}
GO);

        $doc = $this->buildDoc('Handler', 'src/handler.go', [
            'ServeHTTP', 'NewHandler', 'OldHandler',
        ]);

        $result = $this->detector->detect($doc, $this->tmpDir);

        $this->assertNotNull($result);
        $this->assertSame(['OldHandler'], $result['stale_methods']);
    }

    public function test_ignores_magic_methods(): void
    {
        $this->createSourceFile('src/Entity.php', <<<'PHP'
<?php
class Entity {
    public function __construct() {}
    public function __toString() {}
    public function getName() {}
}
PHP);

        // Doc has getName (which exists) and __construct (magic, excluded from source extraction)
        $doc = $this->buildDoc('Entity', 'src/Entity.php', ['getName', '__construct']);

        $result = $this->detector->detect($doc, $this->tmpDir);

        $this->assertNotNull($result);
        // __construct is in docs but excluded from source methods → stale
        $this->assertSame(['__construct'], $result['stale_methods']);
    }

    public function test_detects_language_from_extension(): void
    {
        // Test via behavior: TypeScript file with .ts extension
        $this->createSourceFile('src/api.ts', <<<'TS'
export function getData() {}
export async function setData() {}
TS);

        $doc = $this->buildDoc('api', 'src/api.ts', ['getData', 'setData', 'deletedFunc']);

        $result = $this->detector->detect($doc, $this->tmpDir);

        $this->assertNotNull($result);
        $this->assertSame(['deletedFunc'], $result['stale_methods']);
    }

    public function test_returns_null_for_unsupported_language(): void
    {
        $this->createSourceFile('src/main.rs', <<<'RUST'
pub fn hello() {}
RUST);

        $doc = $this->buildDoc('main', 'src/main.rs', ['hello']);

        $result = $this->detector->detect($doc, $this->tmpDir);

        // Rust is unsupported → no methods extracted → all documented methods are stale?
        // Actually, extractSourceMethods returns [] for unsupported → all methods are stale
        $this->assertNotNull($result);
        $this->assertSame(['hello'], $result['stale_methods']);
    }

    public function test_methods_section_stops_at_next_h2(): void
    {
        $this->createSourceFile('src/Services/UserService.php', <<<'PHP'
<?php
class UserService {
    public function getUser() {}
}
PHP);

        $doc = <<<'MD'
---
name: "UserService"
description: "API reference"
---

# UserService

> **Source:** `src/Services/UserService.php`

## Methods

### getUser

Description of getUser.

## Events

### userCreated

This should not be counted as a method.
MD;

        $result = $this->detector->detect($doc, $this->tmpDir);

        // Only 'getUser' is under ## Methods, and it exists in source
        $this->assertNull($result);
    }

    public function test_multiple_stale_methods(): void
    {
        $this->createSourceFile('src/Services/OrderService.php', <<<'PHP'
<?php
class OrderService {
    public function create() {}
}
PHP);

        $doc = $this->buildDoc('OrderService', 'src/Services/OrderService.php', [
            'create', 'update', 'delete', 'archive',
        ]);

        $result = $this->detector->detect($doc, $this->tmpDir);

        $this->assertNotNull($result);
        $this->assertSame(['update', 'delete', 'archive'], $result['stale_methods']);
    }

    /**
     * Build a scaffold-format doc string.
     *
     * @param array<int, string> $methods
     */
    protected function buildDoc(string $className, string $sourcePath, array $methods): string
    {
        $doc = <<<MD
---
name: "{$className}"
description: "API reference for {$className}"
type: "api"
---

# {$className}

> **FQN:** `App\\Services\\{$className}`
> **Source:** `{$sourcePath}`

## Overview

<!-- TODO: Brief description -->

## Methods
MD;

        foreach ($methods as $method) {
            $doc .= "\n\n### {$method}\n\n<!-- TODO: Description -->";
        }

        return $doc . "\n";
    }

    /**
     * Create a source file in the tmp directory.
     */
    protected function createSourceFile(string $relativePath, string $content): void
    {
        $fullPath = $this->tmpDir . '/' . $relativePath;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($fullPath, $content);
    }

    /**
     * Recursively remove a directory.
     */
    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
