<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Docs;

use BrainCLI\Services\Docs\FreshnessResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class FreshnessResolverTest extends TestCase
{
    protected FreshnessResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FreshnessResolver();
    }

    public function test_bucket_fresh_within_7_days(): void
    {
        $method = new ReflectionMethod(FreshnessResolver::class, 'computeBucket');

        $this->assertSame('fresh', $method->invoke($this->resolver, 0));
        $this->assertSame('fresh', $method->invoke($this->resolver, 3));
        $this->assertSame('fresh', $method->invoke($this->resolver, 6));
    }

    public function test_bucket_recent_within_30_days(): void
    {
        $method = new ReflectionMethod(FreshnessResolver::class, 'computeBucket');

        $this->assertSame('recent', $method->invoke($this->resolver, 8));
        $this->assertSame('recent', $method->invoke($this->resolver, 15));
        $this->assertSame('recent', $method->invoke($this->resolver, 30));
    }

    public function test_bucket_aging_within_90_days(): void
    {
        $method = new ReflectionMethod(FreshnessResolver::class, 'computeBucket');

        $this->assertSame('aging', $method->invoke($this->resolver, 31));
        $this->assertSame('aging', $method->invoke($this->resolver, 60));
        $this->assertSame('aging', $method->invoke($this->resolver, 90));
    }

    public function test_bucket_stale_over_90_days(): void
    {
        $method = new ReflectionMethod(FreshnessResolver::class, 'computeBucket');

        $this->assertSame('stale', $method->invoke($this->resolver, 91));
        $this->assertSame('stale', $method->invoke($this->resolver, 365));
    }

    public function test_bucket_boundary_exact_7_is_fresh(): void
    {
        $method = new ReflectionMethod(FreshnessResolver::class, 'computeBucket');

        $this->assertSame('fresh', $method->invoke($this->resolver, 7));
    }

    public function test_bucket_boundary_exact_31_is_aging(): void
    {
        $method = new ReflectionMethod(FreshnessResolver::class, 'computeBucket');

        $this->assertSame('aging', $method->invoke($this->resolver, 31));
    }

    public function test_resolve_uses_filemtime_fallback(): void
    {
        $tempDir = sys_get_temp_dir() . '/freshness-test-' . uniqid();
        mkdir($tempDir, 0755, true);

        $filePath = $tempDir . '/test.md';
        file_put_contents($filePath, '# Test');

        $now = time();
        touch($filePath, $now - (3 * 86400));
        $this->resolver->setNow($now);

        $result = $this->resolver->resolve($filePath, $tempDir);

        $this->assertSame(3, $result['days_ago']);
        $this->assertSame('fresh', $result['bucket']);

        unlink($filePath);
        rmdir($tempDir);
    }

    public function test_resolve_returns_iso8601_timestamp(): void
    {
        $tempDir = sys_get_temp_dir() . '/freshness-test-' . uniqid();
        mkdir($tempDir, 0755, true);

        $filePath = $tempDir . '/test.md';
        file_put_contents($filePath, '# Test');

        $fixedTime = 1708700000;
        touch($filePath, $fixedTime);
        $this->resolver->setNow($fixedTime);

        $result = $this->resolver->resolve($filePath, $tempDir);

        $this->assertNotNull($result['modified_at']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $result['modified_at'],
        );

        unlink($filePath);
        rmdir($tempDir);
    }

    public function test_resolve_days_ago_is_non_negative(): void
    {
        $tempDir = sys_get_temp_dir() . '/freshness-test-' . uniqid();
        mkdir($tempDir, 0755, true);

        $filePath = $tempDir . '/test.md';
        file_put_contents($filePath, '# Test');

        $now = time();
        touch($filePath, $now + 86400);
        $this->resolver->setNow($now);

        $result = $this->resolver->resolve($filePath, $tempDir);

        $this->assertGreaterThanOrEqual(0, $result['days_ago']);

        unlink($filePath);
        rmdir($tempDir);
    }

    public function test_resolve_deterministic_with_set_now(): void
    {
        $tempDir = sys_get_temp_dir() . '/freshness-test-' . uniqid();
        mkdir($tempDir, 0755, true);

        $filePath = $tempDir . '/test.md';
        file_put_contents($filePath, '# Test');

        $fixedNow = 1708700000;
        $fileTime = $fixedNow - (10 * 86400);
        touch($filePath, $fileTime);

        $this->resolver->setNow($fixedNow);

        $result1 = $this->resolver->resolve($filePath, $tempDir);
        $result2 = $this->resolver->resolve($filePath, $tempDir);

        $this->assertSame($result1, $result2);

        unlink($filePath);
        rmdir($tempDir);
    }
}
