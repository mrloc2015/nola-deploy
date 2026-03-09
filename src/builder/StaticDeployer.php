<?php

declare(strict_types=1);

namespace Nola\Deploy\Builder;

use Nola\Deploy\Analyzer\ThemeInfo;
use Nola\Deploy\Runner\MagentoRunner;
use Nola\Deploy\Runner\ParallelRunner;
use Nola\Deploy\Runner\Task;
use Nola\Deploy\Runner\TaskResult;
use Nola\Deploy\Util\BinaryDetector;
use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;

class StaticDeployer
{
    public function __construct(
        private ConfigLoader $config,
        private MagentoRunner $magentoRunner,
        private BinaryDetector $binaryDetector,
        private Logger $logger,
    ) {
    }

    /**
     * Deploy static content for given themes and locales.
     *
     * @param ThemeInfo[] $themes
     * @param string[] $locales
     * @return TaskResult[]
     */
    public function deploy(array $themes, array $locales): array
    {
        $this->logger->step('Static Content Deployment');

        if (empty($themes)) {
            $this->logger->warning('No themes to deploy');
            return [];
        }

        if (empty($locales)) {
            $locales = ['en_US'];
        }

        // Separate Hyva and Luma themes
        $hyvaThemes = array_filter($themes, fn(ThemeInfo $t) => $t->isHyva);
        $lumaThemes = array_filter($themes, fn(ThemeInfo $t) => !$t->isHyva);

        $results = [];

        // Deploy Hyva themes with Go binary if available
        if (!empty($hyvaThemes)) {
            $results = array_merge($results, $this->deployHyvaThemes($hyvaThemes, $locales));
        }

        // Deploy Luma themes with parallel native SCD
        if (!empty($lumaThemes)) {
            $results = array_merge($results, $this->deployLumaThemes($lumaThemes, $locales));
        }

        return $results;
    }

    /** @return TaskResult[] */
    private function deployHyvaThemes(array $themes, array $locales): array
    {
        if ($this->config->useGoDeployer() && $this->binaryDetector->hasGoDeployer()) {
            $this->logger->info('Using Go deployer for Hyva themes (300x faster)');
            return $this->deployWithGo($themes, $locales);
        }

        $this->logger->info('Go deployer not available, using native SCD for Hyva themes');
        return $this->deployWithNativeScd($themes, $locales);
    }

    /** @return TaskResult[] */
    private function deployLumaThemes(array $themes, array $locales): array
    {
        $count = count($themes);
        $jobs = $this->config->getParallelJobs();
        $this->logger->info("{$count} Luma theme(s), {$jobs} parallel workers");

        // Sort themes by dependency: parent themes must deploy before children
        $ordered = $this->sortByDependency($themes);

        // Group into dependency levels: themes in same level can run in parallel
        $levels = $this->groupByDependencyLevel($ordered);

        $results = [];
        foreach ($levels as $levelThemes) {
            if (count($levelThemes) === 1 || $jobs <= 1) {
                $results = array_merge($results, $this->deployWithNativeScd($levelThemes, $locales));
            } else {
                $results = array_merge($results, $this->deployWithParallelScd($levelThemes, $locales));
            }

            // Check for failures before proceeding to next level
            foreach ($results as $result) {
                if (!$result->success) {
                    return $results;
                }
            }
        }

        return $results;
    }

    /**
     * Sort themes so parents deploy before children.
     *
     * @param ThemeInfo[] $themes
     * @return ThemeInfo[]
     */
    private function sortByDependency(array $themes): array
    {
        $themeMap = [];
        foreach ($themes as $theme) {
            $themeMap[$theme->code] = $theme;
        }

        $sorted = [];
        $visited = [];

        $visit = function (ThemeInfo $theme) use (&$visit, &$sorted, &$visited, $themeMap): void {
            if (isset($visited[$theme->code])) {
                return;
            }
            $visited[$theme->code] = true;

            // Visit parent first if it's in our deploy list
            if ($theme->parentTheme && isset($themeMap[$theme->parentTheme])) {
                $visit($themeMap[$theme->parentTheme]);
            }

            $sorted[] = $theme;
        };

        foreach ($themes as $theme) {
            $visit($theme);
        }

        return $sorted;
    }

    /**
     * Group themes into dependency levels. Themes in the same level have no
     * parent-child relationships between them and can deploy in parallel.
     *
     * @param ThemeInfo[] $sortedThemes
     * @return ThemeInfo[][]
     */
    private function groupByDependencyLevel(array $sortedThemes): array
    {
        $themeSet = [];
        foreach ($sortedThemes as $theme) {
            $themeSet[$theme->code] = true;
        }

        $levels = [];
        $assigned = [];

        foreach ($sortedThemes as $theme) {
            $level = 0;
            // If parent is in our set, put this in parent's level + 1
            if ($theme->parentTheme && isset($themeSet[$theme->parentTheme]) && isset($assigned[$theme->parentTheme])) {
                $level = $assigned[$theme->parentTheme] + 1;
            }
            $assigned[$theme->code] = $level;
            $levels[$level][] = $theme;
        }

        ksort($levels);
        return array_values($levels);
    }

    /** @return TaskResult[] */
    private function deployWithGo(array $themes, array $locales): array
    {
        $goBin = $this->binaryDetector->getGoDeployerPath();
        $runner = new ParallelRunner($this->config->getParallelJobs());

        foreach ($themes as $theme) {
            $cmd = [$goBin, '--area', $theme->area, '--theme', $theme->code];
            $cmd[] = '--jobs';
            $cmd[] = (string) $this->config->getParallelJobs();
            $cmd = array_merge($cmd, $locales);

            $runner->addTask(new Task(
                label: "Go SCD: {$theme->code} ({$theme->area})",
                command: $cmd,
                workingDir: $this->config->getMagentoRoot(),
                timeout: 120,
            ));
        }

        return $runner->run($this->logger);
    }

    /** @return TaskResult[] */
    private function deployWithParallelScd(array $themes, array $locales): array
    {
        $runner = new ParallelRunner($this->config->getParallelJobs());
        $phpBin = $this->config->getPhpBinary();
        $memLimit = $this->config->getMemoryLimit();
        $root = $this->config->getMagentoRoot();
        $strategy = $this->config->getScdStrategy();

        foreach ($themes as $theme) {
            $cmd = [
                $phpBin, '-d', "memory_limit={$memLimit}",
                "{$root}/bin/magento", 'setup:static-content:deploy',
                '--area=' . $theme->area,
                '--theme=' . $theme->code,
                '--strategy=' . $strategy,
                '-f',
            ];
            $cmd = array_merge($cmd, $locales);

            $runner->addTask(new Task(
                label: "SCD: {$theme->code} ({$theme->area})",
                command: $cmd,
                workingDir: $root,
                timeout: 600,
            ));
        }

        return $runner->run($this->logger);
    }

    /** @return TaskResult[] */
    private function deployWithNativeScd(array $themes, array $locales): array
    {
        $strategy = $this->config->getScdStrategy();
        $args = ['-f', '--strategy=' . $strategy];

        // Add theme filters
        foreach ($themes as $theme) {
            $args[] = '--theme=' . $theme->code;
        }

        // Add locales
        $args = array_merge($args, $locales);

        $result = $this->magentoRunner->run('setup:static-content:deploy', $args, 1200);
        return [$result];
    }
}
