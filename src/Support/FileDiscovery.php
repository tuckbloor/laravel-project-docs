<?php

namespace DevDocs\LaravelProjectDocs\Support;

use DevDocs\LaravelProjectDocs\Data\ProjectContext;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class FileDiscovery
{
    /**
     * @param array<int, string> $suffixes
     * @return array<int, string>
     */
    public function files(ProjectContext $context, array $suffixes): array
    {
        $files = [];

        foreach ((array) ($context->config['include'] ?? []) as $relativePath) {
            $absolutePath = $context->path((string) $relativePath);

            if (is_file($absolutePath)) {
                if ($this->allowed($context, $absolutePath, $suffixes)) {
                    $files[$absolutePath] = $absolutePath;
                }
                continue;
            }

            if (! is_dir($absolutePath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolutePath, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();
                if ($this->allowed($context, $path, $suffixes)) {
                    $files[$path] = $path;
                }
            }
        }

        ksort($files);

        return array_values($files);
    }

    /**
     * @param array<int, string> $suffixes
     */
    private function allowed(ProjectContext $context, string $path, array $suffixes): bool
    {
        $relative = $this->relativePath($context->rootPath, $path);
        $normal = str_replace('\\', '/', $relative);

        foreach ((array) ($context->config['exclude'] ?? []) as $excluded) {
            $excluded = trim(str_replace('\\', '/', (string) $excluded), '/');
            if ($excluded !== '' && ($normal === $excluded || str_starts_with($normal, $excluded.'/'))) {
                return false;
            }
        }

        $basename = basename($path);
        foreach ((array) ($context->config['blocked_files'] ?? []) as $pattern) {
            if (fnmatch((string) $pattern, $basename)) {
                return false;
            }
        }

        foreach ($suffixes as $suffix) {
            if (str_ends_with(strtolower($path), strtolower($suffix))) {
                return true;
            }
        }

        return false;
    }

    public function relativePath(string $rootPath, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', realpath($rootPath) ?: $rootPath), '/').'/';
        $candidate = str_replace('\\', '/', realpath($path) ?: $path);

        return str_starts_with($candidate, $root)
            ? substr($candidate, strlen($root))
            : $candidate;
    }
}
