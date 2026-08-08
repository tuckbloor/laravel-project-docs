<?php

namespace DevDocs\LaravelProjectDocs\Contracts;

use DevDocs\LaravelProjectDocs\Data\ProjectContext;

interface Scanner
{
    public function key(): string;

    /**
     * @return array<string, mixed>
     */
    public function scan(ProjectContext $context): array;
}
