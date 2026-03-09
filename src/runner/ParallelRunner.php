<?php

declare(strict_types=1);

namespace Nola\Deploy\Runner;

use Nola\Deploy\Util\Logger;
use Symfony\Component\Process\Process;

class ParallelRunner
{
    /** @var Task[] */
    private array $queue = [];

    public function __construct(private int $maxWorkers = 4)
    {
        $this->maxWorkers = max(1, $maxWorkers);
    }

    public function addTask(Task $task): void
    {
        $this->queue[] = $task;
    }

    public function getQueueSize(): int
    {
        return count($this->queue);
    }

    /** @return TaskResult[] */
    public function run(Logger $logger): array
    {
        if (empty($this->queue)) {
            return [];
        }

        // Single task? Run directly (no overhead)
        if (count($this->queue) === 1) {
            return [$this->runSingle($this->queue[0], $logger)];
        }

        $results = [];
        /** @var array<string, array{process: Process, task: Task, startTime: float}> */
        $active = [];

        while (!empty($this->queue) || !empty($active)) {
            // Fill active slots from queue
            while (count($active) < $this->maxWorkers && !empty($this->queue)) {
                $task = array_shift($this->queue);
                $process = new Process(
                    $task->command,
                    $task->workingDir,
                    null,
                    null,
                    $task->timeout
                );

                $process->start();
                $active[$task->label] = [
                    'process' => $process,
                    'task' => $task,
                    'startTime' => microtime(true),
                ];
                $logger->info("Started: {$task->label}");
            }

            // Check for completed processes
            foreach ($active as $label => $entry) {
                $process = $entry['process'];
                if (!$process->isRunning()) {
                    $duration = microtime(true) - $entry['startTime'];
                    $result = new TaskResult(
                        label: $label,
                        exitCode: $process->getExitCode() ?? 1,
                        output: $process->getOutput(),
                        errorOutput: $process->getErrorOutput(),
                        duration: round($duration, 2),
                        success: $process->isSuccessful(),
                    );
                    $results[] = $result;

                    if ($result->success) {
                        $logger->success("{$label} ({$result->duration}s)");
                    } else {
                        $logger->error("Failed: {$label}");
                        if ($result->errorOutput) {
                            $logger->line(substr($result->errorOutput, 0, 500));
                        }
                    }

                    unset($active[$label]);
                }
            }

            // Prevent CPU spin
            if (!empty($active)) {
                usleep(100_000); // 100ms
            }
        }

        return $results;
    }

    private function runSingle(Task $task, Logger $logger): TaskResult
    {
        $logger->info("Running: {$task->label}");
        $startTime = microtime(true);

        $process = new Process(
            $task->command,
            $task->workingDir,
            null,
            null,
            $task->timeout
        );

        $process->run(function (string $type, string $data) use ($logger) {
            foreach (explode("\n", trim($data)) as $line) {
                if ($line !== '') {
                    $logger->line($line);
                }
            }
        });

        $duration = round(microtime(true) - $startTime, 2);

        $result = new TaskResult(
            label: $task->label,
            exitCode: $process->getExitCode() ?? 1,
            output: $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
            duration: $duration,
            success: $process->isSuccessful(),
        );

        if ($result->success) {
            $logger->success("{$task->label} ({$duration}s)");
        } else {
            $logger->error("Failed: {$task->label}");
        }

        return $result;
    }
}
