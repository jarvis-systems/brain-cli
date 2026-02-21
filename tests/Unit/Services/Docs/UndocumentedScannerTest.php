<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Docs;

use BrainCLI\Services\Docs\UndocumentedScanner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for UndocumentedScanner configuration and extraction logic.
 *
 * These tests validate the scanner's language configs, patterns, and extraction
 * methods via reflection. Integration tests with actual filesystem require
 * the Brain facade and are covered in feature tests.
 */
class UndocumentedScannerTest extends TestCase
{
    protected UndocumentedScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new UndocumentedScanner();
    }

    public function test_language_configs_cover_php(): void
    {
        $configs = $this->getLanguageConfigs();

        $this->assertArrayHasKey('php', $configs);
        $this->assertContains('.php', $configs['php']['extensions']);
    }

    public function test_language_configs_cover_javascript(): void
    {
        $configs = $this->getLanguageConfigs();

        $this->assertArrayHasKey('javascript', $configs);
        $this->assertContains('.js', $configs['javascript']['extensions']);
        $this->assertContains('.jsx', $configs['javascript']['extensions']);
    }

    public function test_language_configs_cover_typescript(): void
    {
        $configs = $this->getLanguageConfigs();

        $this->assertArrayHasKey('typescript', $configs);
        $this->assertContains('.ts', $configs['typescript']['extensions']);
        $this->assertContains('.tsx', $configs['typescript']['extensions']);
    }

    public function test_language_configs_cover_python(): void
    {
        $configs = $this->getLanguageConfigs();

        $this->assertArrayHasKey('python', $configs);
        $this->assertContains('.py', $configs['python']['extensions']);
    }

    public function test_language_configs_cover_go(): void
    {
        $configs = $this->getLanguageConfigs();

        $this->assertArrayHasKey('go', $configs);
        $this->assertContains('.go', $configs['go']['extensions']);
    }

    public function test_php_class_pattern_matches(): void
    {
        $configs = $this->getLanguageConfigs();
        $pattern = $configs['php']['class_pattern'];

        // Standard class
        $this->assertMatchesRegex($pattern, 'class UserService');
        // Abstract class
        $this->assertMatchesRegex($pattern, 'abstract class BaseRepository');
    }

    public function test_php_function_pattern_matches(): void
    {
        $configs = $this->getLanguageConfigs();
        $pattern = $configs['php']['function_pattern'];

        $this->assertMatchesRegex($pattern, 'public function handle(');
        $this->assertMatchesRegex($pattern, 'public function getUser(');
    }

    public function test_javascript_class_pattern_matches(): void
    {
        $configs = $this->getLanguageConfigs();
        $pattern = $configs['javascript']['class_pattern'];

        $this->assertMatchesRegex($pattern, 'class UserService');
        $this->assertMatchesRegex($pattern, 'export class AuthHandler');
        $this->assertMatchesRegex($pattern, 'export default class App');
    }

    public function test_javascript_function_pattern_matches(): void
    {
        $configs = $this->getLanguageConfigs();
        $pattern = $configs['javascript']['function_pattern'];

        $this->assertMatchesRegex($pattern, 'export function handleAuth(');
        $this->assertMatchesRegex($pattern, 'export async function fetchData(');
        $this->assertMatchesRegex($pattern, 'function helper(');
    }

    public function test_python_class_pattern_matches(): void
    {
        $configs = $this->getLanguageConfigs();
        $pattern = $configs['python']['class_pattern'];

        $this->assertMatchesRegex($pattern, 'class UserService:');
        $this->assertMatchesRegex($pattern, 'class BaseModel(Model):');
    }

    public function test_python_function_pattern_matches(): void
    {
        $configs = $this->getLanguageConfigs();
        $pattern = $configs['python']['function_pattern'];

        $this->assertMatchesRegex($pattern, 'def handle(');
        $this->assertMatchesRegex($pattern, 'def get_user(');
    }

    public function test_go_class_pattern_matches(): void
    {
        $configs = $this->getLanguageConfigs();
        $pattern = $configs['go']['class_pattern'];

        $this->assertMatchesRegex($pattern, 'type UserService struct {');
        $this->assertMatchesRegex($pattern, 'type Config struct {');
    }

    public function test_go_function_pattern_matches(): void
    {
        $configs = $this->getLanguageConfigs();
        $pattern = $configs['go']['function_pattern'];

        $this->assertMatchesRegex($pattern, 'func HandleRequest(');
        $this->assertMatchesRegex($pattern, 'func (s *Server) Start(');
    }

    public function test_exclude_patterns_contain_vendor(): void
    {
        $excludes = $this->getExcludePatterns();

        $this->assertContains('/vendor/', $excludes);
        $this->assertContains('/node_modules/', $excludes);
        $this->assertContains('/.git/', $excludes);
    }

    public function test_exclude_patterns_contain_build_dirs(): void
    {
        $excludes = $this->getExcludePatterns();

        $this->assertContains('/dist/', $excludes);
        $this->assertContains('/build/', $excludes);
        $this->assertContains('/__pycache__/', $excludes);
    }

    public function test_is_markdown_file_accepts_md(): void
    {
        $method = $this->getProtectedMethod('isMarkdownFile');
        $this->assertTrue($method->invoke($this->scanner, '/path/to/file.md'));
    }

    public function test_is_markdown_file_accepts_mdx(): void
    {
        $method = $this->getProtectedMethod('isMarkdownFile');
        $this->assertTrue($method->invoke($this->scanner, '/path/to/file.mdx'));
    }

    public function test_is_markdown_file_rejects_other_extensions(): void
    {
        $method = $this->getProtectedMethod('isMarkdownFile');
        $this->assertFalse($method->invoke($this->scanner, '/path/to/file.txt'));
        $this->assertFalse($method->invoke($this->scanner, '/path/to/file.php'));
        $this->assertFalse($method->invoke($this->scanner, '/path/to/file.html'));
    }

    /**
     * Helper: get protected method via reflection.
     */
    protected function getProtectedMethod(string $name): ReflectionMethod
    {
        $method = new ReflectionMethod(UndocumentedScanner::class, $name);
        $method->setAccessible(true);
        return $method;
    }

    /**
     * Helper: get LANGUAGE_CONFIGS constant via reflection.
     */
    protected function getLanguageConfigs(): array
    {
        $ref = new ReflectionClass(UndocumentedScanner::class);
        return $ref->getConstant('LANGUAGE_CONFIGS');
    }

    /**
     * Helper: get EXCLUDE_PATTERNS constant via reflection.
     */
    protected function getExcludePatterns(): array
    {
        $ref = new ReflectionClass(UndocumentedScanner::class);
        return $ref->getConstant('EXCLUDE_PATTERNS');
    }

    /**
     * Assert that a regex pattern matches a string.
     */
    protected function assertMatchesRegex(string $pattern, string $subject): void
    {
        $this->assertMatchesRegularExpression($pattern, $subject);
    }
}
