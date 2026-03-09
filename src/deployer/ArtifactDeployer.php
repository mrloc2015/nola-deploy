<?php

declare(strict_types=1);

namespace Nola\Deploy\Deployer;

use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;
use Symfony\Component\Process\Process;

class ArtifactDeployer
{
    public function __construct(
        private ConfigLoader $config,
        private Logger $logger,
    ) {
    }

    public function deploy(string $artifactPath): string
    {
        if (!file_exists($artifactPath)) {
            throw new \RuntimeException("Artifact not found: {$artifactPath}");
        }

        $releasesDir = $this->config->getReleasesDir();
        $releaseDir = $releasesDir . '/' . date('Ymd-His');

        $this->logger->step('Deploying Artifact');

        // Create release directory
        if (!mkdir($releaseDir, 0755, true)) {
            throw new \RuntimeException("Failed to create release directory: {$releaseDir}");
        }

        // Extract artifact
        $this->logger->info("Extracting to: {$releaseDir}");
        $process = new Process(
            ['tar', 'xzf', $artifactPath, '-C', $releaseDir],
            null,
            null,
            null,
            300
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Failed to extract artifact: ' . $process->getErrorOutput());
        }

        // Link shared resources
        $this->linkSharedResources($releaseDir);

        // Set permissions
        $this->setPermissions($releaseDir);

        $this->logger->success("Artifact deployed to: {$releaseDir}");

        return $releaseDir;
    }

    private function linkSharedResources(string $releaseDir): void
    {
        $releasesDir = dirname($releaseDir);
        $sharedDir = dirname($releasesDir) . '/shared';

        if (!is_dir($sharedDir)) {
            mkdir($sharedDir, 0755, true);
        }

        $this->logger->info('Linking shared resources');

        // Shared directories
        foreach ($this->config->getSharedDirs() as $dir) {
            $sharedPath = $sharedDir . '/' . $dir;
            $releasePath = $releaseDir . '/' . $dir;

            if (!is_dir($sharedPath)) {
                mkdir($sharedPath, 0755, true);
                // Copy existing content on first deploy
                if (is_dir($releasePath)) {
                    $process = new Process(['cp', '-a', $releasePath . '/.', $sharedPath . '/']);
                    $process->run();
                }
            }

            // Remove release dir and create symlink
            if (is_dir($releasePath) && !is_link($releasePath)) {
                $process = new Process(['rm', '-rf', $releasePath]);
                $process->run();
            } elseif (is_link($releasePath)) {
                unlink($releasePath);
            }

            $parentDir = dirname($releasePath);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }

            symlink($sharedPath, $releasePath);
        }

        // Shared files
        foreach ($this->config->getSharedFiles() as $file) {
            $sharedPath = $sharedDir . '/' . $file;
            $releasePath = $releaseDir . '/' . $file;

            if (!file_exists($sharedPath)) {
                $sharedFileDir = dirname($sharedPath);
                if (!is_dir($sharedFileDir)) {
                    mkdir($sharedFileDir, 0755, true);
                }
                if (file_exists($releasePath)) {
                    copy($releasePath, $sharedPath);
                }
            }

            if (file_exists($releasePath) && !is_link($releasePath)) {
                unlink($releasePath);
            }

            symlink($sharedPath, $releasePath);
        }
    }

    private function setPermissions(string $releaseDir): void
    {
        // Set directory permissions
        $process = new Process(['find', $releaseDir, '-type', 'd', '-exec', 'chmod', '755', '{}', '+']);
        $process->setTimeout(60);
        $process->run();

        // Set file permissions
        $process = new Process(['find', $releaseDir, '-type', 'f', '-exec', 'chmod', '644', '{}', '+']);
        $process->setTimeout(60);
        $process->run();

        // Make bin/magento executable
        $magento = $releaseDir . '/bin/magento';
        if (file_exists($magento)) {
            chmod($magento, 0755);
        }
    }
}
