<?php

declare(strict_types=1);

namespace Nola\Deploy\Analyzer;

class ChangeAnalyzer
{
    public function needsDiCompile(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return true;
        }

        return $changes->hasPhpChanges()
            || $changes->hasDiChanges()
            || $changes->hasConfigChanges();
    }

    public function needsStaticDeploy(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return true;
        }

        return $changes->hasThemeChanges()
            || $changes->hasDiChanges() // DI changes can affect view rendering
            || $changes->hasPhpChanges(); // PHP changes can affect blocks/templates
    }

    public function needsSetupUpgrade(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return true;
        }

        return $changes->hasDbChanges() || !empty($changes->composerFiles);
    }

    /** PHP changed but no di.xml — can regenerate specific generated files only */
    public function canSurgicalDi(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return false;
        }
        return $changes->hasPhpSourceChanges();
    }

    /** Only Plugin/*.php changed — plugins loaded dynamically, skip DI entirely */
    public function canSkipDi(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return false;
        }
        return $changes->hasOnlyPluginCodeChanges();
    }

    /** Only .phtml changed — clear preprocessed cache, skip SCD */
    public function canSkipScd(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return false;
        }
        return $changes->hasOnlyPhtmlChanges();
    }

    /** Only .js changed — copy files directly instead of full SCD */
    public function canJsCopyOnly(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return false;
        }
        return $changes->hasOnlyJsChanges();
    }

    /** Only KO .html templates changed — copy to pub/static/ */
    public function canHtmlCopyOnly(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return false;
        }
        return $changes->hasOnlyHtmlTemplateChanges();
    }

    /** Only LESS/CSS changed — run partial SCD with CSS-only flags */
    public function canCssOnlyScd(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return false;
        }
        return $changes->hasOnlyLessChanges();
    }

    /** Only fonts/images changed — direct copy to pub/static/ */
    public function canFontImageCopyOnly(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return false;
        }
        return $changes->hasOnlyFontImageChanges();
    }

    /** Only requirejs-config.js changed — partial SCD (JS config merge) */
    public function canRequireJsConfigScd(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return false;
        }
        return $changes->hasOnlyRequireJsConfigChanges();
    }

    /** Only view.xml changed — partial SCD (image resize) */
    public function canViewXmlScd(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return false;
        }
        return $changes->hasOnlyViewXmlChanges();
    }

    /** Only translation .csv changed — partial SCD */
    public function canTranslationScd(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return false;
        }
        return $changes->hasOnlyTranslationChanges();
    }

    /** Mix of copyable static files (JS + HTML + fonts + images) — no compilation needed */
    public function canCopyableStaticOnly(ChangeSet $changes): bool
    {
        if ($changes->isFullRebuild) {
            return false;
        }
        return $changes->hasOnlyCopyableStaticChanges();
    }

    /**
     * Returns theme codes that need redeployment.
     * Empty array means ALL themes need deployment (module-level changes).
     *
     * @return string[]
     */
    public function getChangedThemes(ChangeSet $changes, array $allThemes): array
    {
        if ($changes->isFullRebuild) {
            return $allThemes;
        }

        // Module view changes affect all themes
        if ($changes->hasPhpChanges() || $changes->hasDiChanges()) {
            return $allThemes;
        }

        $changedCodes = $changes->getChangedThemeCodes();
        if (empty($changedCodes)) {
            // Static files changed in modules → all themes
            if (!empty($changes->staticFiles)) {
                return $allThemes;
            }
            return [];
        }

        return $changedCodes;
    }
}
