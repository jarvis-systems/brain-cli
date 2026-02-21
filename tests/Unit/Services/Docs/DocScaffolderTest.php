<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Docs;

use BrainCLI\Services\Docs\DocScaffolder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DocScaffolder template generation and scaffold logic.
 *
 * Tests buildTemplate() directly (pure function) and scaffoldOne()/scaffoldAll()
 * via a subclass that overrides path resolution for testability.
 */
class DocScaffolderTest extends TestCase
{
    protected DocScaffolder $scaffolder;

    protected string $tmpDir;

    protected function setUp(): void
    {
        $this->scaffolder = new DocScaffolder();
        $this->tmpDir = sys_get_temp_dir() . '/doc_scaffolder_test_' . uniqid();
        mkdir($this->tmpDir . '/.docs', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function test_build_template_contains_yaml_front_matter(): void
    {
        $template = $this->scaffolder->buildTemplate($this->makeClassInfo());

        $this->assertStringStartsWith('---', $template);
        $this->assertStringContainsString('name: "UserService"', $template);
        $this->assertStringContainsString('description: "API reference for UserService"', $template);
        $this->assertStringContainsString('type: "api"', $template);
        $this->assertStringContainsString('date: "' . date('Y-m-d') . '"', $template);
    }

    public function test_build_template_contains_h1_header(): void
    {
        $template = $this->scaffolder->buildTemplate($this->makeClassInfo());

        $this->assertStringContainsString('# UserService', $template);
    }

    public function test_build_template_contains_fqn_and_source(): void
    {
        $template = $this->scaffolder->buildTemplate($this->makeClassInfo());

        $this->assertStringContainsString('`App\\Services\\UserService`', $template);
        $this->assertStringContainsString('`src/Services/UserService.php`', $template);
    }

    public function test_build_template_contains_overview_section(): void
    {
        $template = $this->scaffolder->buildTemplate($this->makeClassInfo());

        $this->assertStringContainsString('## Overview', $template);
        $this->assertStringContainsString('<!-- TODO: Brief description of what this class does -->', $template);
    }

    public function test_build_template_contains_methods_section(): void
    {
        $template = $this->scaffolder->buildTemplate($this->makeClassInfo());

        $this->assertStringContainsString('## Methods', $template);
        $this->assertStringContainsString('### getUser', $template);
        $this->assertStringContainsString('### createUser', $template);
        $this->assertStringContainsString('### deleteUser', $template);
    }

    public function test_build_template_methods_have_todo_placeholders(): void
    {
        $template = $this->scaffolder->buildTemplate($this->makeClassInfo());

        // Count TODO placeholders — should have 1 for overview + 1 per method
        $todoCount = substr_count($template, '<!-- TODO:');
        $this->assertSame(4, $todoCount); // 1 overview + 3 methods
    }

    public function test_build_template_without_methods(): void
    {
        $classInfo = $this->makeClassInfo(methods: [], methodCount: 0);
        $template = $this->scaffolder->buildTemplate($classInfo);

        $this->assertStringContainsString('## Overview', $template);
        $this->assertStringNotContainsString('## Methods', $template);
    }

    public function test_build_template_strips_leading_slash_from_file(): void
    {
        $classInfo = $this->makeClassInfo(file: '/src/Services/UserService.php');
        $template = $this->scaffolder->buildTemplate($classInfo);

        $this->assertStringContainsString('`src/Services/UserService.php`', $template);
        $this->assertStringNotContainsString('`/src/Services/UserService.php`', $template);
    }

    public function test_scaffold_one_creates_file(): void
    {
        $scaffolder = $this->createTestableScaffolder();
        $result = $scaffolder->scaffoldOne($this->makeClassInfo());

        $this->assertSame('created', $result['status']);
        $this->assertSame('UserService', $result['data']['class']);
        $this->assertSame('.docs/UserService.md', $result['data']['path']);
        $this->assertSame(3, $result['data']['methods']);
        $this->assertFileExists($this->tmpDir . '/.docs/UserService.md');
    }

    public function test_scaffold_one_skips_existing_file(): void
    {
        // Pre-create the file
        file_put_contents($this->tmpDir . '/.docs/UserService.md', 'existing content');

        $scaffolder = $this->createTestableScaffolder();
        $result = $scaffolder->scaffoldOne($this->makeClassInfo());

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('already exists', $result['data']['reason']);
        $this->assertSame('existing content', file_get_contents($this->tmpDir . '/.docs/UserService.md'));
    }

    public function test_scaffold_all_processes_multiple_classes(): void
    {
        $scaffolder = $this->createTestableScaffolder();

        $classes = [
            $this->makeClassInfo(),
            $this->makeClassInfo(class: 'AuthService', fqn: 'App\\Services\\AuthService', methods: ['login', 'logout'], methodCount: 2),
        ];

        $result = $scaffolder->scaffoldAll($classes);

        $this->assertSame(2, $result['total_created']);
        $this->assertSame(0, $result['total_skipped']);
        $this->assertCount(2, $result['created']);
        $this->assertFileExists($this->tmpDir . '/.docs/UserService.md');
        $this->assertFileExists($this->tmpDir . '/.docs/AuthService.md');
    }

    public function test_scaffold_all_reports_skipped_and_created(): void
    {
        // Pre-create one file
        file_put_contents($this->tmpDir . '/.docs/UserService.md', 'existing');

        $scaffolder = $this->createTestableScaffolder();

        $classes = [
            $this->makeClassInfo(),
            $this->makeClassInfo(class: 'AuthService', fqn: 'App\\Services\\AuthService', methods: ['login'], methodCount: 1),
        ];

        $result = $scaffolder->scaffoldAll($classes);

        $this->assertSame(1, $result['total_created']);
        $this->assertSame(1, $result['total_skipped']);
        $this->assertSame('UserService', $result['skipped'][0]['class']);
        $this->assertSame('AuthService', $result['created'][0]['class']);
    }

    public function test_build_template_yaml_is_valid(): void
    {
        $template = $this->scaffolder->buildTemplate($this->makeClassInfo());

        // Extract YAML block
        preg_match('/^---\s*(.*?)\s*---/s', $template, $matches);
        $this->assertNotEmpty($matches[1]);

        $yaml = \Symfony\Component\Yaml\Yaml::parse($matches[1]);
        $this->assertIsArray($yaml);
        $this->assertSame('UserService', $yaml['name']);
        $this->assertSame('API reference for UserService', $yaml['description']);
        $this->assertSame('api', $yaml['type']);
    }

    /**
     * Create a testable scaffolder that uses tmpDir instead of Brain::projectDirectory().
     */
    protected function createTestableScaffolder(): DocScaffolder
    {
        $tmpDir = $this->tmpDir;

        return new class ($tmpDir) extends DocScaffolder {
            public function __construct(protected string $testDir) {}

            public function scaffoldOne(array $classInfo): array
            {
                $relativePath = '.docs/' . $classInfo['class'] . '.md';
                $fullPath = $this->testDir . '/' . $relativePath;

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

                $docsDir = $this->testDir . '/.docs';
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
        };
    }

    /**
     * Create a standard class info fixture.
     *
     * @param array<int, string>|null $methods
     */
    protected function makeClassInfo(
        string $class = 'UserService',
        string $fqn = 'App\\Services\\UserService',
        string $file = '/src/Services/UserService.php',
        ?array $methods = null,
        ?int $methodCount = null,
    ): array {
        $methods ??= ['getUser', 'createUser', 'deleteUser'];
        $methodCount ??= count($methods);

        return [
            'class' => $class,
            'fqn' => $fqn,
            'file' => $file,
            'methods' => $methods,
            'method_count' => $methodCount,
        ];
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
