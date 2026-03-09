<?php

declare(strict_types=1);

namespace Nola\Deploy\Builder;

use Nola\Deploy\Runner\MagentoRunner;
use Nola\Deploy\Runner\TaskResult;
use Nola\Deploy\Util\Logger;

/**
 * Surgical DI compilation: regenerates only the specific generated files
 * affected by changed PHP source files, instead of running full di:compile.
 *
 * Strategy: delete stale Interceptor/Factory/Proxy files, then either
 * let Magento auto-generate on next request or trigger via cache:clean.
 *
 * Falls back to full di:compile if too many classes affected (>20)
 * or if any errors occur during surgical regeneration.
 */
class SurgicalDiCompiler
{
    private const MAX_SURGICAL_CLASSES = 20;

    public function __construct(
        private string $magentoRoot,
        private MagentoRunner $magentoRunner,
        private Logger $logger,
    ) {
    }

    /**
     * @param string[] $changedPhpFiles Relative paths to changed PHP files
     * @return TaskResult
     */
    public function compileAffectedClasses(array $changedPhpFiles): TaskResult
    {
        $startTime = microtime(true);
        $mapper = new FileClassMapper($this->magentoRoot);
        $classNames = $mapper->mapToClassNames($changedPhpFiles);

        if (empty($classNames)) {
            return new TaskResult(
                label: 'Surgical DI',
                exitCode: 0,
                output: 'No classes resolved from changed files',
                errorOutput: '',
                duration: microtime(true) - $startTime,
                success: true,
            );
        }

        // Fallback to full compile if too many classes affected
        if (count($classNames) > self::MAX_SURGICAL_CLASSES) {
            $this->logger->info(
                count($classNames) . ' classes affected (>' . self::MAX_SURGICAL_CLASSES
                . ') — falling back to full di:compile'
            );
            return $this->fallbackToFullCompile();
        }

        $this->logger->step('Surgical DI (' . count($classNames) . ' class(es))');

        $deletedFiles = 0;
        $errors = [];

        foreach ($classNames as $className) {
            $result = $this->deleteGeneratedFilesForClass($className);
            $deletedFiles += $result['deleted'];
            if (!empty($result['errors'])) {
                $errors = array_merge($errors, $result['errors']);
            }
        }

        if (!empty($errors)) {
            $this->logger->warning('Errors during surgical DI, falling back to full compile');
            foreach ($errors as $err) {
                $this->logger->info('  ' . $err);
            }
            return $this->fallbackToFullCompile();
        }

        $this->logger->info("Deleted {$deletedFiles} generated file(s) for " . count($classNames) . " class(es)");

        // Clean compiled config cache so Magento regenerates on next request
        $cacheResult = $this->magentoRunner->run('cache:clean', ['compiled_config'], 30, false);

        $duration = microtime(true) - $startTime;

        return new TaskResult(
            label: 'Surgical DI',
            exitCode: $cacheResult->exitCode,
            output: "Regenerated {$deletedFiles} file(s) for " . count($classNames) . " class(es)",
            errorOutput: '',
            duration: $duration,
            success: $cacheResult->success,
        );
    }

    /**
     * Delete generated Interceptor, Factory, and Proxy files for a given class.
     *
     * @return array{deleted: int, errors: string[]}
     */
    private function deleteGeneratedFilesForClass(string $className): array
    {
        $generatedDir = $this->magentoRoot . '/generated/code/';
        $classPath = str_replace('\\', '/', $className);
        $deleted = 0;
        $errors = [];

        // Interceptor: generated/code/Vendor/Module/Model/Foo/Interceptor.php
        $interceptorPath = $generatedDir . $classPath . '/Interceptor.php';
        if (file_exists($interceptorPath)) {
            if (@unlink($interceptorPath)) {
                $deleted++;
                $this->logger->info("  ✓ Deleted Interceptor for {$className}");
            } else {
                $errors[] = "Failed to delete: {$interceptorPath}";
            }
        }

        // Factory: generated/code/Vendor/Module/Model/FooFactory.php
        $factoryPath = $generatedDir . $classPath . 'Factory.php';
        if (file_exists($factoryPath)) {
            if (@unlink($factoryPath)) {
                $deleted++;
                $this->logger->info("  ✓ Deleted Factory for {$className}");
            } else {
                $errors[] = "Failed to delete: {$factoryPath}";
            }
        }

        // Proxy: generated/code/Vendor/Module/Model/Foo/Proxy.php
        $proxyPath = $generatedDir . $classPath . '/Proxy.php';
        if (file_exists($proxyPath)) {
            if (@unlink($proxyPath)) {
                $deleted++;
                $this->logger->info("  ✓ Deleted Proxy for {$className}");
            } else {
                $errors[] = "Failed to delete: {$proxyPath}";
            }
        }

        // Extension: generated/code/Vendor/Module/Api/Data/FooExtension.php
        $extensionPath = $generatedDir . $classPath . 'Extension.php';
        if (file_exists($extensionPath)) {
            if (@unlink($extensionPath)) {
                $deleted++;
            }
        }

        // ExtensionInterface
        $extIfacePath = $generatedDir . $classPath . 'ExtensionInterface.php';
        if (file_exists($extIfacePath)) {
            if (@unlink($extIfacePath)) {
                $deleted++;
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    private function fallbackToFullCompile(): TaskResult
    {
        $diCompiler = new DiCompiler($this->magentoRunner, $this->logger);
        return $diCompiler->compile();
    }
}
