<?php

declare(strict_types=1);

namespace Nola\Deploy\Command;

use Nola\Deploy\Analyzer\LocaleDetector;
use Nola\Deploy\Analyzer\ThemeDetector;
use Nola\Deploy\Util\BinaryDetector;
use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;
use Nola\Deploy\Util\Manifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'status', description: 'Show deployment status and environment info')]
class StatusCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Magento root directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new Logger($output);
        $logger->banner('nola-deploy status');

        try {
            $config = (new ConfigLoader())->load($input->getOption('magento-root'));
            $root = $config->getMagentoRoot();

            // Environment
            $logger->step('Environment');
            $binaryDetector = new BinaryDetector($root);
            $binaries = $binaryDetector->detectAll();

            $logger->info("Magento root: {$root}");
            $logger->info("PHP: {$binaries['php']['version']}");
            $logger->info("CPU cores: {$binaryDetector->getCpuCores()} (optimal workers: {$binaryDetector->getOptimalWorkers()})");

            foreach (['node', 'lessc', 'go_deployer', 'magepack'] as $bin) {
                $status = $binaries[$bin]['available'] ? 'available' : 'not found';
                $version = $binaries[$bin]['version'] ? " ({$binaries[$bin]['version']})" : '';
                $icon = $binaries[$bin]['available'] ? '  +' : '  -';
                $logger->line("{$icon} {$bin}: {$status}{$version}");
            }

            // Themes
            $logger->step('Themes');
            $themeDetector = new ThemeDetector($config);
            $themes = $themeDetector->detectAll();

            $excluded = $config->getExcludedThemes();
            foreach ($themes as $theme) {
                $isExcluded = in_array($theme->code, $excluded, true);
                $status = $isExcluded ? ' (excluded)' : '';
                $type = $theme->isHyva ? 'Hyva' : 'Luma';
                $logger->line("  {$theme->code} [{$type}] ({$theme->area}){$status}");
            }

            // Locales
            $logger->step('Locales');
            $localeDetector = new LocaleDetector($config);
            $locales = $localeDetector->detect();
            $logger->info(implode(', ', $locales));

            // Last deployment
            $logger->step('Last Deployment');
            $manifest = (new Manifest($root))->load();

            if ($manifest->exists()) {
                $logger->info("Date: {$manifest->getLastDeployTime()}");
                $logger->info("Git commit: {$manifest->getLastGitCommit()}");
                $logger->info("Duration: {$manifest->getLastDuration()}s");
                $logger->info("Themes: " . implode(', ', $manifest->getDeployedThemes()));
                $logger->info("Locales: " . implode(', ', $manifest->getDeployedLocales()));
            } else {
                $logger->warning('No previous deployment recorded');
            }

            // Config
            $logger->step('Configuration');
            if ($config->hasUserConfig()) {
                $logger->info("Config file: {$config->getConfigPath()}");
            } else {
                $filename = ConfigLoader::getConfigFilename();
                $logger->warning("No {$filename} found — run 'nola-deploy init' to generate");
            }

            $logger->info("SCD strategy: {$config->getScdStrategy()}");
            $logger->info("Parallel jobs: {$config->getParallelJobs()}");
            $logger->info("Node.js LESS: " . ($config->useNodeLess() ? 'enabled' : 'disabled'));
            $logger->info("Go deployer: " . ($config->useGoDeployer() ? 'enabled' : 'disabled'));
            $logger->info("DI cache: " . ($config->isDiCacheEnabled() ? 'enabled' : 'disabled'));
            $logger->info("Auto-rollback: " . ($config->isAutoRollbackEnabled() ? 'enabled' : 'disabled'));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $logger->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
