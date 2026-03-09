<?php

declare(strict_types=1);

namespace Nola\Deploy\Command;

use Nola\Deploy\Analyzer\ChangeAnalyzer;
use Nola\Deploy\Analyzer\ChangeDetector;
use Nola\Deploy\Analyzer\LocaleDetector;
use Nola\Deploy\Analyzer\ThemeDetector;
use Nola\Deploy\Builder\DiCompiler;
use Nola\Deploy\Builder\SurgicalDiCompiler;
use Nola\Deploy\Runner\MagentoRunner;
use Nola\Deploy\Runner\PostDeployRunner;
use Nola\Deploy\Util\BinaryDetector;
use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;
use Nola\Deploy\Util\MaintenancePageHandler;
use Nola\Deploy\Util\Manifest;
use Nola\Deploy\Util\Profiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'deploy', description: 'Run optimized Magento 2 deployment')]
class DeployCommand extends Command
{
    use RequiresConfigTrait;
    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force full rebuild (skip change detection)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would run without executing')
            ->addOption('skip-di', null, InputOption::VALUE_NONE, 'Skip DI compilation')
            ->addOption('skip-static', null, InputOption::VALUE_NONE, 'Skip static content deployment')
            ->addOption('skip-upgrade', null, InputOption::VALUE_NONE, 'Skip setup:upgrade')
            ->addOption('maintenance', 'm', InputOption::VALUE_NONE, 'Enable maintenance mode during deploy')
            ->addOption('themes', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of themes to deploy')
            ->addOption('locales', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of locales')
            ->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of parallel workers')
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Path to Magento root directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new Logger($output);
        $profiler = new Profiler();

        $logger->banner('nola-deploy v1.0.0');

        try {
            // Load config
            $magentoRoot = $input->getOption('magento-root');
            $config = (new ConfigLoader())->load($magentoRoot);

            if (!$this->requireConfig($config, $logger)) {
                return Command::FAILURE;
            }

            // Override parallel jobs if specified
            if ($input->getOption('jobs')) {
                $config->toArray()['static_content']['parallel_jobs'] = (int) $input->getOption('jobs');
            }

            $root = $config->getMagentoRoot();
            $force = (bool) $input->getOption('force');
            $dryRun = (bool) $input->getOption('dry-run');
            $maintenance = (bool) $input->getOption('maintenance');

            // Validate environment
            $this->validateEnvironment($root, $logger);

            // Detect themes and locales
            $profiler->start('Analysis');

            $themeDetector = new ThemeDetector($config);
            $localeDetector = new LocaleDetector($config);

            // Override themes/locales from CLI
            if ($input->getOption('themes')) {
                $themeNames = explode(',', $input->getOption('themes'));
                $allThemes = $themeDetector->detectAll();
                $themes = array_filter($allThemes, fn($t) => in_array($t->code, $themeNames, true));
            } else {
                $themes = $themeDetector->detect();
            }

            if ($input->getOption('locales')) {
                $locales = explode(',', $input->getOption('locales'));
            } else {
                $locales = $localeDetector->detect();
            }

            $logger->step('Analysis');
            $logger->info('Magento root: ' . $root);
            $logger->info('Themes: ' . implode(', ', array_map(fn($t) => "{$t->code} ({$t->getType()})", $themes)));
            $logger->info('Locales: ' . implode(', ', $locales));

            // Change detection
            $manifest = (new Manifest($root))->load();
            $changeDetector = new ChangeDetector($root, $manifest);
            $changeAnalyzer = new ChangeAnalyzer();

            $changes = $force ? $this->createFullRebuildChangeSet() : $changeDetector->detect();

            if ($force) {
                $logger->info('Mode: Full rebuild (--force)');
            } elseif (!$manifest->exists()) {
                $logger->info('Mode: Full rebuild (first deploy)');
            } else {
                $logger->info('Mode: Incremental (last deploy: ' . ($manifest->getLastDeployTime() ?? 'unknown') . ')');
                $logger->info('Changes: ' . $changes->getSummary());
            }

            $profiler->stop('Analysis');

            if (!$changes->hasAnyChanges() && !$force) {
                $logger->success('No changes detected since last deploy. Nothing to do.');
                $logger->info('Use "nola-deploy deploy:diff" to see details.');
                $logger->info('Use "nola-deploy deploy:fresh" for a clean rebuild.');
                $logger->line($profiler->formatReport());
                return Command::SUCCESS;
            }

            // Show what will run
            $logger->step('Plan');
            if (!$input->getOption('skip-upgrade') && $changeAnalyzer->needsSetupUpgrade($changes)) {
                $logger->info('  ▸ setup:upgrade --keep-generated');
            } else {
                $logger->info('  ✓ setup:upgrade skipped (no DB schema/patch changes)');
            }
            if (!$input->getOption('skip-di')) {
                if ($changeAnalyzer->canSkipDi($changes)) {
                    $logger->info('  ✓ di:compile skipped (plugin code only — loaded dynamically)');
                } elseif ($changeAnalyzer->canSurgicalDi($changes)) {
                    $nonPluginPhp = array_diff($changes->phpFiles, $changes->pluginFiles);
                    $logger->info('  ▸ surgical di:compile (' . count($nonPluginPhp) . ' class(es))');
                } elseif ($changeAnalyzer->needsDiCompile($changes)) {
                    $logger->info('  ▸ setup:di:compile (full)');
                } else {
                    $logger->info('  ✓ di:compile skipped (no PHP/DI changes)');
                }
            } else {
                $logger->info('  ✓ di:compile skipped (--skip-di)');
            }
            // Create services early (needed for plan display + execution)
            $magentoRunner = new MagentoRunner($config, $logger);
            $binaryDetector = new BinaryDetector($root);
            $scdExecutor = new ScdStepExecutor($root, $config, $magentoRunner, $binaryDetector, $logger);

            if (!$input->getOption('skip-static')) {
                $scdExecutor->displayPlan($changeAnalyzer, $changes, $themes, $force);
            } else {
                $logger->info('  ✓ static-content:deploy skipped (--skip-static)');
            }
            $logger->info('  ▸ cache:flush');

            if ($dryRun) {
                return $this->dryRun($changes, $changeAnalyzer, $themes, $locales, $logger, $config);
            }

            // Step 1: Maintenance mode
            $maintenanceHandler = new MaintenancePageHandler($config, $logger);
            if ($maintenance) {
                $profiler->start('Maintenance Enable');
                $maintenanceHandler->install();
                $magentoRunner->run('maintenance:enable', [], 30, false);
                $profiler->stop('Maintenance Enable');
            }

            // Step 2: setup:upgrade
            if (!$input->getOption('skip-upgrade') && $changeAnalyzer->needsSetupUpgrade($changes)) {
                $profiler->start('setup:upgrade');
                $result = $magentoRunner->run('setup:upgrade', ['--keep-generated'], 300);
                $profiler->stop('setup:upgrade');
                if (!$result->success) {
                    $logger->error('setup:upgrade failed');
                    return Command::FAILURE;
                }
            }

            // Step 3: DI Compilation — surgical or full
            if (!$input->getOption('skip-di')) {
                if ($changeAnalyzer->canSkipDi($changes)) {
                    $logger->info('DI compile skipped (plugin code only)');
                } elseif ($changeAnalyzer->canSurgicalDi($changes)) {
                    $profiler->start('DI Compilation');
                    $nonPluginPhp = array_diff($changes->phpFiles, $changes->pluginFiles);
                    $surgicalDi = new SurgicalDiCompiler($root, $magentoRunner, $logger);
                    $result = $surgicalDi->compileAffectedClasses($nonPluginPhp);
                    $profiler->stop('DI Compilation');
                    if (!$result->success) {
                        $logger->error('Surgical DI compilation failed');
                        return Command::FAILURE;
                    }
                } elseif ($changeAnalyzer->needsDiCompile($changes)) {
                    $profiler->start('DI Compilation');
                    $diCompiler = new DiCompiler($magentoRunner, $logger);
                    $result = $diCompiler->compile();
                    $profiler->stop('DI Compilation');
                    if (!$result->success) {
                        $logger->error('DI compilation failed');
                        return Command::FAILURE;
                    }
                } else {
                    $logger->info('DI compile skipped (no changes detected)');
                }
            }

            // Step 4: Static Content Deploy — surgical, partial, or full
            if (!$input->getOption('skip-static')) {
                $scdResult = $scdExecutor->execute(
                    $changeAnalyzer, $changes, $themes, $locales, $force, $profiler
                );
                if ($scdResult !== null) {
                    return $scdResult;
                }
            }

            // Step 5: Cache operations
            $profiler->start('Cache Flush');
            if ($config->getCacheFlushAll()) {
                $magentoRunner->run('cache:flush', [], 60, false);
            } else {
                $types = $config->getCacheTypes();
                $magentoRunner->run('cache:flush', $types ?: [], 60, false);
            }
            $profiler->stop('Cache Flush');

            // Step 5b: Post-deploy commands
            $postDeployRunner = new PostDeployRunner($config, $logger);
            $profiler->start('Post-Deploy');
            $postDeployRunner->run();
            $profiler->stop('Post-Deploy');

            // Step 6: Disable maintenance
            if ($maintenance) {
                $profiler->start('Maintenance Disable');
                $magentoRunner->run('maintenance:disable', [], 30, false);
                $maintenanceHandler->restore();
                $profiler->stop('Maintenance Disable');
            }

            // Save manifest
            $manifest->update(
                gitCommit: $changeDetector->getCurrentGitCommit(),
                hashes: $changeDetector->computeCurrentHashes(),
                deployedThemes: array_map(fn($t) => $t->code, $themes),
                deployedLocales: $locales,
                duration: $profiler->getTotal(),
            );
            $manifest->save();

            // Report
            $logger->separator();
            $logger->line($profiler->formatReport());
            $logger->success('Deployment completed successfully!');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if (isset($maintenanceHandler)) {
                $maintenanceHandler->restore();
            }
            $logger->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function validateEnvironment(string $root, Logger $logger): void
    {
        if (!file_exists($root . '/bin/magento')) {
            throw new \RuntimeException("bin/magento not found in: {$root}");
        }

        if (!file_exists($root . '/app/etc/config.php')) {
            throw new \RuntimeException("app/etc/config.php not found — is Magento installed?");
        }

        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if (version_compare($phpVersion, '8.1', '<')) {
            throw new \RuntimeException("PHP 8.1+ required, found: {$phpVersion}");
        }

        $logger->info("PHP: " . phpversion() . " | Root: {$root}");
    }

    private function createFullRebuildChangeSet(): \Nola\Deploy\Analyzer\ChangeSet
    {
        $cs = new \Nola\Deploy\Analyzer\ChangeSet();
        $cs->isFullRebuild = true;
        return $cs;
    }

    private function dryRun($changes, $analyzer, $themes, $locales, $logger, $config): int
    {
        $logger->step('DRY RUN — No commands will be executed');
        $logger->separator();

        $themeCodes = array_map(fn($t) => $t->code, $themes);

        if ($analyzer->needsSetupUpgrade($changes)) {
            $logger->info('[WOULD RUN] bin/magento setup:upgrade --keep-generated');
        } else {
            $logger->info('[SKIP] setup:upgrade (no DB changes)');
        }

        if ($analyzer->needsDiCompile($changes)) {
            $gcFlag = $config->isDiGcDisabled() ? ' -d zend.enable_gc=0' : '';
            $logger->info("[WOULD RUN] php{$gcFlag} bin/magento setup:di:compile");
        } else {
            $logger->info('[SKIP] setup:di:compile (no PHP/DI changes)');
        }

        if ($analyzer->needsStaticDeploy($changes)) {
            $changedThemes = $analyzer->getChangedThemes($changes, $themeCodes);
            $strategy = $config->getScdStrategy();

            foreach ($themes as $theme) {
                if (!empty($changedThemes) && !in_array($theme->code, $changedThemes, true)) {
                    $logger->info("[SKIP] SCD for {$theme->code} (no changes)");
                    continue;
                }
                $type = $theme->isHyva ? '[Go deployer]' : "[SCD --strategy={$strategy}]";
                $logger->info("[WOULD RUN] {$type} {$theme->code} ({$theme->area}) " . implode(' ', $locales));
            }
        } else {
            $logger->info('[SKIP] static-content:deploy (no theme changes)');
        }

        $logger->info('[WOULD RUN] bin/magento cache:flush');
        $logger->separator();

        return Command::SUCCESS;
    }
}
