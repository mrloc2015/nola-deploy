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

    /** @var string[] LESS/CSS files */
    public array $lessFiles = [];

    /** @var string[] JS files in view/ */
    public array $jsFiles = [];

    /** @var string[] .phtml template files */
    public array $phtmlFiles = [];

    /** @var string[] Plugin/*.php files — no DI regen needed */
    public array $pluginFiles = [];

    /** @var string[] KnockoutJS .html templates (copy-only, no compilation) */
    public array $htmlTemplateFiles = [];

    /** @var string[] Font files (.ttf, .woff, .woff2, .eot) */
    public array $fontFiles = [];

    /** @var string[] Image files (.png, .jpg, .gif, .svg, .ico) */
    public array $imageFiles = [];

    /** @var string[] Module requirejs-config.js files (need merged config rebuild) */
    public array $requireJsConfigFiles = [];

    /** @var string[] etc/view.xml files (image resize config) */
    public array $viewXmlFiles = [];

    /** @var string[] Translation .csv files */
    public array $translationFiles = [];

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

    /** PHP source changed but NO di.xml — surgical DI possible */
    public function hasPhpSourceChanges(): bool
    {
        $nonPluginPhp = array_diff($this->phpFiles, $this->pluginFiles);
        return !empty($nonPluginPhp) && empty($this->diXmlFiles) && empty($this->composerFiles);
    }

    /** Only Plugin/*.php changed — no DI regen needed at all */
    public function hasOnlyPluginCodeChanges(): bool
    {
        if (empty($this->pluginFiles) || $this->isFullRebuild) {
            return false;
        }
        $nonPluginPhp = array_diff($this->phpFiles, $this->pluginFiles);
        return empty($nonPluginPhp) && empty($this->diXmlFiles) && empty($this->composerFiles)
            && empty($this->themeFiles) && empty($this->staticFiles)
            && empty($this->configFiles) && empty($this->dbSchemaFiles);
    }

    /** Only .phtml changed — clear preprocessed cache, no SCD or DI */
    public function hasOnlyPhtmlChanges(): bool
    {
        if (empty($this->phtmlFiles) || $this->isFullRebuild) {
            return false;
        }
        return empty($this->phpFiles) && empty($this->diXmlFiles)
            && empty($this->composerFiles) && empty($this->lessFiles)
            && empty($this->jsFiles) && empty($this->configFiles);
    }

    /** Only .js files changed (excludes requirejs-config.js) — copy to pub/static, no full SCD */
    public function hasOnlyJsChanges(): bool
    {
        if (empty($this->jsFiles) || $this->isFullRebuild) {
            return false;
        }
        // requirejs-config.js needs SCD merge, not simple copy
        $copyableJs = array_diff($this->jsFiles, $this->requireJsConfigFiles);
        if (empty($copyableJs)) {
            return false;
        }
        return empty($this->phpFiles) && empty($this->diXmlFiles)
            && empty($this->composerFiles) && empty($this->lessFiles)
            && empty($this->phtmlFiles) && empty($this->configFiles)
            && empty($this->requireJsConfigFiles) && empty($this->htmlTemplateFiles)
            && empty($this->fontFiles) && empty($this->imageFiles)
            && empty($this->viewXmlFiles) && empty($this->translationFiles);
    }

    /** Only KnockoutJS .html templates changed — copy to pub/static */
    public function hasOnlyHtmlTemplateChanges(): bool
    {
        if (empty($this->htmlTemplateFiles) || $this->isFullRebuild) {
            return false;
        }
        return empty($this->phpFiles) && empty($this->diXmlFiles)
            && empty($this->composerFiles) && empty($this->lessFiles)
            && empty($this->jsFiles) && empty($this->phtmlFiles)
            && empty($this->configFiles) && empty($this->fontFiles)
            && empty($this->imageFiles) && empty($this->viewXmlFiles)
            && empty($this->translationFiles);
    }

    /** Only LESS/CSS files changed — partial SCD (CSS-only) */
    public function hasOnlyLessChanges(): bool
    {
        if (empty($this->lessFiles) || $this->isFullRebuild) {
            return false;
        }
        return empty($this->phpFiles) && empty($this->diXmlFiles)
            && empty($this->composerFiles) && empty($this->jsFiles)
            && empty($this->phtmlFiles) && empty($this->configFiles)
            && empty($this->htmlTemplateFiles) && empty($this->fontFiles)
            && empty($this->imageFiles) && empty($this->viewXmlFiles)
            && empty($this->translationFiles);
    }

    /** Only font/image files changed — direct copy */
    public function hasOnlyFontImageChanges(): bool
    {
        if ((empty($this->fontFiles) && empty($this->imageFiles)) || $this->isFullRebuild) {
            return false;
        }
        return empty($this->phpFiles) && empty($this->diXmlFiles)
            && empty($this->composerFiles) && empty($this->lessFiles)
            && empty($this->jsFiles) && empty($this->phtmlFiles)
            && empty($this->configFiles) && empty($this->htmlTemplateFiles)
            && empty($this->viewXmlFiles) && empty($this->translationFiles);
    }

    /** Only requirejs-config.js changed — partial SCD (JS config merge) */
    public function hasOnlyRequireJsConfigChanges(): bool
    {
        if (empty($this->requireJsConfigFiles) || $this->isFullRebuild) {
            return false;
        }
        // May have requireJsConfigFiles in jsFiles too
        $otherJs = array_diff($this->jsFiles, $this->requireJsConfigFiles);
        return empty($this->phpFiles) && empty($this->diXmlFiles)
            && empty($this->composerFiles) && empty($this->lessFiles)
            && empty($otherJs) && empty($this->phtmlFiles)
            && empty($this->configFiles) && empty($this->htmlTemplateFiles)
            && empty($this->fontFiles) && empty($this->imageFiles)
            && empty($this->viewXmlFiles) && empty($this->translationFiles);
    }

    /** Only view.xml changed — partial SCD (image processing) */
    public function hasOnlyViewXmlChanges(): bool
    {
        if (empty($this->viewXmlFiles) || $this->isFullRebuild) {
            return false;
        }
        return empty($this->phpFiles) && empty($this->diXmlFiles)
            && empty($this->composerFiles) && empty($this->lessFiles)
            && empty($this->jsFiles) && empty($this->phtmlFiles)
            && empty($this->htmlTemplateFiles) && empty($this->fontFiles)
            && empty($this->imageFiles) && empty($this->translationFiles);
    }

    /** Only translation .csv files changed — partial SCD */
    public function hasOnlyTranslationChanges(): bool
    {
        if (empty($this->translationFiles) || $this->isFullRebuild) {
            return false;
        }
        return empty($this->phpFiles) && empty($this->diXmlFiles)
            && empty($this->composerFiles) && empty($this->lessFiles)
            && empty($this->jsFiles) && empty($this->phtmlFiles)
            && empty($this->configFiles) && empty($this->htmlTemplateFiles)
            && empty($this->fontFiles) && empty($this->imageFiles)
            && empty($this->viewXmlFiles);
    }

    /** Check if only copyable static files changed (JS + HTML + fonts + images) */
    public function hasOnlyCopyableStaticChanges(): bool
    {
        if ($this->isFullRebuild) {
            return false;
        }
        $copyableJs = array_diff($this->jsFiles, $this->requireJsConfigFiles);
        $hasCopyable = !empty($copyableJs) || !empty($this->htmlTemplateFiles)
            || !empty($this->fontFiles) || !empty($this->imageFiles);
        if (!$hasCopyable) {
            return false;
        }
        return empty($this->phpFiles) && empty($this->diXmlFiles)
            && empty($this->composerFiles) && empty($this->lessFiles)
            && empty($this->phtmlFiles) && empty($this->configFiles)
            && empty($this->requireJsConfigFiles) && empty($this->viewXmlFiles)
            && empty($this->translationFiles);
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
        if (!empty($this->lessFiles)) {
            $parts[] = count($this->lessFiles) . ' LESS/CSS file(s)';
        }
        if (!empty($this->jsFiles)) {
            $parts[] = count($this->jsFiles) . ' JS file(s)';
        }
        if (!empty($this->phtmlFiles)) {
            $parts[] = count($this->phtmlFiles) . ' PHTML file(s)';
        }
        if (!empty($this->htmlTemplateFiles)) {
            $parts[] = count($this->htmlTemplateFiles) . ' HTML template(s)';
        }
        if (!empty($this->fontFiles)) {
            $parts[] = count($this->fontFiles) . ' font file(s)';
        }
        if (!empty($this->imageFiles)) {
            $parts[] = count($this->imageFiles) . ' image file(s)';
        }
        if (!empty($this->requireJsConfigFiles)) {
            $parts[] = count($this->requireJsConfigFiles) . ' requirejs-config(s)';
        }
        if (!empty($this->viewXmlFiles)) {
            $parts[] = count($this->viewXmlFiles) . ' view.xml file(s)';
        }
        if (!empty($this->translationFiles)) {
            $parts[] = count($this->translationFiles) . ' translation file(s)';
        }

        return 'Changed: ' . implode(', ', $parts);
    }
}
