<?php

declare(strict_types=1);

namespace Nola\Deploy\Command;

use Nola\Deploy\Analyzer\LocaleDetector;
use Nola\Deploy\Analyzer\ThemeDetector;
use Nola\Deploy\Builder\DiCompiler;
use Nola\Deploy\Builder\StaticDeployer;
use Nola\Deploy\Deployer\ArtifactBuilder;
use Nola\Deploy\Runner\MagentoRunner;
use Nola\Deploy\Util\BinaryDetector;
use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;
use Nola\Deploy\Util\Profiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'build', description: 'Build deployment artifact (for CI/CD)')]
class BuildCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output artifact path', 'artifact.tar.gz')
            ->addOption('skip-di', null, InputOption::VALUE_NONE, 'Skip DI compilation')
            ->addOption('skip-static', null, InputOption::VALUE_NONE, 'Skip static content deployment')
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Magento root directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new Logger($output);
        $profiler = new Profiler();

        $logger->banner('nola-deploy build');

        try {
            $config = (new ConfigLoader())->load($input->getOption('magento-root'));
            $root = $config->getMagentoRoot();
            $outputPath = $input->getOption('output');

            // Make output path absolute
            if (!str_starts_with($outputPath, '/')) {
                $outputPath = $root . '/' . $outputPath;
            }

            $magentoRunner = new MagentoRunner($config, $logger);
            $binaryDetector = new BinaryDetector($root);

            // Validate vendor directory
            if (!is_dir($root . '/vendor')) {
                $logger->error('vendor/ not found. Run "composer install" first.');
                return Command::FAILURE;
            }

            // Step 1: DI Compilation
            if (!$input->getOption('skip-di')) {
                $profiler->start('DI Compilation');
                $diCompiler = new DiCompiler($magentoRunner, $logger);
                $result = $diCompiler->compile();
                $profiler->stop('DI Compilation');

                if (!$result->success) {
                    $logger->error('DI compilation failed');
                    return Command::FAILURE;
                }
            }

            // Step 2: Static Content Deploy
            if (!$input->getOption('skip-static')) {
                $profiler->start('Static Content Deploy');

                $themes = (new ThemeDetector($config))->detect();
                $locales = (new LocaleDetector($config))->detect();

                $staticDeployer = new StaticDeployer($config, $magentoRunner, $binaryDetector, $logger);
                $results = $staticDeployer->deploy($themes, $locales);

                foreach ($results as $result) {
                    if (!$result->success) {
                        $logger->error("Static deploy failed: {$result->label}");
                        $profiler->stop('Static Content Deploy');
                        return Command::FAILURE;
                    }
                }

                $profiler->stop('Static Content Deploy');
            }

            // Step 3: Create Artifact
            $profiler->start('Create Artifact');
            $artifactBuilder = new ArtifactBuilder($config, $logger);
            $artifactBuilder->build($outputPath);
            $profiler->stop('Create Artifact');

            // Report
            $logger->separator();
            $logger->line($profiler->formatReport());
            $logger->success("Build complete: {$outputPath}");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $logger->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
