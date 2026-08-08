<?php

namespace DevDocs\LaravelProjectDocs;

use DevDocs\LaravelProjectDocs\Console\GenerateDocumentationCommand;
use DevDocs\LaravelProjectDocs\Rendering\HtmlRenderer;
use DevDocs\LaravelProjectDocs\Rendering\JsonRenderer;
use DevDocs\LaravelProjectDocs\Rendering\PdfRenderer;
use DevDocs\LaravelProjectDocs\Rendering\RendererManager;
use DevDocs\LaravelProjectDocs\Scanning\FrontendScanner;
use DevDocs\LaravelProjectDocs\Scanning\MigrationScanner;
use DevDocs\LaravelProjectDocs\Scanning\ProjectMetadataScanner;
use DevDocs\LaravelProjectDocs\Scanning\PhpScanner;
use DevDocs\LaravelProjectDocs\Scanning\ProjectAnalyzer;
use DevDocs\LaravelProjectDocs\Scanning\RouteScanner;
use DevDocs\LaravelProjectDocs\Support\ApplicationIntelligenceBuilder;
use DevDocs\LaravelProjectDocs\Support\RelationshipBuilder;
use Illuminate\Support\ServiceProvider;

class LaravelProjectDocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/project-docs.php', 'project-docs');

        $this->app->singleton(ProjectAnalyzer::class, function ($app) {
            return new ProjectAnalyzer(
                scanners: [
                    $app->make(ProjectMetadataScanner::class),
                    $app->make(RouteScanner::class),
                    $app->make(PhpScanner::class),
                    $app->make(MigrationScanner::class),
                    $app->make(FrontendScanner::class),
                ],
                relationships: $app->make(RelationshipBuilder::class),
                intelligence: $app->make(ApplicationIntelligenceBuilder::class),
            );
        });

        $this->app->singleton(RendererManager::class, function ($app) {
            return new RendererManager([
                $app->make(HtmlRenderer::class),
                $app->make(PdfRenderer::class),
                $app->make(JsonRenderer::class),
            ]);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/project-docs.php' => config_path('project-docs.php'),
        ], 'project-docs-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateDocumentationCommand::class,
            ]);
        }
    }
}
