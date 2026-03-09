<?php

declare(strict_types=1);

namespace Nola\Deploy\Builder;

use Nola\Deploy\Analyzer\ChangeSet;
use Nola\Deploy\Analyzer\ThemeInfo;
use Nola\Deploy\Runner\MagentoRunner;
use Nola\Deploy\Runner\TaskResult;
use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;

/**
 * Runs partial SCD with targeted flags for specific file type changes.
 *
 * Instead of full SCD, uses --no-{type} flags + --theme + --area
 * to deploy only the content types that actually changed.
 */
class PartialScdRunner
{
    public function __construct(
        private ConfigLoader $config,
        private MagentoRunner $magentoRunner,
        private Logger $logger,
    ) {
    }

    /**
     * CSS-only partial SCD: recompile LESS→CSS for affected themes.
     *
     * @param ChangeSet $changes Change set with lessFiles populated
     * @param ThemeInfo[] $themes Active themes
     * @param string[] $locales Active locales
     */
    public function deployCssOnly(ChangeSet $changes, array $themes, array $locales): TaskResult
    {
        $this->logger->step('Partial SCD — CSS only (' . count($changes->lessFiles) . ' file(s))');

        $affectedThemes = $this->resolveAffectedThemes($changes->lessFiles, $themes);
        $areas = $this->resolveAreas($changes->lessFiles, $affectedThemes);

        $args = $this->buildScdArgs($affectedThemes, $locales, $areas, [
            '--no-javascript',
            '--no-fonts',
            '--no-images',
            '--no-misc',
            '--no-html-minify',
        ]);

        $this->logger->info('Themes: ' . implode(', ', array_map(fn($t) => $t->code, $affectedThemes)));

        return $this->runScd('Partial SCD (CSS)', $args);
    }

    /**
     * RequireJS config partial SCD: rebuild merged requirejs-config.js.
     *
     * @param ChangeSet $changes Change set with requireJsConfigFiles populated
     * @param ThemeInfo[] $themes Active themes
     * @param string[] $locales Active locales
     */
    public function deployRequireJsConfig(ChangeSet $changes, array $themes, array $locales): TaskResult
    {
        $this->logger->step('Partial SCD — RequireJS config (' . count($changes->requireJsConfigFiles) . ' file(s))');

        $areas = $this->resolveAreas($changes->requireJsConfigFiles, $themes);

        $args = $this->buildScdArgs($themes, $locales, $areas, [
            '--no-css',
            '--no-less',
            '--no-fonts',
            '--no-images',
            '--no-misc',
            '--no-html-minify',
        ]);

        return $this->runScd('Partial SCD (RequireJS)', $args);
    }

    /**
     * View.xml partial SCD: regenerate image processing.
     *
     * @param ChangeSet $changes Change set with viewXmlFiles populated
     * @param ThemeInfo[] $themes Active themes
     * @param string[] $locales Active locales
     */
    public function deployViewXml(ChangeSet $changes, array $themes, array $locales): TaskResult
    {
        $this->logger->step('Partial SCD — view.xml (image resize config)');

        $areas = $this->resolveAreas($changes->viewXmlFiles, $themes);

        // view.xml changes affect images; skip CSS/JS/fonts
        $args = $this->buildScdArgs($themes, $locales, $areas, [
            '--no-css',
            '--no-less',
            '--no-javascript',
            '--no-fonts',
            '--no-html-minify',
        ]);

        return $this->runScd('Partial SCD (view.xml)', $args);
    }

    /**
     * Translation partial SCD: regenerate js-translation.json.
     *
     * @param ChangeSet $changes Change set with translationFiles populated
     * @param ThemeInfo[] $themes Active themes
     * @param string[] $locales Active locales
     */
    public function deployTranslations(ChangeSet $changes, array $themes, array $locales): TaskResult
    {
        $this->logger->step('Partial SCD — translations (' . count($changes->translationFiles) . ' file(s))');

        // Translations affect all areas/themes — rebuild JS translations
        $args = $this->buildScdArgs($themes, $locales, [], [
            '--no-css',
            '--no-less',
            '--no-fonts',
            '--no-images',
            '--no-html-minify',
        ]);

        return $this->runScd('Partial SCD (translations)', $args);
    }

    /**
     * Build SCD command arguments with targeted flags.
     *
     * @param ThemeInfo[] $themes Themes to deploy
     * @param string[] $locales Locales to deploy
     * @param string[] $areas Area filter (empty = all areas)
     * @param string[] $excludeFlags --no-* flags to skip content types
     * @return string[]
     */
    private function buildScdArgs(array $themes, array $locales, array $areas, array $excludeFlags): array
    {
        $strategy = $this->config->getScdStrategy();
        $args = ['-f', '--strategy=' . $strategy];

        // Add area filters
        foreach (array_unique($areas) as $area) {
            $args[] = '--area=' . $area;
        }

        // Add theme filters
        foreach ($themes as $theme) {
            if (!empty($areas) && !in_array($theme->area, $areas, true)) {
                continue;
            }
            $args[] = '--theme=' . $theme->code;
        }

        // Add exclusion flags
        $args = array_merge($args, $excludeFlags);

        // Add locales
        $args = array_merge($args, $locales);

        return $args;
    }

    private function runScd(string $label, array $args): TaskResult
    {
        $startTime = microtime(true);
        $result = $this->magentoRunner->run('setup:static-content:deploy', $args, 600);
        $duration = microtime(true) - $startTime;

        // Flush full_page cache after any static change
        $this->magentoRunner->run('cache:clean', ['full_page'], 30, false);

        // Update deployed_version.txt
        $versionFile = $this->config->getMagentoRoot() . '/pub/static/deployed_version.txt';
        @file_put_contents($versionFile, (string) time());

        $this->logger->info(sprintf('%s completed in %.1fs', $label, $duration));

        return new TaskResult(
            label: $label,
            exitCode: $result->exitCode,
            output: $result->output,
            errorOutput: $result->errorOutput,
            duration: $duration,
            success: $result->success,
        );
    }

    /**
     * Determine which themes are affected by changed files.
     * Theme-level files → only that theme. Module-level → all themes.
     *
     * @param string[] $files Changed file paths
     * @param ThemeInfo[] $allThemes All active themes
     * @return ThemeInfo[]
     */
    private function resolveAffectedThemes(array $files, array $allThemes): array
    {
        $specificThemes = [];
        $needsAllThemes = false;

        foreach ($files as $file) {
            // Theme-specific file: app/design/{area}/{Vendor}/{Theme}/...
            if (preg_match('#^app/design/(frontend|adminhtml)/([^/]+/[^/]+)/#', $file, $m)) {
                $specificThemes[$m[2]] = true;
            } else {
                // Module-level file affects all themes
                $needsAllThemes = true;
                break;
            }
        }

        if ($needsAllThemes) {
            return $allThemes;
        }

        return array_filter($allThemes, fn($t) => isset($specificThemes[$t->code]));
    }

    /**
     * Determine which areas are affected by changed files.
     *
     * @param string[] $files Changed file paths
     * @param ThemeInfo[] $themes Themes (for area reference)
     * @return string[]
     */
    private function resolveAreas(array $files, array $themes): array
    {
        $areas = [];
        foreach ($files as $file) {
            if (preg_match('#/(frontend|adminhtml)/#', $file, $m)) {
                $areas[$m[1]] = true;
            }
        }
        // If no area detected from file paths, use all areas from themes
        if (empty($areas)) {
            foreach ($themes as $theme) {
                $areas[$theme->area] = true;
            }
        }
        return array_keys($areas);
    }
}
