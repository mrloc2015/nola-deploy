<?php

declare(strict_types=1);

namespace Nola\Deploy\Deployer;

class SymlinkSwitcher
{
    /**
     * Atomic symlink switch using temp symlink + rename.
     * rename() is a single syscall — no gap where the link doesn't exist.
     */
    public function switch(string $targetDir, string $linkPath): void
    {
        if (!is_dir($targetDir)) {
            throw new \RuntimeException("Target directory does not exist: {$targetDir}");
        }

        $tempLink = $linkPath . '.tmp.' . getmypid();

        // Create temp symlink pointing to new target
        if (!@symlink($targetDir, $tempLink)) {
            throw new \RuntimeException("Failed to create temp symlink: {$tempLink} -> {$targetDir}");
        }

        // Atomic rename (single syscall, no gap)
        if (!@rename($tempLink, $linkPath)) {
            @unlink($tempLink);
            throw new \RuntimeException("Failed to switch symlink: {$linkPath}");
        }
    }

    public function rollback(string $previousRelease, string $linkPath): void
    {
        $this->switch($previousRelease, $linkPath);
    }

    public function getCurrentTarget(string $linkPath): ?string
    {
        if (!is_link($linkPath)) {
            return null;
        }
        return readlink($linkPath) ?: null;
    }
}
