<?php

declare(strict_types=1);

namespace Nola\Deploy\Util;

class BinaryDetector
{
    public function __construct(private string $magentoRoot)
    {
    }

    public function hasNode(): bool
    {
        return $this->which('node') !== null;
    }

    public function hasLessc(): bool
    {
        $local = $this->magentoRoot . '/node_modules/.bin/lessc';
        if (file_exists($local)) {
            return true;
        }
        return $this->which('lessc') !== null;
    }

    public function getLesscPath(): string
    {
        $local = $this->magentoRoot . '/node_modules/.bin/lessc';
        if (file_exists($local)) {
            return $local;
        }
        return $this->which('lessc') ?? 'lessc';
    }

    public function hasGoDeployer(): bool
    {
        $local = $this->magentoRoot . '/bin/magento2-static-deploy';
        if (file_exists($local) && is_executable($local)) {
            return true;
        }
        return $this->which('magento2-static-deploy') !== null;
    }

    public function getGoDeployerPath(): string
    {
        $local = $this->magentoRoot . '/bin/magento2-static-deploy';
        if (file_exists($local) && is_executable($local)) {
            return $local;
        }
        return $this->which('magento2-static-deploy') ?? 'magento2-static-deploy';
    }

    public function hasMagepack(): bool
    {
        $local = $this->magentoRoot . '/node_modules/.bin/magepack';
        if (file_exists($local)) {
            return true;
        }
        return $this->which('magepack') !== null;
    }

    public function getPhpVersion(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
    }

    public function getNodeVersion(): ?string
    {
        $version = $this->exec('node --version');
        return $version ? trim($version) : null;
    }

    public function getCpuCores(): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $cores = $this->exec('nproc');
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $cores = $this->exec('sysctl -n hw.ncpu');
        } else {
            return 4;
        }

        return $cores ? max((int) trim($cores), 1) : 4;
    }

    public function getOptimalWorkers(): int
    {
        return min(max($this->getCpuCores() - 1, 2), 8);
    }

    public function which(string $binary): ?string
    {
        $result = $this->exec("which {$binary} 2>/dev/null");
        $result = $result ? trim($result) : '';
        return $result !== '' ? $result : null;
    }

    /** @return array<string, array{available: bool, version: ?string}> */
    public function detectAll(): array
    {
        return [
            'php' => [
                'available' => true,
                'version' => $this->getPhpVersion(),
            ],
            'node' => [
                'available' => $this->hasNode(),
                'version' => $this->getNodeVersion(),
            ],
            'lessc' => [
                'available' => $this->hasLessc(),
                'version' => null,
            ],
            'go_deployer' => [
                'available' => $this->hasGoDeployer(),
                'version' => null,
            ],
            'magepack' => [
                'available' => $this->hasMagepack(),
                'version' => null,
            ],
        ];
    }

    private function exec(string $command): ?string
    {
        $result = @shell_exec($command);
        return is_string($result) ? $result : null;
    }
}
