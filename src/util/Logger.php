<?php

declare(strict_types=1);

namespace Nola\Deploy\Util;

use Symfony\Component\Console\Output\OutputInterface;

class Logger
{
    public function __construct(private OutputInterface $output)
    {
    }

    public function info(string $message): void
    {
        $this->output->writeln("<info>  {$message}</info>");
    }

    public function success(string $message): void
    {
        $this->output->writeln("<fg=green>  ✓ {$message}</>");
    }

    public function warning(string $message): void
    {
        $this->output->writeln("<comment>  ⚠ {$message}</comment>");
    }

    public function error(string $message): void
    {
        $this->output->writeln("<error>  ✗ {$message}</error>");
    }

    public function step(string $message): void
    {
        $this->output->writeln("");
        $this->output->writeln("<fg=cyan>▸ {$message}</>");
    }

    public function line(string $message = ''): void
    {
        $this->output->writeln("  {$message}");
    }

    public function separator(): void
    {
        $this->output->writeln("<fg=gray>  " . str_repeat('─', 50) . "</>");
    }

    public function banner(string $title): void
    {
        $this->output->writeln('');
        $this->output->writeln("<fg=white;bg=blue>  " . str_pad(" {$title} ", 50, ' ') . "</>");
        $this->output->writeln('');
    }
}
