<?php

declare(strict_types=1);

namespace Nola\Deploy\Tests\Unit\Util;

use Nola\Deploy\Util\ConfigLoader;
use PHPUnit\Framework\TestCase;

class ConfigLoaderTest extends TestCase
{
    /**
     * Detect Magento root by walking up from nola-deploy directory.
     * Returns null if not found (tests will be skipped).
     */
    private function findMagentoRoot(): ?string
    {
        // nola-deploy lives inside or adjacent to a Magento installation
        $dir = dirname(__DIR__, 3); // nola-deploy root
        $candidates = [$dir, dirname($dir)];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate . '/bin/magento') && file_exists($candidate . '/app/etc/config.php')) {
                return $candidate;
            }
        }

        return null;
    }

    private function loadConfigOrSkip(): ConfigLoader
    {
        $root = $this->findMagentoRoot();
        if ($root === null) {
            $this->markTestSkipped('Magento root not available');
        }
        return (new ConfigLoader())->load($root);
    }

    public function testLoadWithMagentoRoot(): void
    {
        $config = $this->loadConfigOrSkip();

        $this->assertNotEmpty($config->getMagentoRoot());
        $this->assertEquals('php', $config->getPhpBinary());
        $this->assertEquals('-1', $config->getMemoryLimit());
    }

    public function testDefaultValues(): void
    {
        $config = $this->loadConfigOrSkip();

        $this->assertEquals('quick', $config->getScdStrategy());
        $this->assertEquals(4, $config->getParallelJobs());
        $this->assertTrue($config->useNodeLess());
        $this->assertTrue($config->useGoDeployer());
        $this->assertTrue($config->isDiCacheEnabled());
        $this->assertTrue($config->isDiGcDisabled());
        $this->assertTrue($config->isAutoRollbackEnabled());
        $this->assertTrue($config->isHealthCheckEnabled());
        $this->assertTrue($config->isThemeAutoDetect());
        $this->assertTrue($config->isLocaleAutoDetect());
        $this->assertEquals(5, $config->getReleasesKeep());
    }

    public function testDotNotationGet(): void
    {
        $config = $this->loadConfigOrSkip();

        $this->assertEquals('quick', $config->get('static_content.strategy'));
        $this->assertEquals(4, $config->get('static_content.parallel_jobs'));
        $this->assertEquals('default_val', $config->get('nonexistent.key', 'default_val'));
    }

    public function testExcludedThemes(): void
    {
        $config = $this->loadConfigOrSkip();
        $excluded = $config->getExcludedThemes();

        // Default exclude list is now empty — active themes are protected by ThemeDetector
        $this->assertIsArray($excluded);
        $this->assertEmpty($excluded);
    }

    public function testCacheConfig(): void
    {
        $config = $this->loadConfigOrSkip();

        $this->assertTrue($config->getCacheFlushAll());
        $this->assertIsArray($config->getCacheTypes());
        $this->assertEmpty($config->getCacheTypes());
    }

    public function testMaintenanceConfig(): void
    {
        $config = $this->loadConfigOrSkip();

        $this->assertEquals('nola-deploy-maintenance.html', $config->getMaintenancePage());
    }

    public function testPostDeployConfig(): void
    {
        $config = $this->loadConfigOrSkip();

        $this->assertIsArray($config->getPostDeployCommands());
        $this->assertEmpty($config->getPostDeployCommands());
    }

    public function testStoreMapping(): void
    {
        $config = $this->loadConfigOrSkip();

        $mapping = $config->getStoreMapping();
        $this->assertArrayHasKey('themes', $mapping);
        $this->assertArrayHasKey('locales', $mapping);

        // If .nola-deploy.yaml exists with stores, mapping should have data
        if ($config->hasStoreMapping()) {
            $this->assertNotEmpty($mapping['themes']);
            $this->assertNotEmpty($mapping['locales']);
        } else {
            $this->assertEmpty($mapping['themes']);
            $this->assertEmpty($mapping['locales']);
        }
    }

    public function testGetMagentoEnvConfig(): void
    {
        $root = $this->findMagentoRoot();
        if ($root === null || !file_exists($root . '/app/etc/env.php')) {
            $this->markTestSkipped('env.php not available');
        }

        $config = (new ConfigLoader())->load($root);
        $envConfig = $config->getMagentoEnvConfig();

        $this->assertIsArray($envConfig);
        $this->assertArrayHasKey('db', $envConfig);
    }

    public function testGetMagentoModuleConfig(): void
    {
        $root = $this->findMagentoRoot();
        if ($root === null || !file_exists($root . '/app/etc/config.php')) {
            $this->markTestSkipped('config.php not available');
        }

        $config = (new ConfigLoader())->load($root);
        $moduleConfig = $config->getMagentoModuleConfig();

        $this->assertIsArray($moduleConfig);
        $this->assertArrayHasKey('modules', $moduleConfig);
    }
}
