<?php

declare(strict_types=1);

namespace Nola\Deploy\Builder;

use Nola\Deploy\Analyzer\ThemeInfo;
use Nola\Deploy\Runner\MagentoRunner;
use Nola\Deploy\Runner\TaskResult;
use Nola\Deploy\Util\Logger;

/**
 * Surgical static content deployment: handles granular changes
 * without running full setup:static-content:deploy.
 *
 * - JS files: copy directly to pub/static/ + bust browser cache
 * - KO .html templates: copy directly to pub/static/ + bust cache
 * - Font/image files: copy directly to pub/static/ + bust cache
 * - PHTML templates: clear var/view_preprocessed/ + flush cache
 *
 * For compilation-based partial SCD (LESS, requirejs-config, view.xml,
 * translations), see PartialScdRunner.
 */
class SurgicalStaticDeployer
{
    public function __construct(
        private string $magentoRoot,
        private MagentoRunner $magentoRunner,
        private Logger $logger,
    ) {
    }

    /**
     * Copy changed JS files directly to pub/static/ locations.
     *
     * @param string[] $jsFiles Relative paths to changed JS files
     * @param ThemeInfo[] $themes Active themes
     * @param string[] $locales Active locales
     */
    public function copyJsFiles(array $jsFiles, array $themes, array $locales): TaskResult
    {
        $this->logger->step('Surgical JS Deploy (' . count($jsFiles) . ' file(s))');
        return $this->copyStaticFiles($jsFiles, $themes, $locales, 'Surgical JS Deploy');
    }

    /**
     * Copy changed KnockoutJS .html templates to pub/static/.
     * Same mechanism as JS copy — direct file copy + cache bust.
     *
     * @param string[] $htmlFiles Relative paths to changed .html files
     * @param ThemeInfo[] $themes Active themes
     * @param string[] $locales Active locales
     */
    public function copyHtmlTemplates(array $htmlFiles, array $themes, array $locales): TaskResult
    {
        $this->logger->step('Surgical HTML Template Deploy (' . count($htmlFiles) . ' file(s))');
        return $this->copyStaticFiles($htmlFiles, $themes, $locales, 'Surgical HTML Deploy');
    }

    /**
     * Copy changed font/image files to pub/static/.
     * Pure file copy — no compilation needed.
     *
     * @param string[] $files Relative paths to changed font/image files
     * @param ThemeInfo[] $themes Active themes
     * @param string[] $locales Active locales
     */
    public function copyFontImageFiles(array $files, array $themes, array $locales): TaskResult
    {
        $this->logger->step('Surgical Font/Image Deploy (' . count($files) . ' file(s))');
        return $this->copyStaticFiles($files, $themes, $locales, 'Surgical Font/Image Deploy');
    }

    /**
     * Copy multiple types of static files to pub/static/ in one pass.
     * Used when JS + HTML + fonts + images change together (no LESS/requirejs-config).
     *
     * @param string[] $files All copyable file paths
     * @param ThemeInfo[] $themes Active themes
     * @param string[] $locales Active locales
     */
    public function copyMixedStaticFiles(array $files, array $themes, array $locales): TaskResult
    {
        $this->logger->step('Surgical Static Deploy (' . count($files) . ' file(s))');
        return $this->copyStaticFiles($files, $themes, $locales, 'Surgical Static Deploy');
    }

    /**
     * Clear preprocessed cache for changed .phtml files.
     * No SCD needed — Magento re-processes templates on next request.
     *
     * @param string[] $phtmlFiles Relative paths to changed .phtml files
     */
    public function clearPhtmlCache(array $phtmlFiles): TaskResult
    {
        $startTime = microtime(true);
        $this->logger->step('Surgical PHTML Cache Clear (' . count($phtmlFiles) . ' file(s))');

        $cleared = 0;
        $preprocessedDir = $this->magentoRoot . '/var/view_preprocessed/';

        if (is_dir($preprocessedDir)) {
            foreach ($phtmlFiles as $phtmlFile) {
                // Find and delete matching preprocessed entries
                $count = $this->clearPreprocessedForFile($preprocessedDir, $phtmlFile);
                $cleared += $count;
            }
        }

        $this->logger->info("Cleared {$cleared} preprocessed entries");

        // Flush block_html + full_page (templates affect rendered blocks)
        $this->magentoRunner->run('cache:clean', ['block_html', 'full_page'], 30, false);

        $duration = microtime(true) - $startTime;

        return new TaskResult(
            label: 'Surgical PHTML Clear',
            exitCode: 0,
            output: "Cleared {$cleared} preprocessed entry(ies) for " . count($phtmlFiles) . " template(s)",
            errorOutput: '',
            duration: $duration,
            success: true,
        );
    }

    /**
     * Generic file copy to pub/static/ with cache busting.
     *
     * @param string[] $files Relative paths to source files
     * @param ThemeInfo[] $themes Active themes
     * @param string[] $locales Active locales
     * @param string $label Task result label
     */
    private function copyStaticFiles(array $files, array $themes, array $locales, string $label): TaskResult
    {
        $startTime = microtime(true);
        $copied = 0;
        $errors = [];

        foreach ($files as $file) {
            $targets = $this->resolveStaticTargets($file, $themes, $locales);
            foreach ($targets as $target) {
                $sourcePath = $this->magentoRoot . '/' . $file;
                $targetPath = $this->magentoRoot . '/' . $target;

                if (!file_exists($sourcePath)) {
                    continue;
                }

                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }

                if (@copy($sourcePath, $targetPath)) {
                    $copied++;
                } else {
                    $errors[] = "Failed to copy {$file} → {$target}";
                }
            }
        }

        $this->updateDeployedVersion();
        $this->logger->info("Copied {$copied} file(s) to pub/static/");
        $this->magentoRunner->run('cache:clean', ['full_page'], 30, false);

        $duration = microtime(true) - $startTime;

        return new TaskResult(
            label: $label,
            exitCode: empty($errors) ? 0 : 1,
            output: empty($errors) ? "Copied {$copied} file(s)" : implode("\n", $errors),
            errorOutput: '',
            duration: $duration,
            success: empty($errors),
        );
    }

    /**
     * Resolve where a source JS/CSS file maps to in pub/static/.
     *
     * Source patterns:
     * - app/design/frontend/Vendor/Theme/web/js/foo.js → pub/static/frontend/Vendor/Theme/{locale}/js/foo.js
     * - app/code/Vendor/Module/view/frontend/web/js/bar.js → pub/static/frontend/Vendor/Theme/{locale}/Vendor_Module/js/bar.js
     *
     * @return string[] Target paths relative to Magento root
     */
    private function resolveStaticTargets(string $sourcePath, array $themes, array $locales): array
    {
        $targets = [];

        // Theme-level file: app/design/{area}/{Vendor}/{Theme}/web/{path}
        if (preg_match('#^app/design/(frontend|adminhtml)/([^/]+/[^/]+)/web/(.+)$#', $sourcePath, $m)) {
            $area = $m[1];
            $themeCode = $m[2];
            $filePath = $m[3];

            foreach ($locales as $locale) {
                $targets[] = "pub/static/{$area}/{$themeCode}/{$locale}/{$filePath}";
            }
            return $targets;
        }

        // Module-level file: app/code/Vendor/Module/view/{area}/web/{path}
        // or vendor/vendor/module/view/{area}/web/{path}
        if (preg_match('#(?:app/code|vendor)/([^/]+)/([^/]+)/view/(frontend|adminhtml)/web/(.+)$#', $sourcePath, $m)) {
            $vendor = $m[1];
            $module = $m[2];
            $area = $m[3];
            $filePath = $m[4];

            // Module name in pub/static uses underscore: Vendor_Module
            $moduleName = $vendor . '_' . $this->normalizeModuleName($module);

            foreach ($themes as $theme) {
                if ($theme->area !== $area) {
                    continue;
                }
                foreach ($locales as $locale) {
                    $targets[] = "pub/static/{$area}/{$theme->code}/{$locale}/{$moduleName}/{$filePath}";
                }
            }
        }

        return $targets;
    }

    /**
     * Convert kebab-case module directory name to PascalCase.
     * e.g. "module-catalog" → "Catalog", "module-catalog-search" → "CatalogSearch"
     */
    private function normalizeModuleName(string $dirName): string
    {
        // Remove "module-" prefix common in vendor packages
        $name = preg_replace('/^module-/', '', $dirName);
        // Convert kebab-case to PascalCase
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $name)));
    }

    private function clearPreprocessedForFile(string $preprocessedDir, string $phtmlFile): int
    {
        $cleared = 0;
        $basename = basename($phtmlFile);

        // Search for matching files in var/view_preprocessed/
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($preprocessedDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getFilename() === $basename) {
                if (@unlink($fileInfo->getPathname())) {
                    $cleared++;
                }
            }
        }

        return $cleared;
    }

    private function updateDeployedVersion(): void
    {
        $versionFile = $this->magentoRoot . '/pub/static/deployed_version.txt';
        @file_put_contents($versionFile, (string) time());
    }
}
