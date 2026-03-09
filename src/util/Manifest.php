<?php

declare(strict_types=1);

namespace Nola\Deploy\Util;

class Manifest
{
    private array $data = [];
    private string $path;

    public function __construct(string $magentoRoot)
    {
        $dir = $magentoRoot . '/var/nola-deploy';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->path = $dir . '/manifest.json';
    }

    public function load(): self
    {
        if (file_exists($this->path)) {
            $content = file_get_contents($this->path);
            $this->data = json_decode($content, true) ?? [];
        }
        return $this;
    }

    public function save(): void
    {
        file_put_contents(
            $this->path,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function exists(): bool
    {
        return file_exists($this->path) && !empty($this->data);
    }

    public function getLastDeployTime(): ?string
    {
        return $this->data['last_deploy'] ?? null;
    }

    public function getLastGitCommit(): ?string
    {
        return $this->data['git_commit'] ?? null;
    }

    public function getHash(string $key): ?string
    {
        return $this->data['hashes'][$key] ?? null;
    }

    public function getThemeHash(string $theme): ?string
    {
        return $this->data['hashes']['themes'][$theme] ?? null;
    }

    /** @return string[] */
    public function getDeployedThemes(): array
    {
        return $this->data['deployed_themes'] ?? [];
    }

    /** @return string[] */
    public function getDeployedLocales(): array
    {
        return $this->data['deployed_locales'] ?? [];
    }

    public function getLastDuration(): ?float
    {
        return $this->data['duration_seconds'] ?? null;
    }

    public function update(
        string $gitCommit,
        array $hashes,
        array $deployedThemes,
        array $deployedLocales,
        float $duration,
        string $magentoVersion = ''
    ): void {
        $this->data = [
            'last_deploy' => date('c'),
            'git_commit' => $gitCommit,
            'duration_seconds' => round($duration, 2),
            'hashes' => $hashes,
            'deployed_themes' => $deployedThemes,
            'deployed_locales' => $deployedLocales,
            'magento_version' => $magentoVersion,
            'tool_version' => '1.0.0',
        ];
    }

    public static function computeFileHash(string $filePath): string
    {
        if (!file_exists($filePath)) {
            return '';
        }
        return hash_file('sha256', $filePath);
    }

    public static function computeDirectoryHash(string $dirPath): string
    {
        if (!is_dir($dirPath)) {
            return '';
        }

        $hashes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace($dirPath . '/', '', $file->getPathname());
                $hashes[] = $relativePath . ':' . hash_file('sha256', $file->getPathname());
            }
        }

        sort($hashes);
        return hash('sha256', implode("\n", $hashes));
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
