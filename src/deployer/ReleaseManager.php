<?php

declare(strict_types=1);

namespace Nola\Deploy\Deployer;

use Symfony\Component\Process\Process;

class ReleaseManager
{
    public function __construct(
        private string $releasesDir,
        private string $currentLink,
    ) {
    }

    /** @return string[] */
    public function getReleaseDirs(): array
    {
        if (!is_dir($this->releasesDir)) {
            return [];
        }

        $dirs = glob($this->releasesDir . '/????????-??????');
        if ($dirs === false) {
            return [];
        }
        rsort($dirs); // newest first
        return $dirs;
    }

    public function getCurrentRelease(): ?string
    {
        if (!is_link($this->currentLink)) {
            return null;
        }
        $target = readlink($this->currentLink);
        return $target ?: null;
    }

    public function getPreviousRelease(): ?string
    {
        $releases = $this->getReleaseDirs();
        $current = $this->getCurrentRelease();

        foreach ($releases as $release) {
            if (realpath($release) !== realpath($current ?? '')) {
                return $release;
            }
        }

        return null;
    }

    /** @return string[] List of removed release paths */
    public function cleanup(int $keepCount = 5): array
    {
        $releases = $this->getReleaseDirs();
        $current = $this->getCurrentRelease();
        $removed = [];

        $toRemove = array_slice($releases, $keepCount);

        foreach ($toRemove as $old) {
            // Never remove current
            if ($current && realpath($old) === realpath($current)) {
                continue;
            }

            $process = new Process(['rm', '-rf', $old]);
            $process->run();

            if ($process->isSuccessful()) {
                $removed[] = $old;
            }
        }

        return $removed;
    }

    public function getReleaseCount(): int
    {
        return count($this->getReleaseDirs());
    }
}
