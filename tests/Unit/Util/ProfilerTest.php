<?php

declare(strict_types=1);

namespace Nola\Deploy\Tests\Unit\Util;

use Nola\Deploy\Util\Profiler;
use PHPUnit\Framework\TestCase;

class ProfilerTest extends TestCase
{
    public function testStartAndStop(): void
    {
        $profiler = new Profiler();
        $profiler->start('test');
        usleep(10_000); // 10ms
        $duration = $profiler->stop('test');

        $this->assertGreaterThan(0, $duration);
        $this->assertArrayHasKey('test', $profiler->getResults());
    }

    public function testGetTotal(): void
    {
        $profiler = new Profiler();
        $profiler->start('step1');
        usleep(10_000);
        $profiler->stop('step1');

        $profiler->start('step2');
        usleep(10_000);
        $profiler->stop('step2');

        $total = $profiler->getTotal();
        $this->assertGreaterThan(0.01, $total);
        $this->assertCount(2, $profiler->getResults());
    }

    public function testStopUnstartedReturnsZero(): void
    {
        $profiler = new Profiler();
        $this->assertEquals(0.0, $profiler->stop('nonexistent'));
    }

    public function testFormatReport(): void
    {
        $profiler = new Profiler();
        $profiler->start('test');
        $profiler->stop('test');

        $report = $profiler->formatReport();
        $this->assertStringContainsString('Step', $report);
        $this->assertStringContainsString('Duration', $report);
        $this->assertStringContainsString('TOTAL', $report);
    }
}
