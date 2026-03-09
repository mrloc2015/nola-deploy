<?php

declare(strict_types=1);

namespace Nola\Deploy\Health;

class HealthResult
{
    public function __construct(
        public readonly string $url,
        public readonly int $statusCode,
        public readonly float $responseTime,
        public readonly bool $passed,
        public readonly ?string $error = null,
    ) {
    }
}
