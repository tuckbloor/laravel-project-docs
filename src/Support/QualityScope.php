<?php

namespace DevDocs\LaravelProjectDocs\Support;

/**
 * Defines which scanned files are considered application-owned for static
 * quality review. Documentation discovery is intentionally broader; this
 * scope only controls findings and the review score.
 */
class QualityScope
{
    /** @param array<int,string> $excludedPaths */
    public function __construct(private readonly array $excludedPaths = [])
    {
    }

    /** @param array<string,array<string,mixed>> $sections */
    public static function fromSections(array $sections): self
    {
        return new self(array_values(array_filter(array_map(
            static fn (mixed $value): string => trim(str_replace('\\', '/', (string) $value), '/'),
            (array) ($sections['project']['quality_scope']['exclude_paths'] ?? []),
        ))));
    }

    public function includes(string $path): bool
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return true;
        }

        foreach ($this->excludedPaths as $pattern) {
            if ($this->matches($path, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int,string> */
    public function excludedPaths(): array
    {
        return $this->excludedPaths;
    }

    private function matches(string $path, string $pattern): bool
    {
        $pattern = trim(str_replace('\\', '/', $pattern), '/');
        if ($pattern === '') {
            return false;
        }

        if (str_ends_with($pattern, '/**')) {
            $prefix = substr($pattern, 0, -3);
            return $path === $prefix || str_starts_with($path, $prefix.'/');
        }

        if (! strpbrk($pattern, '*?[')) {
            return $path === $pattern;
        }

        return fnmatch($pattern, $path);
    }
}
