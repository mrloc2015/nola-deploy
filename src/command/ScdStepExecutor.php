<?php

declare(strict_types=1);

namespace Nola\Deploy\Command;

use Nola\Deploy\Analyzer\ChangeAnalyzer;
use Nola\Deploy\Analyzer\ChangeSet;
use Nola\Deploy\Analyzer\ThemeInfo;
use Nola\Deploy\Builder\PartialScdRunner;
use Nola\Deploy\Builder\StaticDeployer;
use Nola\Deploy\Builder\SurgicalStaticDeployer;
use Nola\Deploy\Runner\MagentoRunner;
use Nola\Deploy\Util\BinaryDetector;
use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;
use Nola\Deploy\Util\Profiler;
use Symfony\Component\Console\Command\Command;

/**
 * Routes SCD execution through the optimal strategy based on change type.
 *
 * Priority chain (most specific → least specific):
 * 1. PHTML only → cache clear (no SCD)
 * 2. JS only → file copy
 * 3. HTML templates only → file copy
 * 4. Fonts/images only → file copy
 * 5. Mixed copyable (JS+HTML+fonts+images) → file copy
 * 6. LESS/CSS only → partial SCD (CSS flags)
 * 7. requirejs-config.js only → partial SCD (JS merge)
 * 8. view.xml only → partial SCD (images)
 * 9. Translations only → partial SCD
 * 10. Full SCD → theme-targeted when possible
 */
class ScdStepExecutor
{
    public function __construct(
        private string $magentoRoot,
        private ConfigLoader $config,
        private MagentoRunner $magentoRunner,
        private BinaryDetector $binaryDetector,
        private Logger $logger,
    ) {
    }

    /**
     * Display the planned SCD action.
     */
    public function displayPlan(
        ChangeAnalyzer $analyzer,
        ChangeSet $changes,
        array $themes,
        bool $force,
    ): void {
        if ($analyzer->canSkipScd($changes)) {
            $this->logger->info('  ▸ phtml cache clear (' . count($changes->phtmlFiles) . ' template(s))');
        } elseif ($analyzer->canJsCopyOnly($changes)) {
            $this->logger->info('  ▸ surgical JS copy (' . count($changes->jsFiles) . ' file(s))');
        } elseif ($analyzer->canHtmlCopyOnly($changes)) {
            $this->logger->info('  ▸ surgical HTML template copy (' . count($changes->htmlTemplateFiles) . ' file(s))');
        } elseif ($analyzer->canFontImageCopyOnly($changes)) {
            $count = count($changes->fontFiles) + count($changes->imageFiles);
            $this->logger->info('  ▸ surgical font/image copy (' . $count . ' file(s))');
        } elseif ($analyzer->canCopyableStaticOnly($changes)) {
            $count = count($changes->jsFiles) + count($changes->htmlTemplateFiles)
                + count($changes->fontFiles) + count($changes->imageFiles);
            $this->logger->info('  ▸ surgical static copy (' . $count . ' file(s))');
        } elseif ($analyzer->canCssOnlyScd($changes)) {
            $this->logger->info('  ▸ partial SCD — CSS only (' . count($changes->lessFiles) . ' file(s))');
        } elseif ($analyzer->canRequireJsConfigScd($changes)) {
            $this->logger->info('  ▸ partial SCD — RequireJS config (' . count($changes->requireJsConfigFiles) . ' file(s))');
        } elseif ($analyzer->canViewXmlScd($changes)) {
            $this->logger->info('  ▸ partial SCD — view.xml (image resize config)');
        } elseif ($analyzer->canTranslationScd($changes)) {
            $this->logger->info('  ▸ partial SCD — translations (' . count($changes->translationFiles) . ' file(s))');
        } elseif ($analyzer->needsStaticDeploy($changes)) {
            $themeCodes = array_map(fn($t) => $t->code, $themes);
            $changedThemeCodes = $analyzer->getChangedThemes($changes, $themeCodes);
            if (!empty($changedThemeCodes) && !$force) {
                $this->logger->info('  ▸ static-content:deploy → ' . implode(', ', $changedThemeCodes));
            } else {
                $this->logger->info('  ▸ static-content:deploy → all themes');
            }
        } else {
            $this->logger->info('  ✓ static-content:deploy skipped (no theme changes)');
        }
    }

    /**
     * Execute the SCD step. Returns Command::FAILURE on error, null on success.
     *
     * @param ThemeInfo[] $themes
     * @param string[] $locales
     */
    public function execute(
        ChangeAnalyzer $analyzer,
        ChangeSet $changes,
        array $themes,
        array $locales,
        bool $force,
        Profiler $profiler,
    ): ?int {
        $surgical = new SurgicalStaticDeployer($this->magentoRoot, $this->magentoRunner, $this->logger);
        $partial = new PartialScdRunner($this->config, $this->magentoRunner, $this->logger);

        // Priority 1–5: File copy strategies (no SCD needed)
        $copyResult = $this->tryCopyStrategies($analyzer, $changes, $themes, $locales, $surgical, $profiler);
        if ($copyResult !== false) {
            return $copyResult;
        }

        // Priority 6–9: Partial SCD strategies
        $partialResult = $this->tryPartialScdStrategies($analyzer, $changes, $themes, $locales, $partial, $profiler);
        if ($partialResult !== false) {
            return $partialResult;
        }

        // Priority 10: Full SCD
        return $this->executeFullScd($analyzer, $changes, $themes, $locales, $force, $profiler);
    }

    /**
     * Try file-copy strategies. Returns null (success), Command::FAILURE, or false (not applicable).
     */
    private function tryCopyStrategies(
        ChangeAnalyzer $analyzer,
        ChangeSet $changes,
        array $themes,
        array $locales,
        SurgicalStaticDeployer $surgical,
        Profiler $profiler,
    ): null|int|false {
        if ($analyzer->canSkipScd($changes)) {
            $profiler->start('Static Content Deploy');
            $surgical->clearPhtmlCache($changes->phtmlFiles);
            $profiler->stop('Static Content Deploy');
            return null;
        }

        if ($analyzer->canJsCopyOnly($changes)) {
            return $this->runWithProfiler($profiler, fn() => $surgical->copyJsFiles($changes->jsFiles, $themes, $locales));
        }

        if ($analyzer->canHtmlCopyOnly($changes)) {
            return $this->runWithProfiler($profiler, fn() => $surgical->copyHtmlTemplates($changes->htmlTemplateFiles, $themes, $locales));
        }

        if ($analyzer->canFontImageCopyOnly($changes)) {
            $allFiles = array_merge($changes->fontFiles, $changes->imageFiles);
            return $this->runWithProfiler($profiler, fn() => $surgical->copyFontImageFiles($allFiles, $themes, $locales));
        }

        if ($analyzer->canCopyableStaticOnly($changes)) {
            $copyableJs = array_diff($changes->jsFiles, $changes->requireJsConfigFiles);
            $allFiles = array_merge($copyableJs, $changes->htmlTemplateFiles, $changes->fontFiles, $changes->imageFiles);
            return $this->runWithProfiler($profiler, fn() => $surgical->copyMixedStaticFiles($allFiles, $themes, $locales));
        }

        return false;
    }

    /**
     * Try partial SCD strategies. Returns null (success), Command::FAILURE, or false (not applicable).
     */
    private function tryPartialScdStrategies(
        ChangeAnalyzer $analyzer,
        ChangeSet $changes,
        array $themes,
        array $locales,
        PartialScdRunner $partial,
        Profiler $profiler,
    ): null|int|false {
        if ($analyzer->canCssOnlyScd($changes)) {
            return $this->runWithProfiler($profiler, fn() => $partial->deployCssOnly($changes, $themes, $locales));
        }
        if ($analyzer->canRequireJsConfigScd($changes)) {
            return $this->runWithProfiler($profiler, fn() => $partial->deployRequireJsConfig($changes, $themes, $locales));
        }
        if ($analyzer->canViewXmlScd($changes)) {
            return $this->runWithProfiler($profiler, fn() => $partial->deployViewXml($changes, $themes, $locales));
        }
        if ($analyzer->canTranslationScd($changes)) {
            return $this->runWithProfiler($profiler, fn() => $partial->deployTranslations($changes, $themes, $locales));
        }
        return false;
    }

    private function executeFullScd(
        ChangeAnalyzer $analyzer,
        ChangeSet $changes,
        array $themes,
        array $locales,
        bool $force,
        Profiler $profiler,
    ): ?int {
        if (!$analyzer->needsStaticDeploy($changes)) {
            $this->logger->info('Static content deploy skipped (no changes detected)');
            return null;
        }

        $profiler->start('Static Content Deploy');

        $themeCodes = array_map(fn($t) => $t->code, $themes);
        $changedThemeCodes = $analyzer->getChangedThemes($changes, $themeCodes);

        $deployThemes = $themes;
        if (!empty($changedThemeCodes) && !$force) {
            $deployThemes = array_filter($themes, fn($t) => in_array($t->code, $changedThemeCodes, true));
            if (!empty($deployThemes)) {
                $this->logger->info('Deploying only changed themes: ' . implode(', ', $changedThemeCodes));
            }
        }

        if (!empty($deployThemes)) {
            $staticDeployer = new StaticDeployer($this->config, $this->magentoRunner, $this->binaryDetector, $this->logger);
            $results = $staticDeployer->deploy(array_values($deployThemes), $locales);
            foreach ($results as $result) {
                if (!$result->success) {
                    $this->logger->error("Static deploy failed: {$result->label}");
                    $profiler->stop('Static Content Deploy');
                    return Command::FAILURE;
                }
            }
        }

        $profiler->stop('Static Content Deploy');
        return null;
    }

    private function runWithProfiler(Profiler $profiler, callable $fn): ?int
    {
        $profiler->start('Static Content Deploy');
        $result = $fn();
        $profiler->stop('Static Content Deploy');
        return $result->success ? null : Command::FAILURE;
    }
}
