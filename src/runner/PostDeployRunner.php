<?php

declare(strict_types=1);

namespace Nola\Deploy\Runner;

use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;
use Symfony\Component\Process\Process;

class PostDeployRunner
{
    public function __construct(
        private ConfigLoader $config,
        private Logger $logger,
    ) {
    }

    /**
     * Run all configured post-deploy commands.
     * Continues on failure — one failing command doesn't block the others.
     *
     * @return array{success: int, failed: int}
     */
    public function run(): array
    {
        $commands = $this->config->getPostDeployCommands();
        if (empty($commands)) {
            return ['success' => 0, 'failed' => 0];
        }

        $this->logger->step('Running post-deploy commands');
        $root = $this->config->getMagentoRoot();
        $success = 0;
        $failed = 0;

        foreach ($commands as $cmd) {
            $this->logger->info("  ▸ {$cmd}");
            $process = Process::fromShellCommandline($cmd, $root);
            $process->setTimeout(120);

            try {
                $process->run();
                if ($process->isSuccessful()) {
                    $success++;
                    $output = trim($process->getOutput());
                    if ($output !== '') {
                        $this->logger->info("    {$output}");
                    }
                } else {
                    $failed++;
                    $this->logger->warning("    Command failed: {$process->getErrorOutput()}");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->warning("    Error: {$e->getMessage()}");
            }
        }

        $this->logger->info("Post-deploy: {$success} succeeded, {$failed} failed");
        return ['success' => $success, 'failed' => $failed];
    }
}
