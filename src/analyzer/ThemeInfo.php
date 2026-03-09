<?php

declare(strict_types=1);

namespace Nola\Deploy\Analyzer;

class ThemeInfo
{
    public function __construct(
        public readonly string $code,
        public readonly string $area,
        public readonly bool $isHyva = false,
        public readonly ?string $parentTheme = null,
    ) {
    }

    public function getType(): string
    {
        return $this->isHyva ? 'Hyva' : 'Luma';
    }
}
