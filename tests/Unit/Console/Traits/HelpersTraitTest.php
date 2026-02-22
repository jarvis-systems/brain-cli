<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Traits;

use BrainCLI\Console\Commands\MakeIncludeCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for HelpersTrait::extractInnerPathNameName() via Reflection.
 *
 * Uses MakeIncludeCommand (which uses HelpersTrait) to test the method.
 * Covers simple names, nested paths, backslash separators, and edge cases.
 */
class HelpersTraitTest extends TestCase
{
    private object $command;

    private ReflectionMethod $method;

    protected function setUp(): void
    {
        defined('DS') || define('DS', DIRECTORY_SEPARATOR);
        defined('OK') || define('OK', 0);
        defined('ERROR') || define('ERROR', 1);

        $this->command = new MakeIncludeCommand();
        $this->method = new ReflectionMethod($this->command, 'extractInnerPathNameName');
    }

    // ─── Simple (single-level) name ─────────────────────────────────

    #[Test]
    public function simple_name_returns_empty_directory(): void
    {
        [$directory, $className, $namespace] = $this->extract('SimpleInclude');

        $this->assertSame('', $directory);
        $this->assertSame('SimpleInclude', $className);
        $this->assertSame('', $namespace);
    }

    #[Test]
    public function simple_lowercase_name_is_preserved(): void
    {
        [$directory, $className, $namespace] = $this->extract('myinclude');

        $this->assertSame('', $directory);
        $this->assertSame('myinclude', $className);
        $this->assertSame('', $namespace);
    }

    // ─── Nested paths with forward slashes ──────────────────────────

    #[Test]
    public function single_level_nesting_extracts_directory_and_namespace(): void
    {
        [$directory, $className, $namespace] = $this->extract('Brain/SecurityConstraint');

        $this->assertSame('Brain' . DS, $directory);
        $this->assertSame('SecurityConstraint', $className);
        $this->assertSame('\\Brain', $namespace);
    }

    #[Test]
    public function two_level_nesting_builds_correct_path(): void
    {
        [$directory, $className, $namespace] = $this->extract('Brain/Constraints/SecurityConstraint');

        $this->assertSame('Brain' . DS . 'Constraints' . DS, $directory);
        $this->assertSame('SecurityConstraint', $className);
        $this->assertSame('\\Brain\\Constraints', $namespace);
    }

    #[Test]
    public function deep_nesting_builds_correct_path(): void
    {
        [$directory, $className, $namespace] = $this->extract('Deep/Nested/Path/MyInclude');

        $this->assertSame('Deep' . DS . 'Nested' . DS . 'Path' . DS, $directory);
        $this->assertSame('MyInclude', $className);
        $this->assertSame('\\Deep\\Nested\\Path', $namespace);
    }

    // ─── Backslash separators ───────────────────────────────────────

    #[Test]
    public function backslash_separators_work_like_forward_slashes(): void
    {
        [$directory, $className, $namespace] = $this->extract('Brain\\Constraints\\RuleInclude');

        $this->assertSame('Brain' . DS . 'Constraints' . DS, $directory);
        $this->assertSame('RuleInclude', $className);
        $this->assertSame('\\Brain\\Constraints', $namespace);
    }

    // ─── Studly case transformation ─────────────────────────────────

    #[Test]
    public function directory_segments_are_studly_cased(): void
    {
        [$directory, $className, $namespace] = $this->extract('my-group/sub-dir/TestInclude');

        $this->assertSame('MyGroup' . DS . 'SubDir' . DS, $directory);
        $this->assertSame('TestInclude', $className);
        $this->assertSame('\\MyGroup\\SubDir', $namespace);
    }

    // ─── Namespace prefix always starts with backslash ──────────────

    #[Test]
    public function namespace_starts_with_backslash_when_nested(): void
    {
        [, , $namespace] = $this->extract('Foo/Bar');

        $this->assertStringStartsWith('\\', $namespace);
    }

    #[Test]
    public function namespace_is_empty_string_when_no_nesting(): void
    {
        [, , $namespace] = $this->extract('FlatName');

        $this->assertSame('', $namespace);
    }

    // ─── Return type structure ──────────────────────────────────────

    #[Test]
    public function returns_array_with_exactly_three_elements(): void
    {
        $result = $this->extract('Any/Name');

        $this->assertCount(3, $result);
    }

    // ─── Data provider: comprehensive edge cases ────────────────────

    #[Test]
    #[DataProvider('extractionCasesProvider')]
    public function extraction_matches_expected(
        string $input,
        string $expectedDirectory,
        string $expectedClassName,
        string $expectedNamespace,
    ): void {
        [$directory, $className, $namespace] = $this->extract($input);

        $this->assertSame($expectedDirectory, $directory, "Directory mismatch for input: {$input}");
        $this->assertSame($expectedClassName, $className, "ClassName mismatch for input: {$input}");
        $this->assertSame($expectedNamespace, $namespace, "Namespace mismatch for input: {$input}");
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function extractionCasesProvider(): array
    {
        return [
            'simple name' => ['Foo', '', 'Foo', ''],
            'one level' => ['Bar/Baz', 'Bar' . DIRECTORY_SEPARATOR, 'Baz', '\\Bar'],
            'two levels' => [
                'A/B/C',
                'A' . DIRECTORY_SEPARATOR . 'B' . DIRECTORY_SEPARATOR,
                'C',
                '\\A\\B',
            ],
            'backslash input' => [
                'X\\Y\\Z',
                'X' . DIRECTORY_SEPARATOR . 'Y' . DIRECTORY_SEPARATOR,
                'Z',
                '\\X\\Y',
            ],
            'kebab-case dirs' => [
                'my-dir/my-class',
                'MyDir' . DIRECTORY_SEPARATOR,
                'my-class',
                '\\MyDir',
            ],
        ];
    }

    // ─── Source inspection: HelpersTrait contract ────────────────────

    #[Test]
    public function helpers_trait_source_has_check_working_dir(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Traits/HelpersTrait.php'
        ) ?: '';

        $this->assertStringContainsString('public function checkWorkingDir(): void', $source);
    }

    #[Test]
    public function helpers_trait_check_working_dir_uses_brain_facade(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Traits/HelpersTrait.php'
        ) ?: '';

        $this->assertStringContainsString('Brain::workingDirectory()', $source);
        $this->assertStringContainsString("Brain::workingDirectory(['node', 'Brain.php'])", $source);
    }

    #[Test]
    public function helpers_trait_check_working_dir_throws_cte_on_missing_dir(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Traits/HelpersTrait.php'
        ) ?: '';

        $this->assertStringContainsString('throw new CommandTerminatedException()', $source);
        $this->assertStringContainsString('brain working directory does not exist', $source);
    }

    #[Test]
    public function helpers_trait_check_working_dir_throws_cte_on_missing_brain_php(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Traits/HelpersTrait.php'
        ) ?: '';

        $this->assertStringContainsString('Brain.php file does not exist', $source);
    }

    #[Test]
    public function helpers_trait_uses_compile_lock_for_auto_switch(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Traits/HelpersTrait.php'
        ) ?: '';

        $this->assertStringContainsString('CompileLock::findProjectRoot(', $source);
        $this->assertStringContainsString('Auto-switched to project root', $source);
    }

    #[Test]
    public function helpers_trait_uses_colors_trait(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Traits/HelpersTrait.php'
        ) ?: '';

        $this->assertStringContainsString('use Colors;', $source);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Call extractInnerPathNameName() via reflection.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function extract(string $name): array
    {
        /** @var array{0: string, 1: string, 2: string} */
        return $this->method->invoke($this->command, $name);
    }
}
