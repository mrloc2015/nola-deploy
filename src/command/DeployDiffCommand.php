<?php

declare(strict_types=1);

namespace Nola\Deploy\Command;

use Nola\Deploy\Analyzer\ChangeAnalyzer;
use Nola\Deploy\Analyzer\ChangeDetector;
use Nola\Deploy\Analyzer\LocaleDetector;
use Nola\Deploy\Analyzer\ThemeDetector;
use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;
use Nola\Deploy\Util\Manifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'deploy:diff', description: 'Show what changed since last deploy and what steps would run')]
class DeployDiffCommand extends Command
{
    use RequiresConfigTrait;
    protected function configure(): void
    {
        $this
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Path to Magento root directory')
            ->setHelp(<<<'HELP'
Shows detailed diff of what changed since the last deployment.

Groups changes by category and tells you exactly which deploy steps
would run if you deployed now.

Usage:
  <info>nola-deploy deploy:diff</info>     # Show changes and what would happen
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new Logger($output);
        $logger->banner('nola-deploy — Deployment Diff');

        try {
            $config = (new ConfigLoader())->load($input->getOption('magento-root'));

            if (!$this->requireConfig($config, $logger)) {
                return Command::FAILURE;
            }

            $root = $config->getMagentoRoot();
            $logger->info("Magento root: {$root}");

            // Load manifest
            $manifest = (new Manifest($root))->load();
            if (!$manifest->exists()) {
                $logger->warning('No previous deployment recorded.');
                $logger->info('Running "nola-deploy deploy" will do a full rebuild.');
                $logger->info('Running "nola-deploy deploy:fresh" will clean + full deploy.');
                return Command::SUCCESS;
            }

            // Show last deploy info
            $logger->step('Last Deployment');
            $logger->info("Date:    {$manifest->getLastDeployTime()}");
            $logger->info("Commit:  {$manifest->getLastGitCommit()}");
            $logger->info("Themes:  " . implode(', ', $manifest->getDeployedThemes()));
            $logger->info("Locales: " . implode(', ', $manifest->getDeployedLocales()));

            // Detect changes
            $changeDetector = new ChangeDetector($root, $manifest);
            $changes = $changeDetector->detect();

            if (!$changes->hasAnyChanges()) {
                $logger->separator();
                $logger->success('No changes since last deploy. Nothing to do.');
                return Command::SUCCESS;
            }

            // Show changed files by category
            $logger->step('Changes Detected');

            if ($changes->isFullRebuild) {
                $logger->warning('Full rebuild required (manifest missing or invalid)');
            } else {
                $this->showFileGroup($logger, 'PHP files', $changes->phpFiles, $root);
                $this->showFileGroup($logger, 'DI config (di.xml)', $changes->diXmlFiles, $root);
                $this->showFileGroup($logger, 'Theme files', $changes->themeFiles, $root);
                $this->showFileGroup($logger, 'Static/JS/CSS/LESS', $changes->staticFiles, $root);
                $this->showFileGroup($logger, 'Magento config', $changes->configFiles, $root);
                $this->showFileGroup($logger, 'Composer', $changes->composerFiles, $root);
                $this->showFileGroup($logger, 'DB schema/patches', $changes->dbSchemaFiles, $root);

                // Show files that don't fit any category
                $categorized = array_merge(
                    $changes->phpFiles,
                    $changes->diXmlFiles,
                    $changes->themeFiles,
                    $changes->staticFiles,
                    $changes->configFiles,
                    $changes->composerFiles,
                    $changes->dbSchemaFiles,
                );
                $other = array_diff($changes->allFiles, $categorized);
                $this->showFileGroup($logger, 'Other files', $other, $root);

                $logger->info(count($changes->allFiles) . ' file(s) changed total');
            }

            // Show deleted files (git status)
            $this->showDeletedFiles($root, $manifest->getLastGitCommit(), $logger);

            // Show what deploy steps would run
            $analyzer = new ChangeAnalyzer();
            $themeDetector = new ThemeDetector($config);
            $themes = $themeDetector->detect();
            $themeCodes = array_map(fn($t) => $t->code, $themes);

            $logger->step('Deploy Steps Required');

            $steps = [];
            if ($analyzer->needsSetupUpgrade($changes)) {
                $steps[] = ['setup:upgrade', 'DB schema/patch changes detected'];
            }
            if ($analyzer->needsDiCompile($changes)) {
                $steps[] = ['setup:di:compile', 'PHP or DI config changes detected'];
            }
            if ($analyzer->needsStaticDeploy($changes)) {
                $changedThemes = $analyzer->getChangedThemes($changes, $themeCodes);
                $themeList = empty($changedThemes) ? 'all themes' : implode(', ', $changedThemes);
                $steps[] = ['setup:static-content:deploy', "Theme/static changes → {$themeList}"];
            }
            $steps[] = ['cache:flush', 'Always runs after deploy'];

            foreach ($steps as [$cmd, $reason]) {
                $logger->info("  ▸ {$cmd}");
                $logger->line("    {$reason}");
            }

            // Show what would NOT run
            $logger->step('Steps Skipped');
            if (!$analyzer->needsSetupUpgrade($changes)) {
                $logger->info('  ✓ setup:upgrade (no DB changes)');
            }
            if (!$analyzer->needsDiCompile($changes)) {
                $logger->info('  ✓ setup:di:compile (no PHP/DI changes)');
            }
            if (!$analyzer->needsStaticDeploy($changes)) {
                $logger->info('  ✓ setup:static-content:deploy (no theme/static changes)');
            }

            // Recommendation
            $logger->separator();
            if ($changes->isFullRebuild) {
                $logger->info('Recommended: nola-deploy deploy:fresh');
            } else {
                $logger->info('Recommended: nola-deploy deploy');
                $logger->info('This will only run the required steps listed above.');
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $logger->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function showFileGroup(Logger $logger, string $label, array $files, string $root): void
    {
        if (empty($files)) {
            return;
        }

        $logger->info("  {$label} (" . count($files) . "):");
        $max = 10;
        $shown = 0;
        foreach ($files as $file) {
            if ($shown >= $max) {
                $remaining = count($files) - $max;
                $logger->line("    ... and {$remaining} more");
                break;
            }
            $logger->line("    {$file}");
            $shown++;
        }
    }

    private function showDeletedFiles(string $root, ?string $lastCommit, Logger $logger): void
    {
        if (!$lastCommit) {
            return;
        }

        $isGit = trim((string) @shell_exec(
            "cd " . escapeshellarg($root) . " && git rev-parse --is-inside-work-tree 2>/dev/null"
        ));
        if ($isGit !== 'true') {
            return;
        }

        // Get deleted files
        $deleted = trim((string) @shell_exec(
            "cd " . escapeshellarg($root)
            . " && git diff " . escapeshellarg($lastCommit) . "..HEAD --diff-filter=D --name-only 2>/dev/null"
        ));

        if ($deleted === '') {
            return;
        }

        $files = explode("\n", $deleted);
        $logger->step('Deleted Files (' . count($files) . ')');
        $max = 10;
        $shown = 0;
        foreach ($files as $file) {
            if ($shown >= $max) {
                $remaining = count($files) - $max;
                $logger->line("    ... and {$remaining} more");
                break;
            }
            $logger->line("    - {$file}");
            $shown++;
        }
    }
}
