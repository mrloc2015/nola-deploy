<?php

declare(strict_types=1);

namespace Nola\Deploy\Command;

use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;

/**
 * Shared gate for deploy commands — ensures .nola-deploy.yaml exists.
 * If not, tells the user to run `nola-deploy init` first.
 */
trait RequiresConfigTrait
{
    /**
     * @return bool true if config exists and deployment can proceed
     */
    private function requireConfig(ConfigLoader $config, Logger $logger): bool
    {
        if ($config->hasUserConfig()) {
            return true;
        }

        $filename = ConfigLoader::getConfigFilename();
        $logger->separator();
        $logger->warning("No {$filename} found.");
        $logger->line('');
        $logger->info('  Run this first to set up your deployment config:');
        $logger->line('');
        $logger->line('    nola-deploy init');
        $logger->line('');
        $logger->info('  This will:');
        $logger->info('    1. Check your environment (PHP, DB, extensions)');
        $logger->info('    2. Auto-detect stores, themes, and locales');
        $logger->info('    3. Generate ' . $filename . ' for you to review');
        $logger->line('');
        $logger->info('  After reviewing, run: nola-deploy deploy');
        $logger->separator();

        return false;
    }
}
