<?php

declare(strict_types=1);

namespace Nola\Deploy\Tests\Unit\Deployer;

use Nola\Deploy\Deployer\SymlinkSwitcher;
use PHPUnit\Framework\TestCase;

class SymlinkSwitcherTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/nola-deploy-symlink-test-' . uniqid();
        mkdir($this->tmpDir . '/releases/v1', 0755, true);
        mkdir($this->tmpDir . '/releases/v2', 0755, true);
    }

    protected function tearDown(): void
    {
        $link = $this->tmpDir . '/current';
        if (is_link($link)) {
            unlink($link);
        }
        // Cleanup temp files
        @array_map('unlink', glob($this->tmpDir . '/current.tmp.*') ?: []);
        @rmdir($this->tmpDir . '/releases/v1');
        @rmdir($this->tmpDir . '/releases/v2');
        @rmdir($this->tmpDir . '/releases');
        @rmdir($this->tmpDir);
    }

    public function testSwitchCreatesSymlink(): void
    {
        $switcher = new SymlinkSwitcher();
        $linkPath = $this->tmpDir . '/current';

        $switcher->switch($this->tmpDir . '/releases/v1', $linkPath);

        $this->assertTrue(is_link($linkPath));
        $this->assertEquals($this->tmpDir . '/releases/v1', readlink($linkPath));
    }

    public function testSwitchReplacesExistingSymlink(): void
    {
        $switcher = new SymlinkSwitcher();
        $linkPath = $this->tmpDir . '/current';

        // Create initial symlink
        $switcher->switch($this->tmpDir . '/releases/v1', $linkPath);
        $this->assertEquals($this->tmpDir . '/releases/v1', readlink($linkPath));

        // Switch to v2
        $switcher->switch($this->tmpDir . '/releases/v2', $linkPath);
        $this->assertEquals($this->tmpDir . '/releases/v2', readlink($linkPath));
    }

    public function testSwitchThrowsForMissingTarget(): void
    {
        $switcher = new SymlinkSwitcher();
        $this->expectException(\RuntimeException::class);
        $switcher->switch('/nonexistent/path', $this->tmpDir . '/current');
    }

    public function testRollback(): void
    {
        $switcher = new SymlinkSwitcher();
        $linkPath = $this->tmpDir . '/current';

        $switcher->switch($this->tmpDir . '/releases/v2', $linkPath);
        $switcher->rollback($this->tmpDir . '/releases/v1', $linkPath);

        $this->assertEquals($this->tmpDir . '/releases/v1', readlink($linkPath));
    }

    public function testGetCurrentTarget(): void
    {
        $switcher = new SymlinkSwitcher();
        $linkPath = $this->tmpDir . '/current';

        $this->assertNull($switcher->getCurrentTarget($linkPath));

        $switcher->switch($this->tmpDir . '/releases/v1', $linkPath);
        $this->assertEquals($this->tmpDir . '/releases/v1', $switcher->getCurrentTarget($linkPath));
    }
}
