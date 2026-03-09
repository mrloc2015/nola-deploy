<?php

declare(strict_types=1);

namespace Nola\Deploy;

use Nola\Deploy\Command\BenchmarkCommand;
use Nola\Deploy\Command\BuildCommand;
use Nola\Deploy\Command\DeployArtifactCommand;
use Nola\Deploy\Command\DeployCommand;
use Nola\Deploy\Command\DeployDiffCommand;
use Nola\Deploy\Command\DeployFreshCommand;
use Nola\Deploy\Command\InitCommand;
use Nola\Deploy\Command\RollbackCommand;
use Nola\Deploy\Command\StatusCommand;
use Symfony\Component\Console\Application as ConsoleApplication;

class Application extends ConsoleApplication
{
    public const VERSION = '1.0.0';

    public function __construct()
    {
        parent::__construct('nola-deploy', self::VERSION);

        $this->add(new DeployCommand());
        $this->add(new DeployFreshCommand());
        $this->add(new DeployDiffCommand());
        $this->add(new BuildCommand());
        $this->add(new DeployArtifactCommand());
        $this->add(new StatusCommand());
        $this->add(new BenchmarkCommand());
        $this->add(new RollbackCommand());
        $this->add(new InitCommand());

        $this->setDefaultCommand('deploy');
    }
}
