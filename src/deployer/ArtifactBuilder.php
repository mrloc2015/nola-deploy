<?php

declare(strict_types=1);

namespace Nola\Deploy\Deployer;

use Nola\Deploy\Util\ConfigLoader;
use Nola\Deploy\Util\Logger;
use Symfony\Component\Process\Process;

class ArtifactBuilder
{
    private array $excludePatterns = [
        '.git',
        'dev',
        'phpunit.xml*',
        '.github',
        '.gitlab-ci*',
        'var/cache',
        'var/page_cache',
        'var/session',
        'var/log',
        'var/report',
        'var/tmp',
        'pub/media',
        '*/tests',
        '*/test',
        '*/Test',
        'node_modules',
        '.claude',
        'plans',
        'nola-deploy',
        '.phpunit*',
        'docker-compose*',
        'Dockerfile*',
    ];

    public function __construct(
        private ConfigLoader $config,
        private Logger $logger,
    ) {
    }

    public function build(string $outputPath): string
    {
        $root = $this->config->getMagentoRoot();

        // Validate vendor directory exists
        if (!is_dir($root . '/vendor')) {
            throw new \RuntimeException('vendor/ directory not found. Run composer install first.');
        }

        // Generate artifact manifest
        $manifest = $this->createManifest();
        $manifestDir = $root . '/var/nola-deploy';
        if (!is_dir($manifestDir)) {
            mkdir($manifestDir, 0755, true);
        }
        file_put_contents(
            $manifestDir . '/artifact-manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Build tar.gz
        $this->logger->info("Creating artifact: {$outputPath}");

        $excludeArgs = [];
        foreach ($this->excludePatterns as $pattern) {
            $excludeArgs[] = "--exclude={$pattern}";
        }

        $cmd = array_merge(
            ['tar', 'czf', $outputPath],
            $excludeArgs,
            ['-C', $root, '.']
        );

        $process = new Process($cmd, $root, null, null, 300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Failed to create artifact: ' . $process->getErrorOutput());
        }

        $size = filesize($outputPath);
        $sizeMb = round($size / 1024 / 1024, 1);
        $this->logger->success("Artifact created: {$sizeMb} MB");

        return $outputPath;
    }

    private function createManifest(): array
    {
        $root = $this->config->getMagentoRoot();

        $gitCommit = trim((string) @shell_exec(
            "cd " . escapeshellarg($root) . " && git rev-parse HEAD 2>/dev/null"
        ));
        $gitBranch = trim((string) @shell_exec(
            "cd " . escapeshellarg($root) . " && git rev-parse --abbrev-ref HEAD 2>/dev/null"
        ));

        return [
            'tool_version' => '1.0.0',
            'build_date' => date('c'),
            'git_commit' => $gitCommit ?: 'unknown',
            'git_branch' => $gitBranch ?: 'unknown',
            'php_version' => phpversion(),
            'magento_root' => $root,
        ];
    }
}
