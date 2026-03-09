<?php

declare(strict_types=1);

namespace Nola\Deploy\Analyzer;

use Nola\Deploy\Util\ConfigLoader;

class LocaleDetector
{
    public function __construct(private ConfigLoader $config)
    {
    }

    /** @return string[] */
    public function detect(): array
    {
        // If stores mapping exists in config, derive locales from it
        if ($this->config->hasStoreMapping()) {
            $mapping = $this->config->getStoreMapping();
            $locales = $mapping['locales'];
            if (!in_array('en_US', $locales, true)) {
                array_unshift($locales, 'en_US');
            }
            return array_unique($locales);
        }

        if (!$this->config->isLocaleAutoDetect()) {
            return $this->config->getConfiguredLocales();
        }

        $locales = $this->detectFromDatabase();

        if (empty($locales)) {
            $locales = $this->config->getConfiguredLocales();
        }

        // Always include en_US as fallback
        if (!in_array('en_US', $locales, true)) {
            array_unshift($locales, 'en_US');
        }

        return array_unique($locales);
    }

    /** @return string[] */
    private function detectFromDatabase(): array
    {
        $envConfig = $this->config->getMagentoEnvConfig();
        $dbConfig = $envConfig['db']['connection']['default'] ?? null;

        if (!$dbConfig) {
            return [];
        }

        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;port=%s',
                $dbConfig['host'] ?? 'localhost',
                $dbConfig['dbname'] ?? '',
                $dbConfig['port'] ?? '3306'
            );

            $prefix = $dbConfig['table_prefix'] ?? '';
            $pdo = new \PDO($dsn, $dbConfig['username'] ?? '', $dbConfig['password'] ?? '');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Get locales from store config
            $sql = "
                SELECT DISTINCT value FROM {$prefix}core_config_data
                WHERE path = 'general/locale/code'
                AND value IS NOT NULL AND value != ''
            ";

            $stmt = $pdo->query($sql);
            $locales = [];

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $locales[] = $row['value'];
            }

            // Also check admin locale
            $sql = "
                SELECT DISTINCT value FROM {$prefix}core_config_data
                WHERE path = 'general/locale/code'
                AND scope = 'default'
                AND value IS NOT NULL AND value != ''
            ";
            $stmt = $pdo->query($sql);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $locales[] = $row['value'];
            }

            return array_unique($locales);
        } catch (\PDOException) {
            return [];
        }
    }
}
