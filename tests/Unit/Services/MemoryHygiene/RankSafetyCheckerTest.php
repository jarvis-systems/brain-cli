<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\MemoryHygiene;

use BrainCLI\Services\MemoryHygiene\RankSafetyChecker;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RankSafetyChecker::evaluateRankSafety() pure logic.
 *
 * No MCP calls, no mocks — tests rank safety evaluation directly.
 */
class RankSafetyCheckerTest extends TestCase
{
    private RankSafetyChecker $checker;

    protected function setUp(): void
    {
        $reflection = new \ReflectionClass(RankSafetyChecker::class);
        $this->checker = $reflection->newInstanceWithoutConstructor();
    }

    public function test_safe_when_anchor_dominates(): void
    {
        $probe = [
            'id' => 'P01',
            'domain' => 'compile-safety',
            'critical' => true,
        ];

        $top5 = [
            ['rank' => 1, 'id' => 276, 'similarity' => 0.743, 'is_anchor' => true, 'is_canonical' => false],
            ['rank' => 2, 'id' => 228, 'similarity' => 0.462, 'is_anchor' => false, 'is_canonical' => false],
            ['rank' => 3, 'id' => 6, 'similarity' => 0.460, 'is_anchor' => false, 'is_canonical' => false],
        ];

        $result = $this->checker->evaluateRankSafety(
            $probe,
            $top5,
            276,
            [280, 281, 282],
            [276, 277, 278, 279],
        );

        $this->assertSame('SAFE', $result['verdict']);
        $this->assertFalse($result['overlap_risk']);
        $this->assertNull($result['anchor_margin']); // No canonicals in top-5
    }

    public function test_overlap_risk_when_margin_below_threshold(): void
    {
        $probe = [
            'id' => 'P11',
            'domain' => 'pseudo-syntax',
            'critical' => true,
        ];

        $top5 = [
            ['rank' => 1, 'id' => 267, 'similarity' => 0.598, 'is_anchor' => false, 'is_canonical' => false],
            ['rank' => 2, 'id' => 282, 'similarity' => 0.593, 'is_anchor' => false, 'is_canonical' => true],
        ];

        $result = $this->checker->evaluateRankSafety(
            $probe,
            $top5,
            267,
            [280, 281, 282],
            [276, 277, 278, 279],
        );

        $this->assertSame('OVERLAP_RISK', $result['verdict']);
        $this->assertTrue($result['overlap_risk']);
        $this->assertSame(0.005, $result['anchor_margin']);
    }

    public function test_safe_with_empty_top5(): void
    {
        $probe = [
            'id' => 'P01',
            'domain' => 'compile-safety',
            'critical' => true,
        ];

        $result = $this->checker->evaluateRankSafety(
            $probe,
            [],
            276,
            [280, 281, 282],
            [276, 277, 278, 279],
        );

        $this->assertSame('SAFE', $result['verdict']);
        $this->assertFalse($result['overlap_risk']);
        $this->assertNull($result['anchor_margin']);
        $this->assertSame('BASELINE_FAIL', $result['status']);
    }

    public function test_overlap_fail_when_canonical_outranks(): void
    {
        $probe = [
            'id' => 'P11',
            'domain' => 'pseudo-syntax',
            'critical' => true,
        ];

        $top5 = [
            ['rank' => 1, 'id' => 282, 'similarity' => 0.610, 'is_anchor' => false, 'is_canonical' => true],
            ['rank' => 2, 'id' => 267, 'similarity' => 0.598, 'is_anchor' => false, 'is_canonical' => false],
        ];

        $result = $this->checker->evaluateRankSafety(
            $probe,
            $top5,
            267,
            [280, 281, 282],
            [276, 277, 278, 279],
        );

        $this->assertSame('OVERLAP_FAIL', $result['verdict']);
        $this->assertTrue($result['overlap_risk']);
        $this->assertLessThanOrEqual(0, $result['anchor_margin']);
    }
}
