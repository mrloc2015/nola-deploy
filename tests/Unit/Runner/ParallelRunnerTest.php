<?php

declare(strict_types=1);

namespace Nola\Deploy\Tests\Unit\Runner;

use Nola\Deploy\Runner\ParallelRunner;
use Nola\Deploy\Runner\Task;
use Nola\Deploy\Util\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class ParallelRunnerTest extends TestCase
{
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger(new BufferedOutput());
    }

    public function testEmptyQueueReturnsEmpty(): void
    {
        $runner = new ParallelRunner(4);
        $results = $runner->run($this->logger);
        $this->assertEmpty($results);
    }

    public function testSingleTaskRuns(): void
    {
        $runner = new ParallelRunner(4);
        $runner->addTask(new Task(
            label: 'echo test',
            command: ['echo', 'hello'],
            timeout: 10,
        ));

        $results = $runner->run($this->logger);
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->success);
        $this->assertStringContainsString('hello', $results[0]->output);
    }

    public function testMultipleTasksRunInParallel(): void
    {
        $runner = new ParallelRunner(4);

        for ($i = 1; $i <= 4; $i++) {
            $runner->addTask(new Task(
                label: "task-{$i}",
                command: ['echo', "output-{$i}"],
                timeout: 10,
            ));
        }

        $results = $runner->run($this->logger);
        $this->assertCount(4, $results);

        foreach ($results as $result) {
            $this->assertTrue($result->success, "Task {$result->label} failed");
        }
    }

    public function testFailedTaskReportsError(): void
    {
        $runner = new ParallelRunner(2);
        $runner->addTask(new Task(
            label: 'fail task',
            command: ['bash', '-c', 'exit 1'],
            timeout: 10,
        ));

        $results = $runner->run($this->logger);
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->success);
        $this->assertEquals(1, $results[0]->exitCode);
    }

    public function testQueueSize(): void
    {
        $runner = new ParallelRunner(4);
        $this->assertEquals(0, $runner->getQueueSize());

        $runner->addTask(new Task('t1', ['echo', '1']));
        $runner->addTask(new Task('t2', ['echo', '2']));
        $this->assertEquals(2, $runner->getQueueSize());
    }

    public function testWorkerLimit(): void
    {
        // Ensure tasks complete even with more tasks than workers
        $runner = new ParallelRunner(2);

        for ($i = 0; $i < 6; $i++) {
            $runner->addTask(new Task("t{$i}", ['echo', "{$i}"]));
        }

        $results = $runner->run($this->logger);
        $this->assertCount(6, $results);

        foreach ($results as $result) {
            $this->assertTrue($result->success);
        }
    }
}
