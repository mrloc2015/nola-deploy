<?php

declare(strict_types=1);

namespace Nola\Deploy\Runner;

class Task
{
    public function __construct(
        public readonly string $label,
        public readonly array $command,
        public readonly ?string $workingDir = null,
        public readonly int $timeout = 600,
    ) {
    }
}
