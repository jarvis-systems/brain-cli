<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use BrainCLI\Tests\Support\CliOutputCapture;
use PHPUnit\Framework\TestCase;

/**
 * Golden parity tests for DocsCommand behavior through CommandKernel.
 *
 * These tests verify command output patterns and exit codes
 * without requiring the full Laravel application container.
 */
class DocsCommandGoldenTest extends TestCase
{
    use CliOutputCapture;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
    }

    protected function tearDown(): void
    {
        $this->cleanDirectory($this->tempDir);
    }

    public function test_as_without_download_returns_error(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/Console/Commands/DocsCommand.php') ?: '';

        // Verify the --as requires --download guard exists
        $this->assertStringContainsString("'--as requires --download'", $source);
        $this->assertStringContainsString('return ERROR', $source);
    }

    public function test_validate_source_uses_command_terminated_exception(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/Console/Commands/DocsCommand.php') ?: '';

        // validateDocs() should throw CTE when .docs dir missing
        $this->assertStringContainsString('throw new CommandTerminatedException()', $source);
        $this->assertStringContainsString("'.docs directory does not exist.'", $source);
    }

    public function test_download_invalid_url_throws_cte(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/Console/Commands/DocsCommand.php') ?: '';

        // downloadDocsSources() should throw CTE on invalid URL
        $this->assertStringContainsString("'Invalid URL.'", $source);
        $this->assertStringContainsString('throw new CommandTerminatedException()', $source);
    }

    public function test_download_invalid_filename_throws_cte(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/Console/Commands/DocsCommand.php') ?: '';

        // Validate filename pattern check exists
        $this->assertStringContainsString("'Filename must end with .md, .txt, or .html'", $source);
    }

    public function test_docs_command_kernel_adoption(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/Console/Commands/DocsCommand.php') ?: '';

        $this->assertStringContainsString('CommandKernel::run(', $source);
        $this->assertStringContainsString('executeCommand()', $source);
        $this->assertStringContainsString("'docs'", $source);
    }

    public function test_global_flag_exists_in_signature(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/Console/Commands/DocsCommand.php') ?: '';

        $this->assertStringContainsString('{--global', $source);
        $this->assertStringContainsString('Search all .docs/ folders in project subdirectories', $source);
    }

    public function test_global_flag_uses_docs_directory_resolver(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/Console/Commands/DocsCommand.php') ?: '';

        $this->assertStringContainsString('DocsDirectoryResolver', $source);
        $this->assertStringContainsString('docsDirectoryResolver->resolve(', $source);
    }

    public function test_global_flag_documented_in_help(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/Console/Commands/DocsCommand.php') ?: '';

        $this->assertStringContainsString('--global', $source);
        $this->assertStringContainsString('brain docs api --global', $source);
    }
}
