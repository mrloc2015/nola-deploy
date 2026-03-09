<?php

declare(strict_types=1);

namespace Nola\Deploy\Analyzer;

use Nola\Deploy\Util\Manifest;

class ChangeDetector
{
    public function __construct(
        private string $magentoRoot,
        private Manifest $manifest,
    ) {
    }

    public function detect(): ChangeSet
    {
        $changeSet = new ChangeSet();

        // If no manifest exists, force full rebuild
        if (!$this->manifest->exists()) {
            $changeSet->isFullRebuild = true;
            return $changeSet;
        }

        // Try git-based detection first
        $gitChanges = $this->detectFromGit();
        if ($gitChanges !== null) {
            return $this->categorizeFiles($gitChanges);
        }

        // Fall back to hash-based detection
        return $this->detectFromHashes();
    }

    /** @return string[]|null null if not in a git repo */
    private function detectFromGit(): ?array
    {
        $isGit = trim((string) @shell_exec(
            "cd " . escapeshellarg($this->magentoRoot) . " && git rev-parse --is-inside-work-tree 2>/dev/null"
        ));

        if ($isGit !== 'true') {
            return null;
        }

        $lastCommit = $this->manifest->getLastGitCommit();
        if (!$lastCommit) {
            return null; // No baseline commit — can't diff
        }

        // Check if the commit still exists (handles force-push/rebase)
        $commitExists = trim((string) @shell_exec(
            "cd " . escapeshellarg($this->magentoRoot)
            . " && git cat-file -t " . escapeshellarg($lastCommit) . " 2>/dev/null"
        ));

        if ($commitExists !== 'commit') {
            return null;
        }

        // Get changed files between last deploy and HEAD
        $output = trim((string) @shell_exec(
            "cd " . escapeshellarg($this->magentoRoot)
            . " && git diff " . escapeshellarg($lastCommit) . "..HEAD --name-only 2>/dev/null"
        ));

        $files = $output !== '' ? explode("\n", $output) : [];

        // Also include uncommitted changes (modified tracked files)
        $uncommitted = trim((string) @shell_exec(
            "cd " . escapeshellarg($this->magentoRoot)
            . " && git diff --name-only 2>/dev/null && git diff --cached --name-only 2>/dev/null"
        ));

        if ($uncommitted !== '') {
            $files = array_merge($files, explode("\n", $uncommitted));
        }

        // Also include untracked files in key directories (new files not yet git-added)
        $untracked = trim((string) @shell_exec(
            "cd " . escapeshellarg($this->magentoRoot)
            . " && git ls-files --others --exclude-standard"
            . " -- app/code app/design app/etc 2>/dev/null"
        ));

        if ($untracked !== '') {
            $files = array_merge($files, explode("\n", $untracked));
        }

        return array_unique(array_filter($files));
    }

    private function categorizeFiles(array $files): ChangeSet
    {
        $changeSet = new ChangeSet();
        $changeSet->allFiles = $files;

        foreach ($files as $file) {
            // PHP files
            if (str_ends_with($file, '.php')) {
                $changeSet->phpFiles[] = $file;

                // Plugin code — loaded dynamically, no DI regen needed
                if (str_contains($file, '/Plugin/') || str_contains($file, '/Plugins/')) {
                    $changeSet->pluginFiles[] = $file;
                }
            }

            // DI XML files
            if (str_ends_with($file, 'di.xml') || str_contains($file, '/etc/di/')) {
                $changeSet->diXmlFiles[] = $file;
            }

            // Theme files
            if (str_starts_with($file, 'app/design/')) {
                $changeSet->themeFiles[] = $file;
            }

            // .phtml template files
            if (str_ends_with($file, '.phtml')) {
                $changeSet->phtmlFiles[] = $file;
            }

            // Config files
            if ($file === 'app/etc/config.php' || $file === 'app/etc/env.php') {
                $changeSet->configFiles[] = $file;
            }

            // Composer files
            if ($file === 'composer.json' || $file === 'composer.lock') {
                $changeSet->composerFiles[] = $file;
            }

            // DB schema files — triggers setup:upgrade
            if (
                str_contains($file, 'db_schema.xml')
                || str_contains($file, 'db_schema_whitelist.json')
                || str_contains($file, 'Setup/Patch/')
                || str_contains($file, 'Setup/UpgradeSchema')
                || str_contains($file, 'Setup/UpgradeData')
                || str_contains($file, 'Setup/InstallSchema')
                || str_contains($file, 'Setup/InstallData')
                || str_contains($file, 'Setup/Recurring')
                || str_contains($file, '/etc/module.xml')
            ) {
                $changeSet->dbSchemaFiles[] = $file;
            }

            // LESS/CSS files
            if (str_ends_with($file, '.less') || str_ends_with($file, '.css')) {
                $changeSet->lessFiles[] = $file;
                $changeSet->staticFiles[] = $file;
            }
            // JS files
            elseif (str_ends_with($file, '.js')) {
                $changeSet->jsFiles[] = $file;
                $changeSet->staticFiles[] = $file;

                // requirejs-config.js needs SCD merge (not simple copy)
                if (basename($file) === 'requirejs-config.js') {
                    $changeSet->requireJsConfigFiles[] = $file;
                }
            }
            // KnockoutJS .html templates (in view/*/web/ or view/*/templates/)
            elseif (str_ends_with($file, '.html') && preg_match('#/view/(frontend|adminhtml)/web/#', $file)) {
                $changeSet->htmlTemplateFiles[] = $file;
                $changeSet->staticFiles[] = $file;
            }
            // Font files
            elseif (preg_match('#\.(ttf|woff|woff2|eot)$#', $file)) {
                $changeSet->fontFiles[] = $file;
                $changeSet->staticFiles[] = $file;
            }
            // Image files in web/ directories
            elseif (preg_match('#\.(png|jpg|jpeg|gif|svg|ico|webp)$#', $file)
                && preg_match('#/view/(frontend|adminhtml)/web/#', $file)
            ) {
                $changeSet->imageFiles[] = $file;
                $changeSet->staticFiles[] = $file;
            }
            // Other static/view files in modules
            elseif (preg_match('#/view/(frontend|adminhtml)/web/#', $file)) {
                $changeSet->staticFiles[] = $file;
            }

            // etc/view.xml — image resize configuration
            if (str_ends_with($file, '/etc/view.xml')) {
                $changeSet->viewXmlFiles[] = $file;
            }

            // Translation .csv files
            if (str_ends_with($file, '.csv') && str_contains($file, '/i18n/')) {
                $changeSet->translationFiles[] = $file;
            }
        }

        return $changeSet;
    }

    private function detectFromHashes(): ChangeSet
    {
        $changeSet = new ChangeSet();
        $root = $this->magentoRoot;

        // File-level hash checks (fast)
        $fileChecks = [
            'composer.lock' => $root . '/composer.lock',
            'config.php' => $root . '/app/etc/config.php',
            'vendor_installed' => $root . '/vendor/composer/installed.json',
        ];

        foreach ($fileChecks as $key => $path) {
            $currentHash = Manifest::computeFileHash($path);
            $storedHash = $this->manifest->getHash($key);

            if ($currentHash !== $storedHash) {
                $changeSet->allFiles[] = $key;
                if ($key === 'composer.lock' || $key === 'vendor_installed') {
                    $changeSet->composerFiles[] = $key;
                }
                if ($key === 'config.php') {
                    $changeSet->configFiles[] = $key;
                }
            }
        }

        // Directory-level hash checks (detects PHP/theme changes without git)
        $dirChecks = [
            'app_code' => $root . '/app/code',
            'app_design' => $root . '/app/design',
        ];

        foreach ($dirChecks as $key => $path) {
            if (!is_dir($path)) {
                continue;
            }
            $currentHash = Manifest::computeDirectoryHash($path);
            $storedHash = $this->manifest->getHash($key);

            if ($currentHash !== $storedHash) {
                $changeSet->allFiles[] = $key;
                if ($key === 'app_code') {
                    $changeSet->phpFiles[] = $key;
                    $changeSet->diXmlFiles[] = $key;
                }
                if ($key === 'app_design') {
                    $changeSet->themeFiles[] = $key;
                }
            }
        }

        // If composer-related files changed, force full rebuild
        if (!empty($changeSet->composerFiles)) {
            $changeSet->isFullRebuild = true;
        }

        return $changeSet;
    }

    public function getCurrentGitCommit(): string
    {
        $commit = trim((string) @shell_exec(
            "cd " . escapeshellarg($this->magentoRoot)
            . " && git rev-parse HEAD 2>/dev/null"
        ));
        return $commit !== '' ? $commit : 'unknown';
    }

    public function computeCurrentHashes(): array
    {
        $root = $this->magentoRoot;
        $hashes = [
            'composer.lock' => Manifest::computeFileHash($root . '/composer.lock'),
            'config.php' => Manifest::computeFileHash($root . '/app/etc/config.php'),
            'vendor_installed' => Manifest::computeFileHash($root . '/vendor/composer/installed.json'),
        ];

        if (is_dir($root . '/app/code')) {
            $hashes['app_code'] = Manifest::computeDirectoryHash($root . '/app/code');
        }
        if (is_dir($root . '/app/design')) {
            $hashes['app_design'] = Manifest::computeDirectoryHash($root . '/app/design');
        }

        return $hashes;
    }
}
