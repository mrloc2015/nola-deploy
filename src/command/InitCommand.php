<?php

declare(strict_types=1);

namespace Nola\Deploy\Command;

use Nola\Deploy\Analyzer\StoreMapper;
use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'init', description: 'Auto-detect environment, verify prerequisites, and generate .nola-deploy.yaml')]
class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing config')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show config without writing')
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Magento root directory')
            ->setHelp(<<<'HELP'
Auto-detects your Magento environment and generates .nola-deploy.yaml.

This command:
  1. Verifies prerequisites (PHP, DB connection, extensions, disk space)
  2. Queries the database for store → theme → locale mapping
  3. Generates a commented YAML config file

Usage:
  <info>nola-deploy init</info>              # Generate .nola-deploy.yaml
  <info>nola-deploy init --dry-run</info>    # Preview without writing
  <info>nola-deploy init --force</info>      # Overwrite existing config
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new Logger($output);
        $logger->banner('nola-deploy init');

        try {
            $config = (new ConfigLoader())->load($input->getOption('magento-root'));
            $root = $config->getMagentoRoot();
            $configPath = $config->getConfigPath();
            $filename = ConfigLoader::getConfigFilename();

            if (file_exists($configPath) && !$input->getOption('force') && !$input->getOption('dry-run')) {
                $logger->warning("{$filename} already exists at: {$configPath}");
                $logger->info('Use --force to overwrite, or edit it manually.');
                return Command::SUCCESS;
            }

            // Step 1: Health check
            $logger->step('Checking environment');
            $healthy = $this->runHealthChecks($config, $logger);

            // Step 2: Detect store mapping
            $logger->step('Detecting store configuration');
            $storeMapper = new StoreMapper($config);
            $stores = $storeMapper->buildMapping();

            $logger->info('Found ' . (count($stores) - 1) . ' store view(s) + admin');
            foreach ($stores as $code => $storeConfig) {
                $locales = implode(', ', $storeConfig['locales']);
                $logger->info("  {$code}: {$storeConfig['theme']} [{$locales}]");
            }

            // Step 3: Build YAML content with comments
            $yamlContent = $this->buildYamlConfig($config, $stores);

            if ($input->getOption('dry-run')) {
                $logger->step('Generated config (dry-run)');
                $logger->line($yamlContent);
                if (!$healthy) {
                    $logger->warning('Some health checks failed — review above before deploying.');
                }
                return Command::SUCCESS;
            }

            // Write config file
            file_put_contents($configPath, $yamlContent);
            $logger->separator();
            $logger->success("Config written to: {$configPath}");
            $logger->info('Review and adjust the file, then run: nola-deploy deploy');

            if (!$healthy) {
                $logger->warning('Some health checks failed — fix them before deploying.');
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $logger->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Verify all prerequisites needed before deployment.
     * @return bool true if all critical checks pass
     */
    private function runHealthChecks(ConfigLoader $config, Logger $logger): bool
    {
        $allPassed = true;

        // PHP version
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.1.0', '>=');
        $this->logCheck($logger, $phpOk, "PHP {$phpVersion}", 'Requires PHP 8.1+');
        if (!$phpOk) {
            $allPassed = false;
        }

        // bin/magento
        $root = $config->getMagentoRoot();
        $magentoOk = file_exists($root . '/bin/magento') && is_executable($root . '/bin/magento');
        $this->logCheck($logger, $magentoOk, 'bin/magento', 'Magento CLI not found or not executable');
        if (!$magentoOk) {
            $allPassed = false;
        }

        // app/etc/env.php
        $envOk = file_exists($root . '/app/etc/env.php');
        $this->logCheck($logger, $envOk, 'app/etc/env.php', 'Magento not installed — run setup:install first');
        if (!$envOk) {
            $allPassed = false;
        }

        // app/etc/config.php
        $configOk = file_exists($root . '/app/etc/config.php');
        $this->logCheck($logger, $configOk, 'app/etc/config.php', 'Module config missing');
        if (!$configOk) {
            $allPassed = false;
        }

        // Required PHP extensions
        $requiredExts = ['pdo_mysql', 'intl', 'mbstring', 'json', 'dom', 'simplexml'];
        $missingExts = array_filter($requiredExts, fn($ext) => !extension_loaded($ext));
        $extOk = empty($missingExts);
        if ($extOk) {
            $this->logCheck($logger, true, 'PHP extensions (' . count($requiredExts) . ' required)');
        } else {
            $this->logCheck($logger, false, 'PHP extensions', 'Missing: ' . implode(', ', $missingExts));
            $allPassed = false;
        }

        // DB connection
        $dbOk = false;
        try {
            $envConfig = $config->getMagentoEnvConfig();
            $dbConfig = $envConfig['db']['connection']['default'] ?? null;
            if ($dbConfig) {
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;port=%s',
                    $dbConfig['host'] ?? 'localhost',
                    $dbConfig['dbname'] ?? '',
                    $dbConfig['port'] ?? '3306',
                );
                $pdo = new \PDO($dsn, $dbConfig['username'] ?? '', $dbConfig['password'] ?? '');
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $pdo->query('SELECT 1');
                $dbOk = true;
            }
        } catch (\Throwable) {
            // DB connection failed
        }
        $this->logCheck($logger, $dbOk, 'Database connection', 'Cannot connect — check app/etc/env.php');
        if (!$dbOk) {
            $allPassed = false;
        }

        // Disk space (warn if < 1GB free)
        $freeBytes = @disk_free_space($root);
        if ($freeBytes !== false) {
            $freeGb = round($freeBytes / 1024 / 1024 / 1024, 1);
            $diskOk = $freeGb >= 1.0;
            $this->logCheck($logger, $diskOk, "Disk space ({$freeGb} GB free)", 'Low disk space — may fail during deploy');
            if (!$diskOk) {
                $allPassed = false;
            }
        }

        // Composer vendor
        $vendorOk = file_exists($root . '/vendor/autoload.php');
        $this->logCheck($logger, $vendorOk, 'Composer vendor', 'Run "composer install" first');
        if (!$vendorOk) {
            $allPassed = false;
        }

        // OPcache detection (informational — not a pass/fail check)
        $this->detectOpcache($logger);

        return $allPassed;
    }

    /**
     * Detect OPcache status and log informational note.
     * Not a pass/fail check — just awareness for PHP code deployments.
     */
    private function detectOpcache(Logger $logger): void
    {
        if (!function_exists('opcache_get_status')) {
            $logger->info('  [--] OPcache not available');
            return;
        }

        $status = @opcache_get_status(false);
        if ($status === false || !($status['opcache_enabled'] ?? false)) {
            $logger->info('  [--] OPcache disabled');
            return;
        }

        $scripts = $status['opcache_statistics']['num_cached_scripts'] ?? 0;
        $memory = round(($status['memory_usage']['used_memory'] ?? 0) / 1024 / 1024, 1);
        $logger->info("  [OK] OPcache enabled ({$scripts} cached scripts, {$memory} MB)");
        $logger->info('       Note: After PHP/DI code changes, restart PHP-FPM to clear bytecode cache');
    }

    private function logCheck(Logger $logger, bool $pass, string $label, string $failMsg = ''): void
    {
        if ($pass) {
            $logger->info("  [OK] {$label}");
        } else {
            $logger->warning("  [!!] {$label} — {$failMsg}");
        }
    }

    /**
     * Build a YAML config string with helpful comments.
     */
    private function buildYamlConfig(ConfigLoader $config, array $stores): string
    {
        $lines = [];
        $lines[] = '# =============================================================================';
        $lines[] = '# nola-deploy configuration';
        $lines[] = '# Generated by: nola-deploy init';
        $lines[] = '# Docs: https://github.com/nola/deploy';
        $lines[] = '# =============================================================================';
        $lines[] = '';

        // Stores section (most important — users will edit this)
        $lines[] = '# --- Store Mapping -----------------------------------------------------------';
        $lines[] = '# Each store view maps to a theme + locales. Detected from your database.';
        $lines[] = '# Edit this to match your production storefronts.';
        $lines[] = 'stores:';
        foreach ($stores as $code => $storeConfig) {
            $lines[] = "  {$code}:";
            $lines[] = "    theme: {$storeConfig['theme']}";
            $lines[] = '    locales:';
            foreach ($storeConfig['locales'] as $locale) {
                $lines[] = "      - {$locale}";
            }
        }
        $lines[] = '';

        // Static content
        $lines[] = '# --- Static Content Deploy ---------------------------------------------------';
        $lines[] = 'static_content:';
        $lines[] = '  strategy: quick      # quick | standard | compact';
        $lines[] = '  parallel_jobs: 4';
        $lines[] = '  use_node_less: true   # Use Node.js LESS compiler (faster than PHP)';
        $lines[] = '  use_go_deployer: true';
        $lines[] = '';

        // DI compile
        $lines[] = '# --- DI Compilation ----------------------------------------------------------';
        $lines[] = 'di_compile:';
        $lines[] = '  enabled: true';
        $lines[] = '  cache: true           # Cache compiled DI for incremental builds';
        $lines[] = '  gc_disable: true      # Disable GC during compile (~30% faster)';
        $lines[] = '';

        // Cache
        $lines[] = '# --- Cache Control -----------------------------------------------------------';
        $lines[] = '# flush_all: true flushes everything. Set false + list specific types.';
        $lines[] = 'cache:';
        $lines[] = '  flush_all: true';
        $lines[] = '  types: []';
        $lines[] = '  # types:';
        $lines[] = '  #   - config';
        $lines[] = '  #   - layout';
        $lines[] = '  #   - block_html';
        $lines[] = '  #   - full_page';
        $lines[] = '';

        // Maintenance
        $lines[] = '# --- Maintenance Page --------------------------------------------------------';
        $lines[] = '# Path to your custom HTML file (relative to Magento root).';
        $lines[] = '# Create a single HTML file — nola-deploy handles the rest.';
        $lines[] = '# If file not found, nola-deploy uses its built-in template.';
        $lines[] = 'maintenance:';
        $lines[] = '  page: nola-deploy-maintenance.html';
        $lines[] = '';

        // Post-deploy
        $lines[] = '# --- Post-Deploy Commands ----------------------------------------------------';
        $lines[] = '# Shell commands to run after deployment (from Magento root).';
        $lines[] = 'post_deploy: []';
        $lines[] = '# post_deploy:';
        $lines[] = '#   - bin/magento indexer:reindex';
        $lines[] = '#   - bin/magento cache:clean full_page';
        $lines[] = '';

        // PHP settings
        $lines[] = '# --- PHP Settings ------------------------------------------------------------';
        $lines[] = 'php_binary: php';
        $lines[] = 'memory_limit: "-1"';
        $lines[] = '';

        // Health check
        $lines[] = '# --- Health Check (post-deploy verification) ---------------------------------';
        $lines[] = 'health_check:';
        $lines[] = '  enabled: true';
        $lines[] = '  urls:';
        $lines[] = '    - /';
        $lines[] = '  timeout: 10';
        $lines[] = '';

        // Cache warmup
        $lines[] = '# --- Cache Warmup ------------------------------------------------------------';
        $lines[] = 'cache_warmup:';
        $lines[] = '  enabled: true';
        $lines[] = '  urls:';
        $lines[] = '    - /';
        $lines[] = '  concurrency: 4';
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }
}
