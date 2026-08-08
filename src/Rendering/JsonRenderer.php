<?php

namespace DevDocs\LaravelProjectDocs\Rendering;

use DevDocs\LaravelProjectDocs\Contracts\Renderer;
use DevDocs\LaravelProjectDocs\Data\ProjectDocumentation;

class JsonRenderer implements Renderer
{
    public function format(): string
    {
        return 'json';
    }

    public function render(ProjectDocumentation $documentation, string $outputDirectory): string
    {
        $this->ensureDirectory($outputDirectory);
        $qualityOnly = (($documentation->meta['report_mode'] ?? 'full') === 'quality');
        $filename = $qualityOnly ? 'project-quality-report.json' : 'project-documentation.json';
        $path = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;

        $payload = $documentation;
        if ($qualityOnly) {
            $payload = [
                'meta' => $documentation->meta,
                'quality_report' => (array) ($documentation->sections['intelligence']['quality_report'] ?? []),
                'quality_scope' => (array) ($documentation->sections['coverage']['quality'] ?? []),
                'warnings' => $documentation->warnings,
            ];
        }

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        return $path;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create output directory [{$directory}].");
        }
    }
}
