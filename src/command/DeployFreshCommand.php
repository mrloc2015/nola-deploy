<?php

declare(strict_types=1);

namespace Nola\Deploy\Command;

use Nola\Deploy\Analyzer\LocaleDetector;
use Nola\Deploy\Analyzer\ThemeDetector;
use Nola\Deploy\Builder\DiCompiler;
use Nola\Deploy\Builder\StaticDeployer;
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

#[AsCommand(name: 'deploy:fresh', description: 'Clean everything and deploy from scratch (replaces deploy.sh)')]
class DeployFreshCommand extends Command
{
    use RequiresConfigTrait;
    protected function configure(): void
    {
        $this
            ->addOption('skip-upgrade', null, InputOption::VALUE_NONE, 'Skip setup:upgrade')
            ->addOption('maintenance', 'm', InputOption::VALUE_NONE, 'Enable maintenance mode during deploy')
            ->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of parallel workers')
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Path to Magento root directory')
            ->setHelp(<<<'HELP'
Performs a clean full deployment — the equivalent of deploy.sh but optimized.

This command:
  1. Cleans generated/, pub/static/frontend, pub/static/adminhtml, var/view_preprocessed/
  2. Runs setup:upgrade (unless --skip-upgrade)
  3. Runs setup:di:compile (with GC disabled for speed)
  4. Runs setup:static-content:deploy (parallel, dependency-aware)
  5. Flushes all caches

Usage:
  <info>nola-deploy deploy:fresh</info>                  # Full clean deploy
  <info>nola-deploy deploy:fresh --skip-upgrade</info>   # Skip setup:upgrade
  <info>nola-deploy deploy:fresh -m</info>               # With maintenance mode
  <info>nola-deploy deploy:fresh -j 8</info>             # Use 8 parallel workers
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new Logger($output);
        $profiler = new Profiler();

        $logger->banner('nola-deploy v1.0.0 — Fresh Deploy');

        try {
            $config = (new ConfigLoader())->load($input->getOption('magento-root'));

            if (!$this->requireConfig($config, $logger)) {
                return Command::FAILURE;
            }

            $root = $config->getMagentoRoot();
            $maintenance = (bool) $input->getOption('maintenance');

            $this->validateEnvironment($root, $logger);

            // Detect themes and locales
            $themeDetector = new ThemeDetector($config);
            $localeDetector = new LocaleDetector($config);
            $themes = $themeDetector->detect();
            $locales = $localeDetector->detect();

            $logger->info('Themes: ' . implode(', ', array_map(fn($t) => $t->code, $themes)));
            $logger->info('Locales: ' . implode(', ', $locales));

            // Step 1: Clean
            $profiler->start('Clean');
            $logger->step('Cleaning build artifacts');
            $this->clean($root, $logger);
            $profiler->stop('Clean');

            $magentoRunner = new MagentoRunner($config, $logger);
            $binaryDetector = new BinaryDetector($root);

            // Step 2: Maintenance mode
            $maintenanceHandler = new MaintenancePageHandler($config, $logger);
            if ($maintenance) {
                $profiler->start('Maintenance Enable');
                $maintenanceHandler->install();
                $magentoRunner->run('maintenance:enable', [], 30, false);
                $profiler->stop('Maintenance Enable');
            }

            // Step 3: setup:upgrade
            if (!$input->getOption('skip-upgrade')) {
                $profiler->start('setup:upgrade');
                $logger->step('Running setup:upgrade');
                $result = $magentoRunner->run('setup:upgrade', ['--keep-generated'], 300);
                $profiler->stop('setup:upgrade');
                if (!$result->success) {
                    $logger->error('setup:upgrade failed');
                    return Command::FAILURE;
                }
            } else {
                $logger->info('Skipping setup:upgrade (--skip-upgrade)');
            }

            // Step 4: DI Compile
            $profiler->start('DI Compilation');
            $logger->step('Running setup:di:compile');
            $diCompiler = new DiCompiler($magentoRunner, $logger);
            $result = $diCompiler->compile();
            $profiler->stop('DI Compilation');
            if (!$result->success) {
                $logger->error('DI compilation failed');
                return Command::FAILURE;
            }

            // Step 5: Static Content Deploy
            $profiler->start('Static Content Deploy');
            $logger->step('Running static-content:deploy');
            $staticDeployer = new StaticDeployer($config, $magentoRunner, $binaryDetector, $logger);
            $results = $staticDeployer->deploy($themes, $locales);
            $profiler->stop('Static Content Deploy');

            foreach ($results as $result) {
                if (!$result->success) {
                    $logger->error("Static deploy failed: {$result->label}");
                    return Command::FAILURE;
                }
            }

            // Step 6: Cache flush
            $profiler->start('Cache Flush');
            if ($config->getCacheFlushAll()) {
                $magentoRunner->run('cache:flush', [], 60, false);
            } else {
                $types = $config->getCacheTypes();
                $magentoRunner->run('cache:flush', $types ?: [], 60, false);
            }
            $profiler->stop('Cache Flush');

            // Step 6b: Post-deploy commands
            $postDeployRunner = new PostDeployRunner($config, $logger);
            $profiler->start('Post-Deploy');
            $postDeployRunner->run();
            $profiler->stop('Post-Deploy');

            // Step 7: Disable maintenance
            if ($maintenance) {
                $profiler->start('Maintenance Disable');
                $magentoRunner->run('maintenance:disable', [], 30, false);
                $maintenanceHandler->restore();
                $profiler->stop('Maintenance Disable');
            }

            // Save manifest
            $changeDetector = new \Nola\Deploy\Analyzer\ChangeDetector($root, new Manifest($root));
            $manifest = (new Manifest($root))->load();
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
            $logger->success('Fresh deployment completed successfully!');

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
        $logger->info("PHP: " . phpversion() . " | Root: {$root}");
    }

    private function clean(string $root, Logger $logger): void
    {
        $dirs = [
            $root . '/generated/code',
            $root . '/generated/metadata',
            $root . '/pub/static/frontend',
            $root . '/pub/static/adminhtml',
            $root . '/pub/static/_requirejs',
            $root . '/var/view_preprocessed',
            $root . '/var/cache',
            $root . '/var/page_cache',
            $root . '/var/di',
        ];

        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
                $logger->info('Cleaned: ' . str_replace($root . '/', '', $dir));
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
