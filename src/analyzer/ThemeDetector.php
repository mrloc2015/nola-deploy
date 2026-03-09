<?php

declare(strict_types=1);

namespace Nola\Deploy\Analyzer;

use Nola\Deploy\Util\ConfigLoader;

class ThemeDetector
{
    /** @var array<string, ThemeInfo> */
    private array $themes = [];

    public function __construct(private ConfigLoader $config)
    {
    }

    /** @return ThemeInfo[] */
    public function detect(): array
    {
        $this->themes = [];

        // If stores mapping exists in config, derive themes from it
        if ($this->config->hasStoreMapping()) {
            $mapping = $this->config->getStoreMapping();
            foreach ($mapping['themes'] as $themeCode) {
                $this->themes[$themeCode] = $this->resolveThemeInfo($themeCode);
            }
        } else {
            // Auto-detection: DB first, fallback to filesystem
            $dbThemes = $this->detectFromDatabase();
            if (!empty($dbThemes)) {
                $this->themes = $dbThemes;
            } else {
                $this->themes = $this->detectFromFilesystem();
            }

            // Filter excluded themes, but protect active store themes
            if ($this->config->isThemeAutoDetect()) {
                $excluded = $this->config->getExcludedThemes();
                $activeThemes = $this->getActiveStoreThemes();
                $this->themes = array_filter(
                    $this->themes,
                    fn(ThemeInfo $t) => !in_array($t->code, $excluded, true)
                        || in_array($t->code, $activeThemes, true)
                );
            }
        }

        // Always include adminhtml/Magento/backend
        $hasBackend = false;
        foreach ($this->themes as $theme) {
            if ($theme->code === 'Magento/backend') {
                $hasBackend = true;
                break;
            }
        }
        if (!$hasBackend) {
            $this->themes['Magento/backend'] = new ThemeInfo(
                code: 'Magento/backend',
                area: 'adminhtml',
                isHyva: false,
                parentTheme: null
            );
        }

        return array_values($this->themes);
    }

    /**
     * Build ThemeInfo for a known theme code (from stores config).
     * Tries DB lookup, then filesystem, then creates basic info.
     */
    private function resolveThemeInfo(string $code): ThemeInfo
    {
        // Check DB for full info
        $dbThemes = $this->detectFromDatabase();
        if (isset($dbThemes[$code])) {
            return $dbThemes[$code];
        }

        // Determine area from code
        $area = ($code === 'Magento/backend') ? 'adminhtml' : 'frontend';

        // Check filesystem for parent/Hyva info
        $root = $this->config->getMagentoRoot();
        $themePath = $root . '/app/design/' . $area . '/' . $code;
        $parent = null;
        $isHyva = false;

        if (is_dir($themePath)) {
            $parent = $this->readThemeParent($themePath);
            $isHyva = file_exists($themePath . '/tailwind.config.js')
                || file_exists($themePath . '/tailwind.config.cjs');
        }

        return new ThemeInfo(code: $code, area: $area, isHyva: $isHyva, parentTheme: $parent);
    }

    /** @return ThemeInfo[] */
    public function detectAll(): array
    {
        $dbThemes = $this->detectFromDatabase();
        if (!empty($dbThemes)) {
            return array_values($dbThemes);
        }
        return array_values($this->detectFromFilesystem());
    }

    /**
     * Get themes actively assigned to store views via core_config_data.
     * These must NEVER be excluded, even if in the exclude list.
     *
     * @return string[] Theme codes (e.g., ['Magento/luma', 'Magento/backend'])
     */
    private function getActiveStoreThemes(): array
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

            // Find theme IDs assigned to stores via design/theme/theme_id
            $sql = "
                SELECT DISTINCT t.theme_path
                FROM {$prefix}core_config_data c
                JOIN {$prefix}theme t ON t.theme_id = c.value
                WHERE c.path = 'design/theme/theme_id'
            ";
            $stmt = $pdo->query($sql);
            $active = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $active[] = $row['theme_path'];
                // Also include parent themes in the chain (they're needed for inheritance)
                $this->addParentChainToActive($row['theme_path'], $pdo, $prefix, $active);
            }

            // Always include Magento/backend (admin always needs it)
            $active[] = 'Magento/backend';

            return array_unique($active);
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Walk the parent theme chain and add all parents to the active list.
     * Parent themes are needed for SCD to work correctly (theme inheritance).
     */
    private function addParentChainToActive(string $themePath, \PDO $pdo, string $prefix, array &$active): void
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT pt.theme_path as parent_path
                 FROM {$prefix}theme t
                 LEFT JOIN {$prefix}theme pt ON t.parent_id = pt.theme_id
                 WHERE t.theme_path = ?"
            );
            $stmt->execute([$themePath]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['parent_path'])) {
                $active[] = $row['parent_path'];
                $this->addParentChainToActive($row['parent_path'], $pdo, $prefix, $active);
            }
        } catch (\PDOException) {
            // ignore
        }
    }

    /** @return array<string, ThemeInfo> */
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

            // Get active themes from store config + theme table
            $sql = "
                SELECT DISTINCT t.theme_path, t.area, t.type,
                       pt.theme_path as parent_path
                FROM {$prefix}theme t
                LEFT JOIN {$prefix}theme pt ON t.parent_id = pt.theme_id
                WHERE t.area IN ('frontend', 'adminhtml')
            ";

            $stmt = $pdo->query($sql);
            $themes = [];

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $code = $row['theme_path'];
                $isHyva = $this->isHyvaTheme($code, $row['parent_path'] ?? '', $pdo, $prefix);
                $themes[$code] = new ThemeInfo(
                    code: $code,
                    area: $row['area'],
                    isHyva: $isHyva,
                    parentTheme: $row['parent_path']
                );
            }

            return $themes;
        } catch (\PDOException) {
            return [];
        }
    }

    private function isHyvaTheme(string $code, string $parentPath, \PDO $pdo, string $prefix): bool
    {
        // Check theme code or parent for Hyva indicators
        if (str_contains(strtolower($code), 'hyva') || str_contains(strtolower($parentPath), 'hyva')) {
            return true;
        }

        // Check if Hyva_Theme module is enabled
        $moduleConfig = $this->config->getMagentoModuleConfig();
        $modules = $moduleConfig['modules'] ?? [];
        if (isset($modules['Hyva_Theme']) && $modules['Hyva_Theme'] === 1) {
            // Walk the parent chain to see if any parent is a Hyva theme
            return $this->parentChainContainsHyva($parentPath, $pdo, $prefix);
        }

        // Check for tailwind.config.js in theme directory
        $root = $this->config->getMagentoRoot();
        $themePath = $root . '/app/design/frontend/' . $code;
        if (file_exists($themePath . '/tailwind.config.js') || file_exists($themePath . '/tailwind.config.cjs')) {
            return true;
        }

        return false;
    }

    private function parentChainContainsHyva(string $parentPath, \PDO $pdo, string $prefix): bool
    {
        if (empty($parentPath)) {
            return false;
        }
        if (str_contains(strtolower($parentPath), 'hyva')) {
            return true;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT pt.theme_path as parent_path FROM {$prefix}theme t
                 LEFT JOIN {$prefix}theme pt ON t.parent_id = pt.theme_id
                 WHERE t.theme_path = ?"
            );
            $stmt->execute([$parentPath]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && $row['parent_path']) {
                return $this->parentChainContainsHyva($row['parent_path'], $pdo, $prefix);
            }
        } catch (\PDOException) {
            // ignore
        }

        return false;
    }

    /** @return array<string, ThemeInfo> */
    private function detectFromFilesystem(): array
    {
        $root = $this->config->getMagentoRoot();
        $themes = [];

        foreach (['frontend', 'adminhtml'] as $area) {
            $designDir = $root . "/app/design/{$area}";
            if (!is_dir($designDir)) {
                continue;
            }

            foreach (new \DirectoryIterator($designDir) as $vendor) {
                if ($vendor->isDot() || !$vendor->isDir()) {
                    continue;
                }
                foreach (new \DirectoryIterator($vendor->getPathname()) as $theme) {
                    if ($theme->isDot() || !$theme->isDir()) {
                        continue;
                    }
                    $code = $vendor->getFilename() . '/' . $theme->getFilename();
                    $isHyva = file_exists($theme->getPathname() . '/tailwind.config.js')
                        || file_exists($theme->getPathname() . '/tailwind.config.cjs');

                    // Read theme.xml for parent
                    $parent = $this->readThemeParent($theme->getPathname());

                    $themes[$code] = new ThemeInfo(
                        code: $code,
                        area: $area,
                        isHyva: $isHyva,
                        parentTheme: $parent
                    );
                }
            }
        }

        // Always include Magento/backend for adminhtml
        if (!isset($themes['Magento/backend'])) {
            $themes['Magento/backend'] = new ThemeInfo(
                code: 'Magento/backend',
                area: 'adminhtml',
                isHyva: false,
                parentTheme: null
            );
        }

        return $themes;
    }

    private function readThemeParent(string $themePath): ?string
    {
        $themeXml = $themePath . '/theme.xml';
        if (!file_exists($themeXml)) {
            return null;
        }

        $xml = @simplexml_load_file($themeXml);
        if (!$xml) {
            return null;
        }

        $parent = (string) ($xml->parent ?? '');
        return $parent !== '' ? $parent : null;
    }
}
