<?php

declare(strict_types=1);

namespace Nola\Deploy\Util;

use Symfony\Component\Yaml\Yaml;

class ConfigLoader
{
    private const CONFIG_FILENAME = '.nola-deploy.yaml';
    private array $config = [];
    private ?string $magentoRoot = null;

    public function load(?string $magentoRoot = null): self
    {
        $this->magentoRoot = $magentoRoot ?? $this->detectMagentoRoot();
        $defaults = $this->loadDefaults();
        $userConfig = $this->loadUserConfig();
        $this->config = $this->mergeDeep($defaults, $userConfig);
        $this->config['magento_root'] = $this->magentoRoot;
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function getMagentoRoot(): string
    {
        return $this->magentoRoot ?? throw new \RuntimeException('Config not loaded');
    }

    public function getPhpBinary(): string
    {
        return $this->get('php_binary', 'php');
    }

    public function getMemoryLimit(): string
    {
        return $this->get('memory_limit', '-1');
    }

    public function getParallelJobs(): int
    {
        return (int) $this->get('static_content.parallel_jobs', 4);
    }

    public function getReleasesDir(): string
    {
        $dir = $this->get('deployment.releases_dir', 'releases');
        if (!str_starts_with($dir, '/')) {
            return $this->magentoRoot . '/' . $dir;
        }
        return $dir;
    }

    public function getReleasesKeep(): int
    {
        return (int) $this->get('deployment.releases_keep', 5);
    }

    /** @return string[] */
    public function getSharedDirs(): array
    {
        return $this->get('deployment.shared_dirs', [
            'var/log', 'var/session', 'pub/media',
            'var/import', 'var/export',
        ]);
    }

    /** @return string[] */
    public function getSharedFiles(): array
    {
        return $this->get('deployment.shared_files', ['app/etc/env.php']);
    }

    /** @return string[] */
    public function getHealthCheckUrls(): array
    {
        return $this->get('health_check.urls', ['/']);
    }

    public function isHealthCheckEnabled(): bool
    {
        return (bool) $this->get('health_check.enabled', true);
    }

    public function isAutoRollbackEnabled(): bool
    {
        return (bool) $this->get('deployment.auto_rollback', true);
    }

    /** @return string[] */
    public function getCacheWarmupUrls(): array
    {
        return $this->get('cache_warmup.urls', ['/']);
    }

    public function isCacheWarmupEnabled(): bool
    {
        return (bool) $this->get('cache_warmup.enabled', true);
    }

    public function useNodeLess(): bool
    {
        return (bool) $this->get('static_content.use_node_less', true);
    }

    public function useGoDeployer(): bool
    {
        return (bool) $this->get('static_content.use_go_deployer', true);
    }

    public function getScdStrategy(): string
    {
        return $this->get('static_content.strategy', 'quick');
    }

    public function isDiCacheEnabled(): bool
    {
        return (bool) $this->get('di_compile.cache', true);
    }

    public function isDiGcDisabled(): bool
    {
        return (bool) $this->get('di_compile.gc_disable', true);
    }

    /** @return string[] */
    public function getExcludedThemes(): array
    {
        return $this->get('themes.exclude', []);
    }

    public function isThemeAutoDetect(): bool
    {
        return (bool) $this->get('themes.auto_detect', true);
    }

    public function isLocaleAutoDetect(): bool
    {
        return (bool) $this->get('locales.auto_detect', true);
    }

    /** @return string[] */
    public function getConfiguredLocales(): array
    {
        return $this->get('locales.list', ['en_US']);
    }

    public function getCacheFlushAll(): bool
    {
        return (bool) $this->get('cache.flush_all', true);
    }

    /** @return string[] */
    public function getCacheTypes(): array
    {
        return $this->get('cache.types', []);
    }

    /** @return string[] */
    public function getPostDeployCommands(): array
    {
        return $this->get('post_deploy', []);
    }

    public function getMaintenancePage(): string
    {
        return $this->get('maintenance.page', 'nola-deploy-maintenance.html');
    }

    public function hasStoreMapping(): bool
    {
        return !empty($this->get('stores', []));
    }

    /** @return array{themes: string[], locales: string[]} */
    public function getStoreMapping(): array
    {
        $stores = $this->get('stores', []);
        if (empty($stores)) {
            return ['themes' => [], 'locales' => []];
        }

        $themes = [];
        $locales = [];
        foreach ($stores as $config) {
            if (isset($config['theme'])) {
                $themes[] = $config['theme'];
            }
            if (isset($config['locales'])) {
                $locales = array_merge($locales, (array) $config['locales']);
            }
        }

        return [
            'themes' => array_values(array_unique($themes)),
            'locales' => array_values(array_unique($locales)),
        ];
    }

    public function toArray(): array
    {
        return $this->config;
    }

    /** Check if user config file exists (has been initialized). */
    public function hasUserConfig(): bool
    {
        if ($this->magentoRoot === null) {
            return false;
        }
        return file_exists($this->magentoRoot . '/' . self::CONFIG_FILENAME);
    }

    public static function getConfigFilename(): string
    {
        return self::CONFIG_FILENAME;
    }

    public function getConfigPath(): string
    {
        return $this->magentoRoot . '/' . self::CONFIG_FILENAME;
    }

    public function getMagentoEnvConfig(): array
    {
        $envFile = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envFile)) {
            return [];
        }
        return include $envFile;
    }

    public function getMagentoModuleConfig(): array
    {
        $configFile = $this->magentoRoot . '/app/etc/config.php';
        if (!file_exists($configFile)) {
            return [];
        }
        return include $configFile;
    }

    private function detectMagentoRoot(): string
    {
        $dir = getcwd();
        while ($dir !== '/') {
            if (file_exists($dir . '/bin/magento') && file_exists($dir . '/app/etc/config.php')) {
                return $dir;
            }
            // Check if nola-deploy is inside a subdirectory
            $parentDir = dirname($dir);
            if (file_exists($parentDir . '/bin/magento')) {
                return $parentDir;
            }
            $dir = $parentDir;
        }

        throw new \RuntimeException(
            'Could not detect Magento root. Run from Magento directory or use --magento-root option'
        );
    }

    private function loadDefaults(): array
    {
        $distFile = __DIR__ . '/../../config/nola-deploy.yaml.dist';
        if (file_exists($distFile)) {
            $parsed = Yaml::parseFile($distFile);
            return is_array($parsed) ? $parsed : $this->getHardcodedDefaults();
        }

        return $this->getHardcodedDefaults();
    }

    private function loadUserConfig(): array
    {
        $configFile = $this->magentoRoot . '/' . self::CONFIG_FILENAME;
        if (file_exists($configFile)) {
            $parsed = Yaml::parseFile($configFile);
            return is_array($parsed) ? $parsed : [];
        }

        return [];
    }

    private function getHardcodedDefaults(): array
    {
        return [
            'php_binary' => 'php',
            'memory_limit' => '-1',
            'themes' => [
                'auto_detect' => true,
                'exclude' => [],
            ],
            'locales' => [
                'auto_detect' => true,
                'list' => ['en_US'],
            ],
            'di_compile' => [
                'enabled' => true,
                'cache' => true,
                'gc_disable' => true,
            ],
            'static_content' => [
                'strategy' => 'quick',
                'parallel_jobs' => 4,
                'use_node_less' => true,
                'use_go_deployer' => true,
            ],
            'deployment' => [
                'mode' => 'standard',
                'releases_dir' => 'releases',
                'releases_keep' => 5,
                'shared_dirs' => ['var/log', 'var/session', 'pub/media', 'var/import', 'var/export'],
                'shared_files' => ['app/etc/env.php'],
                'auto_rollback' => true,
            ],
            'health_check' => [
                'enabled' => true,
                'urls' => ['/'],
                'timeout' => 10,
            ],
            'cache' => [
                'flush_all' => true,
                'types' => [],
            ],
            'maintenance' => [
                'page' => 'nola-deploy-maintenance.html',
            ],
            'post_deploy' => [],
            'cache_warmup' => [
                'enabled' => true,
                'urls' => ['/'],
                'concurrency' => 4,
            ],
        ];
    }

    private function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeDeep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
