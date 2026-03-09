<?php

declare(strict_types=1);

namespace Nola\Deploy\Tests\Unit\Util;

use Nola\Deploy\Util\Manifest;
use PHPUnit\Framework\TestCase;

class ManifestTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/nola-deploy-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $manifestFile = $this->tmpDir . '/var/nola-deploy/manifest.json';
        if (file_exists($manifestFile)) {
            unlink($manifestFile);
        }
        @rmdir($this->tmpDir . '/var/nola-deploy');
        @rmdir($this->tmpDir . '/var');
        @rmdir($this->tmpDir);
    }

    public function testNewManifestDoesNotExist(): void
    {
        $manifest = new Manifest($this->tmpDir);
        $manifest->load();
        $this->assertFalse($manifest->exists());
    }

    public function testSaveAndLoad(): void
    {
        $manifest = new Manifest($this->tmpDir);
        $manifest->update(
            gitCommit: 'abc123',
            hashes: ['file.txt' => 'hash123'],
            deployedThemes: ['Magento/backend'],
            deployedLocales: ['en_US'],
            duration: 42.5,
            magentoVersion: '2.4.8',
        );
        $manifest->save();

        // Load fresh
        $loaded = new Manifest($this->tmpDir);
        $loaded->load();

        $this->assertTrue($loaded->exists());
        $this->assertEquals('abc123', $loaded->getLastGitCommit());
        $this->assertEquals(42.5, $loaded->getLastDuration());
        $this->assertEquals(['Magento/backend'], $loaded->getDeployedThemes());
        $this->assertEquals(['en_US'], $loaded->getDeployedLocales());
        $this->assertEquals('hash123', $loaded->getHash('file.txt'));
    }

    public function testComputeFileHash(): void
    {
        $file = $this->tmpDir . '/test.txt';
        file_put_contents($file, 'hello world');

        $hash = Manifest::computeFileHash($file);
        $this->assertNotEmpty($hash);
        $this->assertEquals(64, strlen($hash)); // SHA256 hex length

        unlink($file);
    }

    public function testComputeFileHashMissingFile(): void
    {
        $hash = Manifest::computeFileHash('/nonexistent/file.txt');
        $this->assertEquals('', $hash);
    }
}
