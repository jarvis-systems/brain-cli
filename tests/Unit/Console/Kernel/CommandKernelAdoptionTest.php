<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Kernel;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that CommandKernel is adopted in key command classes.
 */
class CommandKernelAdoptionTest extends TestCase
{
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 4) . '/src/' . $relativePath;
        $this->assertFileExists($path, "Source file not found: {$relativePath}");

        return file_get_contents($path) ?: '';
    }

    public function test_docs_command_uses_command_kernel(): void
    {
        $source = $this->readSource('Console/Commands/DocsCommand.php');

        $this->assertStringContainsString('CommandKernel::run(', $source);
    }

    public function test_command_bridge_abstract_uses_command_kernel(): void
    {
        $source = $this->readSource('Abstracts/CommandBridgeAbstract.php');

        $this->assertStringContainsString('CommandKernel::run(', $source);
    }
}
