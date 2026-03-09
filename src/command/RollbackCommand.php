<?php

declare(strict_types=1);

namespace Nola\Deploy\Command;

use Nola\Deploy\Deployer\ReleaseManager;
use Nola\Deploy\Deployer\SymlinkSwitcher;
use Nola\Deploy\Runner\MagentoRunner;
use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;
use Nola\Deploy\Util\Profiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rollback', description: 'Rollback to previous release')]
class RollbackCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('release', 'r', InputOption::VALUE_REQUIRED, 'Specific release directory to rollback to')
            ->addOption('current-link', null, InputOption::VALUE_REQUIRED, 'Path to current symlink', 'current')
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Magento root directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new Logger($output);
        $profiler = new Profiler();

        $logger->banner('nola-deploy rollback');

        try {
            $config = (new ConfigLoader())->load($input->getOption('magento-root'));
            $releasesDir = $config->getReleasesDir();

            $currentLink = $input->getOption('current-link');
            if (!str_starts_with($currentLink, '/')) {
                $currentLink = dirname($releasesDir) . '/' . $currentLink;
            }

            $releaseManager = new ReleaseManager($releasesDir, $currentLink);
            $current = $releaseManager->getCurrentRelease();

            // Determine target release
            $targetRelease = $input->getOption('release');
            if ($targetRelease) {
                if (!str_starts_with($targetRelease, '/')) {
                    $targetRelease = $releasesDir . '/' . $targetRelease;
                }
                if (!is_dir($targetRelease)) {
                    $logger->error("Release not found: {$targetRelease}");
                    return Command::FAILURE;
                }
            } else {
                $targetRelease = $releaseManager->getPreviousRelease();
                if (!$targetRelease) {
                    $logger->error('No previous release found to rollback to');
                    $logger->info('Available releases:');
                    foreach ($releaseManager->getReleaseDirs() as $dir) {
                        $isCurrent = ($current && realpath($dir) === realpath($current)) ? ' (current)' : '';
                        $logger->line("  " . basename($dir) . $isCurrent);
                    }
                    return Command::FAILURE;
                }
            }

            $logger->info("Current: " . ($current ? basename($current) : 'none'));
            $logger->info("Rolling back to: " . basename($targetRelease));

            // Step 1: Maintenance mode
            $profiler->start('Maintenance Enable');
            $magentoRunner = new MagentoRunner($config, $logger);
            $magentoRunner->run('maintenance:enable', [], 30, false);
            $profiler->stop('Maintenance Enable');

            // Step 2: Switch symlink
            $profiler->start('Symlink Switch');
            $switcher = new SymlinkSwitcher();
            $switcher->switch($targetRelease, $currentLink);
            $profiler->stop('Symlink Switch');

            // Step 3: Flush cache
            $profiler->start('Cache Flush');
            $magentoRunner->run('cache:flush', [], 60, false);
            $profiler->stop('Cache Flush');

            // Step 4: Disable maintenance
            $profiler->start('Maintenance Disable');
            $magentoRunner->run('maintenance:disable', [], 30, false);
            $profiler->stop('Maintenance Disable');

            $logger->separator();
            $logger->line($profiler->formatReport());
            $logger->success("Rolled back to: " . basename($targetRelease));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $logger->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
