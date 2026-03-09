<?php

declare(strict_types=1);

namespace Nola\Deploy\Command;

use Nola\Deploy\Deployer\ArtifactDeployer;
use Nola\Deploy\Deployer\ReleaseManager;
use Nola\Deploy\Deployer\SymlinkSwitcher;
use Nola\Deploy\Health\CacheWarmer;
use Nola\Deploy\Health\HealthChecker;
use Nola\Deploy\Runner\MagentoRunner;
use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;
use Nola\Deploy\Util\Profiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'deploy:artifact', description: 'Deploy a pre-built artifact to production')]
class DeployArtifactCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('artifact', 'a', InputOption::VALUE_REQUIRED, 'Path to artifact tar.gz')
            ->addOption('current-link', null, InputOption::VALUE_REQUIRED, 'Path to current symlink', 'current')
            ->addOption('skip-upgrade', null, InputOption::VALUE_NONE, 'Skip setup:upgrade')
            ->addOption('skip-health-check', null, InputOption::VALUE_NONE, 'Skip health check')
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Magento root directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new Logger($output);
        $profiler = new Profiler();

        $logger->banner('nola-deploy artifact deployment');

        try {
            $artifactPath = $input->getOption('artifact');
            if (!$artifactPath) {
                $logger->error('--artifact is required');
                return Command::FAILURE;
            }

            $config = (new ConfigLoader())->load($input->getOption('magento-root'));
            $releasesDir = $config->getReleasesDir();
            $currentLink = $input->getOption('current-link');

            if (!str_starts_with($currentLink, '/')) {
                $currentLink = dirname($releasesDir) . '/' . $currentLink;
            }

            // Step 1: Extract artifact
            $profiler->start('Extract Artifact');
            $deployer = new ArtifactDeployer($config, $logger);
            $releaseDir = $deployer->deploy($artifactPath);
            $profiler->stop('Extract Artifact');

            // Step 2: Maintenance mode
            $profiler->start('Maintenance Enable');
            $magentoRunner = new MagentoRunner($config, $logger);
            $magentoRunner->run('maintenance:enable', [], 30, false);
            $profiler->stop('Maintenance Enable');

            // Step 3: setup:upgrade
            if (!$input->getOption('skip-upgrade')) {
                $profiler->start('setup:upgrade');
                $magentoRunner->run('setup:upgrade', ['--keep-generated'], 300, false);
                $profiler->stop('setup:upgrade');
            }

            // Step 4: Symlink switch
            $profiler->start('Symlink Switch');
            $switcher = new SymlinkSwitcher();
            $switcher->switch($releaseDir, $currentLink);
            $logger->success("Symlink switched: {$currentLink} -> {$releaseDir}");
            $profiler->stop('Symlink Switch');

            // Step 5: Cache flush
            $profiler->start('Cache Flush');
            $magentoRunner->run('cache:flush', [], 60, false);
            $profiler->stop('Cache Flush');

            // Step 6: Disable maintenance
            $profiler->start('Maintenance Disable');
            $magentoRunner->run('maintenance:disable', [], 30, false);
            $profiler->stop('Maintenance Disable');

            // Step 7: Health check
            if (!$input->getOption('skip-health-check') && $config->isHealthCheckEnabled()) {
                $profiler->start('Health Check');
                $healthChecker = new HealthChecker();
                $baseUrl = $this->getBaseUrl($config);
                $results = $healthChecker->check($config->getHealthCheckUrls(), $baseUrl);

                $allPassed = $healthChecker->allPassed($results);
                foreach ($results as $r) {
                    $status = $r->passed ? 'OK' : 'FAIL';
                    $logger->line("[{$status}] {$r->url} ({$r->statusCode}, {$r->responseTime}s)");
                }
                $profiler->stop('Health Check');

                // Auto-rollback on failure
                if (!$allPassed && $config->isAutoRollbackEnabled()) {
                    $logger->error('Health check failed! Auto-rolling back...');
                    $releaseManager = new ReleaseManager($releasesDir, $currentLink);
                    $previous = $releaseManager->getPreviousRelease();
                    if ($previous) {
                        $switcher->rollback($previous, $currentLink);
                        $magentoRunner->run('cache:flush', [], 60, false);
                        $logger->warning("Rolled back to: {$previous}");
                    }
                    return Command::FAILURE;
                }
            }

            // Step 8: Cache warmup
            if ($config->isCacheWarmupEnabled()) {
                $profiler->start('Cache Warmup');
                $warmer = new CacheWarmer($logger);
                $warmer->warm($config->getCacheWarmupUrls(), $this->getBaseUrl($config));
                $profiler->stop('Cache Warmup');
            }

            // Step 9: Cleanup old releases
            $releaseManager = new ReleaseManager($releasesDir, $currentLink);
            $removed = $releaseManager->cleanup($config->getReleasesKeep());
            if (!empty($removed)) {
                $logger->info('Cleaned up ' . count($removed) . ' old release(s)');
            }

            // Report
            $logger->separator();
            $logger->line($profiler->formatReport());
            $logger->success('Artifact deployment completed!');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $logger->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function getBaseUrl(ConfigLoader $config): string
    {
        $envConfig = $config->getMagentoEnvConfig();
        // Try to get base URL from env.php or config
        return $config->get('base_url', '');
    }
}
