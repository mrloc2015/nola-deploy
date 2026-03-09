<?php

declare(strict_types=1);

namespace Nola\Deploy\Runner;

use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;
use Symfony\Component\Process\Process;

class MagentoRunner
{
    public function __construct(
        private ConfigLoader $config,
        private Logger $logger,
    ) {
    }

    public function run(string $command, array $args = [], int $timeout = 600, bool $stream = true): TaskResult
    {
        $cmd = $this->buildCommand($command, $args);
        $this->logger->info("Running: bin/magento {$command} " . implode(' ', $args));

        $startTime = microtime(true);

        $process = new Process(
            $cmd,
            $this->config->getMagentoRoot(),
            null,
            null,
            $timeout,
        );

        if ($stream) {
            $process->run(function (string $type, string $data) {
                foreach (explode("\n", trim($data)) as $line) {
                    if ($line !== '') {
                        $this->logger->line($line);
                    }
                }
            });
        } else {
            $process->run();
        }

        $duration = round(microtime(true) - $startTime, 2);

        return new TaskResult(
            label: "magento:{$command}",
            exitCode: $process->getExitCode() ?? 1,
            output: $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
            duration: $duration,
            success: $process->isSuccessful(),
        );
    }

    public function createTask(string $command, array $args = [], int $timeout = 600): Task
    {
        return new Task(
            label: "magento:{$command}",
            command: $this->buildCommand($command, $args),
            workingDir: $this->config->getMagentoRoot(),
            timeout: $timeout,
        );
    }

    private function buildCommand(string $command, array $args = []): array
    {
        $phpBin = $this->config->getPhpBinary();
        $memLimit = $this->config->getMemoryLimit();
        $magentoRoot = $this->config->getMagentoRoot();

        $cmd = [$phpBin, "-d", "memory_limit={$memLimit}"];

        // Disable GC for di:compile (5-10% faster)
        if ($command === 'setup:di:compile' && $this->config->isDiGcDisabled()) {
            $cmd[] = '-d';
            $cmd[] = 'zend.enable_gc=0';
        }

        $cmd[] = "{$magentoRoot}/bin/magento";
        $cmd[] = $command;

        return array_merge($cmd, $args);
    }
}
