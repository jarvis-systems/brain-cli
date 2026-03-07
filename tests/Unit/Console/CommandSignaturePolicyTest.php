<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

/**
 * Tests for CLI command signature policy.
 *
 * Policy:
 *   1. All commands MUST be namespaced (contain ":") UNLESS explicitly allowlisted
 *   2. Banned signatures: migrate, install, run (too generic)
 *   3. Storage paths MUST use lowercase "memory/" not "Memory/"
 */
class CommandSignaturePolicyTest extends TestCase
{
    private string $cliSrcDir;

    private array $allowlist = [
        'init',
        'status',
        'compile',
        'docs',
        'diagnose',
        'add',
        'detail',
        'list',
        'update',
        'script',
        'board',
    ];

    private array $banned = [
        'migrate',
        'install',
        'run',
    ];

    protected function setUp(): void
    {
        $this->cliSrcDir = dirname(__DIR__, 3) . '/src/Console';
    }

    public function test_all_commands_are_namespaced_or_allowlisted(): void
    {
        $signatures = $this->extractSignatures();

        foreach ($signatures as $file => $sig) {
            $bareName = $this->getBareName($sig);

            // Check if banned
            $this->assertNotContains(
                $bareName,
                $this->banned,
                "Banned signature '$bareName' in $file - must be namespaced (e.g., 'mcp:$bareName')"
            );

            // Check if namespaced or allowlisted
            $isNamespaced = str_contains($sig, ':');
            $isAllowed = in_array($bareName, $this->allowlist, true);

            $this->assertTrue(
                $isNamespaced || $isAllowed,
                "Non-namespaced command '$bareName' in $file - add to allowlist or namespace it"
            );
        }

        $this->assertGreaterThan(0, count($signatures), 'Should find at least one command signature');
    }

    public function test_no_banned_bare_signatures(): void
    {
        $signatures = $this->extractSignatures();

        foreach ($signatures as $file => $sig) {
            $bareName = $this->getBareName($sig);

            $this->assertNotContains(
                $bareName,
                $this->banned,
                "Banned bare signature '$bareName' found in $file"
            );
        }
    }

    public function test_no_uppercase_memory_paths(): void
    {
        $phpFiles = glob($this->cliSrcDir . '/**/*.php') ?: [];
        $violations = [];

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file) ?: '';
            if (preg_match_all("/['\"]Memory\\//", $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $violations[] = basename($file) . ":$line";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Uppercase 'Memory/' paths found - use lowercase 'memory/': " . implode(', ', $violations)
        );
    }

    public function test_allowlist_does_not_overlap_banned(): void
    {
        $overlap = array_intersect($this->allowlist, $this->banned);
        $this->assertEmpty(
            $overlap,
            'Allowlist and banned lists must not overlap: ' . implode(', ', $overlap)
        );
    }

    private function extractSignatures(): array
    {
        $signatures = [];
        $phpFiles = glob($this->cliSrcDir . '/**/*.php') ?: [];
        $phpFiles = array_merge($phpFiles, glob($this->cliSrcDir . '/**/**/*.php') ?: []);

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file) ?: '';
            if (preg_match('/\$signature\s*=\s*[\'"]([^\'"}\s]+)/', $content, $matches)) {
                $signatures[basename($file)] = $matches[1];
            }
        }

        return $signatures;
    }

    private function getBareName(string $signature): string
    {
        $withoutArgs = explode('{', $signature)[0];
        $withoutNamespace = explode(':', $withoutArgs)[0];
        return trim(explode(' ', $withoutNamespace)[0]);
    }
}
