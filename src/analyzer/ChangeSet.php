<?php

declare(strict_types=1);

namespace Nola\Deploy\Analyzer;

class ChangeSet
{
    /** @var string[] */
    public array $phpFiles = [];

    /** @var string[] */
    public array $diXmlFiles = [];

    /** @var string[] */
    public array $themeFiles = [];

    /** @var string[] */
    public array $configFiles = [];

    /** @var string[] */
    public array $composerFiles = [];

    /** @var string[] */
    public array $dbSchemaFiles = [];

    /** @var string[] */
    public array $staticFiles = [];

    /** @var string[] */
    public array $allFiles = [];

    public bool $isFullRebuild = false;

    public function hasPhpChanges(): bool
    {
        return !empty($this->phpFiles);
    }

    public function hasDiChanges(): bool
    {
        return !empty($this->diXmlFiles) || !empty($this->composerFiles);
    }

    public function hasThemeChanges(): bool
    {
        return !empty($this->themeFiles) || !empty($this->staticFiles);
    }

    public function hasConfigChanges(): bool
    {
        return !empty($this->configFiles);
    }

    public function hasDbChanges(): bool
    {
        return !empty($this->dbSchemaFiles);
    }

    public function hasAnyChanges(): bool
    {
        return !empty($this->allFiles) || $this->isFullRebuild;
    }

    public function getChangedThemeCodes(): array
    {
        $themes = [];
        foreach ($this->themeFiles as $file) {
            // Extract theme code from path like app/design/frontend/Vendor/theme/...
            if (preg_match('#app/design/(frontend|adminhtml)/([^/]+/[^/]+)/#', $file, $m)) {
                $themes[$m[2]] = true;
            }
        }

        // Module view changes affect all themes
        foreach ($this->staticFiles as $file) {
            if (preg_match('#(app/code|vendor)/.*?/view/(frontend|adminhtml)/#', $file)) {
                return []; // Empty = all themes affected
            }
        }

        return array_keys($themes);
    }

    public function getSummary(): string
    {
        if ($this->isFullRebuild) {
            return 'Full rebuild (no manifest or --force)';
        }

        if (!$this->hasAnyChanges()) {
            return 'No changes detected';
        }

        $parts = [];
        if (!empty($this->phpFiles)) {
            $parts[] = count($this->phpFiles) . ' PHP file(s)';
        }
        if (!empty($this->diXmlFiles)) {
            $parts[] = count($this->diXmlFiles) . ' di.xml file(s)';
        }
        if (!empty($this->themeFiles)) {
            $parts[] = count($this->themeFiles) . ' theme file(s)';
        }
        if (!empty($this->configFiles)) {
            $parts[] = count($this->configFiles) . ' config file(s)';
        }
        if (!empty($this->composerFiles)) {
            $parts[] = 'composer changes';
        }
        if (!empty($this->dbSchemaFiles)) {
            $parts[] = count($this->dbSchemaFiles) . ' DB schema file(s)';
        }

        return 'Changed: ' . implode(', ', $parts);
    }
}
