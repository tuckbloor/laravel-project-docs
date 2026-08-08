<?php

namespace DevDocs\LaravelProjectDocs\Scanning;

use DevDocs\LaravelProjectDocs\Contracts\Scanner;
use DevDocs\LaravelProjectDocs\Data\ProjectContext;
use DevDocs\LaravelProjectDocs\Data\ProjectDocumentation;
use DevDocs\LaravelProjectDocs\Support\ApplicationIntelligenceBuilder;
use DevDocs\LaravelProjectDocs\Support\RelationshipBuilder;
use Throwable;

class ProjectAnalyzer
{
    /** @param array<int, Scanner> $scanners */
    public function __construct(
        private readonly array $scanners,
        private readonly RelationshipBuilder $relationships,
        private readonly ApplicationIntelligenceBuilder $intelligence,
    ) {
    }

    public function analyze(ProjectContext $context): ProjectDocumentation
    {
        $sections = [];
        $warnings = [];

        foreach ($this->scanners as $scanner) {
            try {
                $sections[$scanner->key()] = $scanner->scan($context);
            } catch (Throwable $exception) {
                $warnings[] = [
                    'scanner' => $scanner->key(),
                    'message' => $exception->getMessage(),
                ];
                $sections[$scanner->key()] = [];
            }
        }

        $sections['relationships'] = $this->relationships->build($sections);
        $sections['intelligence'] = $this->intelligence->build($sections);
        $sections['coverage'] = $this->coverage($sections, $warnings);

        $project = $sections['project'] ?? [];
        $git = $project['git'] ?? [];

        return new ProjectDocumentation(
            meta: [
                'project_name' => $this->resolveProjectName($context, $project),
                'root' => $context->rootPath,
                'generated_at' => date(DATE_ATOM),
                'php_version' => PHP_VERSION,
                'laravel_version' => (string) ($project['laravel']['version'] ?? 'unknown'),
                'generator' => 'tuckbloor/laravel-project-docs',
                'generator_version' => '0.9.4',
                'report_mode' => (($context->config['report_mode'] ?? 'full') === 'quality' ? 'quality' : 'full'),
                'analysis_mode' => 'static-read-only',
                'application_code_execution' => false,
                'tests_executed' => false,
                'git_branch' => $git['branch'] ?? null,
                'git_commit' => $git['commit'] ?? null,
                'env_requested' => (bool) (($project['env_file']['requested'] ?? false)),
                'env_exists' => (bool) (($project['env_file']['exists'] ?? false)),
                'env_included' => (bool) (($project['env_file']['included'] ?? false)),
            ],
            sections: $sections,
            warnings: $warnings,
        );
    }

    /**
     * Resolve a human project name without relying on the container mount folder.
     *
     * Dockerised Laravel applications commonly live at /var/www/html, so using
     * basename(base_path()) would incorrectly label every report as "html".
     * Prefer an explicit package setting, then Laravel's already-loaded app.name,
     * then package metadata. Generic framework/container names are skipped.
     */
    private function resolveProjectName(ProjectContext $context, array $project): string
    {
        $candidates = [
            $context->config['project_name'] ?? null,
            $context->config['application_name'] ?? null,
            $project['npm']['name'] ?? null,
            $project['composer']['name'] ?? null,
            basename(rtrim($context->rootPath, DIRECTORY_SEPARATOR)),
        ];

        foreach ($candidates as $candidate) {
            $name = trim((string) $candidate);
            if ($name === '' || $this->isGenericProjectName($name)) {
                continue;
            }

            return $name;
        }

        return 'Laravel Project';
    }

    private function isGenericProjectName(string $name): bool
    {
        return in_array(strtolower(trim($name)), [
            'laravel',
            'laravel/laravel',
            'html',
            'www',
            'wwwroot',
            'app',
            'application',
        ], true);
    }

    /** @return array<string,mixed> */
    private function coverage(array $sections, array $warnings): array
    {
        $php = (array) ($sections['php'] ?? []);
        $frontend = (array) ($sections['frontend'] ?? []);
        $routes = (array) ($sections['routes'] ?? []);
        $database = (array) ($sections['database'] ?? []);
        $quality = (array) ($sections['intelligence']['quality_report'] ?? []);
        $scope = (array) ($quality['scope'] ?? []);

        $phpItems = (array) ($php['items'] ?? []);
        $frontendItems = (array) ($frontend['items'] ?? []);
        $phpErrors = (array) ($php['errors'] ?? []);
        $frontendErrors = (array) ($frontend['errors'] ?? []);
        $routeErrors = (array) ($routes['errors'] ?? []);
        $migrationErrors = (array) ($database['errors'] ?? []);

        $phpSourceIncluded = count(array_filter($phpItems, static fn (array $item): bool => ($item['source'] ?? null) !== null));
        $frontendSourceIncluded = count(array_filter($frontendItems, static fn (array $item): bool => ($item['source'] ?? null) !== null));
        $issues = [];
        foreach ([
            'PHP parser' => $phpErrors,
            'Frontend read' => $frontendErrors,
            'Route parser' => $routeErrors,
            'Migration parser' => $migrationErrors,
        ] as $type => $errors) {
            foreach ($errors as $error) {
                $issues[] = [
                    'type' => $type,
                    'path' => (string) ($error['path'] ?? ''),
                    'message' => (string) ($error['message'] ?? ''),
                ];
            }
        }
        foreach ($warnings as $warning) {
            $issues[] = [
                'type' => 'Scanner',
                'path' => (string) ($warning['scanner'] ?? ''),
                'message' => (string) ($warning['message'] ?? ''),
            ];
        }

        return [
            'php' => [
                'files' => count($phpItems),
                'structurally_parsed' => max(0, count($phpItems) - count($phpErrors)),
                'parse_errors' => count($phpErrors),
                'source_included' => $phpSourceIncluded,
            ],
            'frontend' => [
                'files' => count($frontendItems),
                'read_errors' => count($frontendErrors),
                'source_included' => $frontendSourceIncluded,
            ],
            'routes' => [
                'routes' => count((array) ($routes['items'] ?? [])),
                'file_errors' => count($routeErrors),
            ],
            'database' => [
                'tables' => count((array) ($database['tables'] ?? [])),
                'migrations_parsed' => count((array) ($database['migrations'] ?? [])),
                'migration_errors' => count($migrationErrors),
            ],
            'quality' => [
                'reviewed_php_files' => (int) ($scope['reviewed_php_files'] ?? 0),
                'excluded_php_files' => (int) ($scope['excluded_php_files'] ?? 0),
                'reviewed_routes' => (int) ($scope['reviewed_routes'] ?? 0),
                'excluded_routes' => (int) ($scope['excluded_routes'] ?? 0),
            ],
            'scanner_warnings' => count($warnings),
            'issues' => $issues,
            'complete' => count($phpErrors) === 0
                && count($frontendErrors) === 0
                && count($routeErrors) === 0
                && count($migrationErrors) === 0
                && count($warnings) === 0,
        ];
    }

}
