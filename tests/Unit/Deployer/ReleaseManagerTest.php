<?php

declare(strict_types=1);

namespace Nola\Deploy\Tests\Unit\Deployer;

use Nola\Deploy\Deployer\ReleaseManager;
use PHPUnit\Framework\TestCase;

class ReleaseManagerTest extends TestCase
{
    private string $tmpDir;
    private string $releasesDir;
    private string $currentLink;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/nola-deploy-release-test-' . uniqid();
        $this->releasesDir = $this->tmpDir . '/releases';
        $this->currentLink = $this->tmpDir . '/current';
        mkdir($this->releasesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_link($this->currentLink)) {
            unlink($this->currentLink);
        }
        // Cleanup release dirs
        foreach (glob($this->releasesDir . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->releasesDir);
        @rmdir($this->tmpDir);
    }

    public function testEmptyReleasesDir(): void
    {
        $rm = new ReleaseManager($this->releasesDir, $this->currentLink);
        $this->assertEmpty($rm->getReleaseDirs());
        $this->assertNull($rm->getCurrentRelease());
        $this->assertEquals(0, $rm->getReleaseCount());
    }

    public function testGetReleaseDirsSorted(): void
    {
        mkdir($this->releasesDir . '/20260308-100000');
        mkdir($this->releasesDir . '/20260309-150000');
        mkdir($this->releasesDir . '/20260307-090000');

        $rm = new ReleaseManager($this->releasesDir, $this->currentLink);
        $dirs = $rm->getReleaseDirs();

        $this->assertCount(3, $dirs);
        // Newest first
        $this->assertStringContainsString('20260309', $dirs[0]);
        $this->assertStringContainsString('20260308', $dirs[1]);
        $this->assertStringContainsString('20260307', $dirs[2]);
    }

    public function testGetCurrentRelease(): void
    {
        $releaseDir = $this->releasesDir . '/20260309-150000';
        mkdir($releaseDir);
        symlink($releaseDir, $this->currentLink);

        $rm = new ReleaseManager($this->releasesDir, $this->currentLink);
        $this->assertStringContainsString('20260309-150000', $rm->getCurrentRelease());
    }

    public function testGetPreviousRelease(): void
    {
        $r1 = $this->releasesDir . '/20260308-100000';
        $r2 = $this->releasesDir . '/20260309-150000';
        mkdir($r1);
        mkdir($r2);
        symlink($r2, $this->currentLink);

        $rm = new ReleaseManager($this->releasesDir, $this->currentLink);
        $previous = $rm->getPreviousRelease();
        $this->assertStringContainsString('20260308', $previous);
    }

    public function testCleanupKeepsSpecifiedCount(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            mkdir($this->releasesDir . "/2026030{$i}-100000");
        }

        // Current is the newest
        $current = $this->releasesDir . '/20260305-100000';
        symlink($current, $this->currentLink);

        $rm = new ReleaseManager($this->releasesDir, $this->currentLink);
        $removed = $rm->cleanup(3);

        $this->assertCount(2, $removed);
        $this->assertEquals(3, $rm->getReleaseCount());
    }
}
