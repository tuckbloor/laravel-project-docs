<?php

namespace DevDocs\LaravelProjectDocs\Data;

use JsonSerializable;

class ProjectDocumentation implements JsonSerializable
{
    /**
     * @param array<string, mixed> $meta
     * @param array<string, array<string, mixed>> $sections
     * @param array<int, array<string, string>> $warnings
     */
    public function __construct(
        public readonly array $meta,
        public readonly array $sections,
        public readonly array $warnings = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta,
            'sections' => $this->sections,
            'warnings' => $this->warnings,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
