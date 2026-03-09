<?php

declare(strict_types=1);

namespace Nola\Deploy\Runner;

class TaskResult
{
    public function __construct(
        public readonly string $label,
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput,
        public readonly float $duration,
        public readonly bool $success,
    ) {
    }
}
