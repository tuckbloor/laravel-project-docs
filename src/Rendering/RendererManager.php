<?php

namespace DevDocs\LaravelProjectDocs\Rendering;

use DevDocs\LaravelProjectDocs\Contracts\Renderer;
use DevDocs\LaravelProjectDocs\Data\ProjectDocumentation;
use InvalidArgumentException;

class RendererManager
{
    /** @var array<string, Renderer> */
    private array $renderers = [];

    /** @param array<int, Renderer> $renderers */
    public function __construct(array $renderers)
    {
        foreach ($renderers as $renderer) {
            $this->renderers[$renderer->format()] = $renderer;
        }
    }

    /** @return array<int, string> */
    public function formats(): array
    {
        return array_keys($this->renderers);
    }

    public function render(string $format, ProjectDocumentation $documentation, string $outputDirectory): string
    {
        if (! isset($this->renderers[$format])) {
            throw new InvalidArgumentException("Unsupported documentation format [{$format}].");
        }

        return $this->renderers[$format]->render($documentation, $outputDirectory);
    }
}
