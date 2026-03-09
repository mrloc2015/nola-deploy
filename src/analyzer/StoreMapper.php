<?php

declare(strict_types=1);

namespace Nola\Deploy\Analyzer;

use Nola\Deploy\Util\ConfigLoader;

class StoreMapper
{
    public function __construct(private ConfigLoader $config)
    {
    }

    /**
     * Build complete store → theme → locale mapping from Magento DB.
     * Respects Magento scope fallback: store → website → default.
     *
     * @return array<string, array{theme: string, locales: string[]}>
     */
    public function buildMapping(): array
    {
        $envConfig = $this->config->getMagentoEnvConfig();
        $dbConfig = $envConfig['db']['connection']['default'] ?? null;
        if (!$dbConfig) {
            throw new \RuntimeException('Cannot read DB config from app/etc/env.php');
        }

        $pdo = $this->connect($dbConfig);
        $prefix = $dbConfig['table_prefix'] ?? '';

        $storeViews = $this->getStoreViews($pdo, $prefix);
        $themeConfig = $this->getScopedConfig($pdo, $prefix, 'design/theme/theme_id');
        $localeConfig = $this->getScopedConfig($pdo, $prefix, 'general/locale/code');
        $themePaths = $this->getThemePaths($pdo, $prefix);

        $stores = [];
        foreach ($storeViews as $sv) {
            $themeId = $this->resolveScope($themeConfig, (int) $sv['store_id'], (int) $sv['website_id']);
            $locale = $this->resolveScope($localeConfig, (int) $sv['store_id'], (int) $sv['website_id']) ?? 'en_US';
            $themePath = ($themeId && isset($themePaths[$themeId])) ? $themePaths[$themeId] : 'Magento/luma';

            $stores[$sv['code']] = [
                'theme' => $themePath,
                'locales' => [$locale],
            ];
        }

        // Admin entry
        $adminLocale = $this->resolveScope($localeConfig, 0, 0) ?? 'en_US';
        $stores['admin'] = [
            'theme' => 'Magento/backend',
            'locales' => [$adminLocale],
        ];

        return $stores;
    }

    private function connect(array $dbConfig): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;port=%s',
            $dbConfig['host'] ?? 'localhost',
            $dbConfig['dbname'] ?? '',
            $dbConfig['port'] ?? '3306',
        );

        $pdo = new \PDO($dsn, $dbConfig['username'] ?? '', $dbConfig['password'] ?? '');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /** @return array<int, array{store_id: string, code: string, website_id: string}> */
    private function getStoreViews(\PDO $pdo, string $prefix): array
    {
        $stmt = $pdo->query("SELECT store_id, code, website_id FROM {$prefix}store WHERE store_id > 0 AND is_active = 1");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get scoped config values organized by scope level.
     * @return array{default: ?string, websites: array<int, string>, stores: array<int, string>}
     */
    private function getScopedConfig(\PDO $pdo, string $prefix, string $path): array
    {
        $stmt = $pdo->prepare("SELECT scope, scope_id, value FROM {$prefix}core_config_data WHERE path = ?");
        $stmt->execute([$path]);

        $result = ['default' => null, 'websites' => [], 'stores' => []];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $scopeId = (int) $row['scope_id'];
            match ($row['scope']) {
                'default' => $result['default'] = $row['value'],
                'websites' => $result['websites'][$scopeId] = $row['value'],
                'stores' => $result['stores'][$scopeId] = $row['value'],
                default => null,
            };
        }

        return $result;
    }

    /** @return array<string, string> theme_id => theme_path */
    private function getThemePaths(\PDO $pdo, string $prefix): array
    {
        $stmt = $pdo->query("SELECT theme_id, theme_path FROM {$prefix}theme");
        $paths = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $paths[$row['theme_id']] = $row['theme_path'];
        }
        return $paths;
    }

    /**
     * Resolve config value using Magento scope fallback:
     * store → website → default
     */
    private function resolveScope(array $config, int $storeId, int $websiteId): ?string
    {
        if (isset($config['stores'][$storeId])) {
            return $config['stores'][$storeId];
        }
        if (isset($config['websites'][$websiteId])) {
            return $config['websites'][$websiteId];
        }
        return $config['default'] ?? null;
    }
}
