<?php

declare(strict_types=1);

namespace Nola\Deploy\Health;

use Nola\Deploy\Runner\ParallelRunner;
use Nola\Deploy\Runner\Task;
use Nola\Deploy\Util\Logger;

class CacheWarmer
{
    public function __construct(private Logger $logger)
    {
    }

    public function warm(array $urls, string $baseUrl = '', int $concurrency = 4): void
    {
        if (empty($urls) || empty($baseUrl)) {
            $this->logger->info('Cache warmup skipped (no URLs or base URL configured)');
            return;
        }

        $this->logger->step('Cache Warmup');

        $runner = new ParallelRunner($concurrency);

        foreach ($urls as $url) {
            $fullUrl = str_starts_with($url, 'http')
                ? $url
                : rtrim($baseUrl, '/') . '/' . ltrim($url, '/');

            $runner->addTask(new Task(
                label: "Warm: {$url}",
                command: ['curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', $fullUrl],
                timeout: 30,
            ));
        }

        $runner->run($this->logger);
    }
}
