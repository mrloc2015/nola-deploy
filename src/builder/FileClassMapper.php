<?php

declare(strict_types=1);

namespace Nola\Deploy\Builder;

/**
 * Maps changed PHP file paths to fully qualified class names (FQCN).
 * Used by SurgicalDiCompiler to determine which generated files to regenerate.
 */
class FileClassMapper
{
    public function __construct(
        private string $magentoRoot,
    ) {
    }

    /**
     * @param string[] $phpFiles Relative file paths (e.g. app/code/Vendor/Module/Model/Foo.php)
     * @return string[] Fully qualified class names
     */
    public function mapToClassNames(array $phpFiles): array
    {
        $classNames = [];

        foreach ($phpFiles as $file) {
            $fqcn = $this->resolveClassFromFile($file);
            if ($fqcn !== null) {
                $classNames[] = $fqcn;
            }
        }

        return array_unique($classNames);
    }

    private function resolveClassFromFile(string $relativePath): ?string
    {
        // Try PSR-4 path-based resolution first (fast, no file read)
        $fqcn = $this->resolveFromPath($relativePath);
        if ($fqcn !== null) {
            return $fqcn;
        }

        // Fallback: read file and parse namespace + class
        return $this->resolveFromFileContent($relativePath);
    }

    /**
     * Resolve FQCN from conventional Magento paths:
     * - app/code/Vendor/Module/Path/Class.php → Vendor\Module\Path\Class
     * - vendor/vendor/module-name/Path/Class.php → read from file
     */
    private function resolveFromPath(string $path): ?string
    {
        // app/code/Vendor/Module/... → Vendor\Module\...
        if (str_starts_with($path, 'app/code/')) {
            $relative = substr($path, strlen('app/code/'));
            $relative = preg_replace('/\.php$/', '', $relative);
            return str_replace('/', '\\', $relative);
        }

        return null;
    }

    private function resolveFromFileContent(string $relativePath): ?string
    {
        $fullPath = $this->magentoRoot . '/' . $relativePath;
        if (!file_exists($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            return null;
        }

        $namespace = null;
        $className = null;

        // Parse namespace
        if (preg_match('/^\s*namespace\s+([^;]+)/m', $content, $m)) {
            $namespace = trim($m[1]);
        }

        // Parse class/interface/trait name
        if (preg_match('/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $content, $m)) {
            $className = $m[1];
        }

        if ($namespace && $className) {
            return $namespace . '\\' . $className;
        }

        return null;
    }
}
