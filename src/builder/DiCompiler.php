<?php

declare(strict_types=1);

namespace Nola\Deploy\Builder;

use Nola\Deploy\Runner\MagentoRunner;
use Nola\Deploy\Runner\TaskResult;
use Nola\Deploy\Util\Logger;

class DiCompiler
{
    public function __construct(
        private MagentoRunner $magentoRunner,
        private Logger $logger,
    ) {
    }

    public function compile(): TaskResult
    {
        $this->logger->step('DI Compilation (setup:di:compile)');
        return $this->magentoRunner->run('setup:di:compile', [], 600);
    }
}
