<?php

namespace DevDocs\LaravelProjectDocs\Data;

class ProjectContext
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly string $rootPath,
        public readonly array $config,
        public readonly bool $includeSource = true,
    ) {
    }

    public function path(string $relativePath = ''): string
    {
        if ($relativePath === '') {
            return rtrim($this->rootPath, DIRECTORY_SEPARATOR);
        }

        return rtrim($this->rootPath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .ltrim($relativePath, DIRECTORY_SEPARATOR);
    }
}
