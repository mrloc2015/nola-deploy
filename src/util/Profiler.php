<?php

declare(strict_types=1);

namespace Nola\Deploy\Util;

class Profiler
{
    /** @var array<string, float> */
    private array $starts = [];

    /** @var array<string, float> */
    private array $durations = [];

    public function start(string $step): void
    {
        $this->starts[$step] = microtime(true);
    }

    public function stop(string $step): float
    {
        if (!isset($this->starts[$step])) {
            return 0.0;
        }
        $duration = microtime(true) - $this->starts[$step];
        $this->durations[$step] = $duration;
        unset($this->starts[$step]);
        return $duration;
    }

    /** @return array<string, float> */
    public function getResults(): array
    {
        return $this->durations;
    }

    public function getTotal(): float
    {
        return array_sum($this->durations);
    }

    public function formatReport(): string
    {
        $lines = [];
        $lines[] = '┌──────────────────────────────────┬──────────┐';
        $lines[] = '│ Step                             │ Duration │';
        $lines[] = '├──────────────────────────────────┼──────────┤';

        foreach ($this->durations as $step => $duration) {
            $lines[] = sprintf('│ %-32s │ %6.1fs  │', $this->truncate($step, 32), $duration);
        }

        $lines[] = '├──────────────────────────────────┼──────────┤';
        $lines[] = sprintf('│ %-32s │ %6.1fs  │', 'TOTAL', $this->getTotal());
        $lines[] = '└──────────────────────────────────┴──────────┘';

        return implode("\n", $lines);
    }

    private function truncate(string $text, int $maxLen): string
    {
        if (mb_strlen($text) <= $maxLen) {
            return $text;
        }
        return mb_substr($text, 0, $maxLen - 3) . '...';
    }
}
