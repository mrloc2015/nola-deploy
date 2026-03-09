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
