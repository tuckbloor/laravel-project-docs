<?php

namespace DevDocs\LaravelProjectDocs\Contracts;

use DevDocs\LaravelProjectDocs\Data\ProjectDocumentation;

interface Renderer
{
    public function format(): string;

    /** Returns the absolute file path written by the renderer. */
    public function render(ProjectDocumentation $documentation, string $outputDirectory): string;
}
