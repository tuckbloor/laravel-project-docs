<?php

namespace DevDocs\LaravelProjectDocs\Console;

use DevDocs\LaravelProjectDocs\Data\ProjectContext;
use DevDocs\LaravelProjectDocs\Rendering\RendererManager;
use DevDocs\LaravelProjectDocs\Scanning\ProjectAnalyzer;
use Illuminate\Console\Command;

class GenerateDocumentationCommand extends Command
{
    protected $signature = 'project-docs:generate
                            {--format=* : Output format(s), e.g. html or json}
                            {--path= : Output path relative to the Laravel project root}
                            {--no-source : Do not embed source code in the generated documentation}
                            {--quality : Generate only the focused static quality report}
                            {--include-env : Include .env verbatim in full reports; enable env checks in quality-only mode (SENSITIVE)}';

    protected $description = 'Statically analyse the Laravel project and generate developer documentation';

    public function handle(ProjectAnalyzer $analyzer, RendererManager $renderers): int
    {
        $config = (array) config('project-docs', []);
        // Laravel has already loaded its normal configuration by the time an Artisan
        // command runs. Passing app.name here gives containerised projects a useful
        // display name without reading .env directly or executing project code.
        $config['application_name'] = (string) config('app.name', '');
        // .env inclusion is intentionally CLI-only. A checked-in config file must
        // never make secret material appear in generated documentation by accident.
        $config['include_env'] = (bool) $this->option('include-env');
        $config['report_mode'] = (bool) $this->option('quality') ? 'quality' : 'full';

        $formats = array_values(array_filter((array) $this->option('format')));
        if ($formats === []) {
            $formats = (array) ($config['formats'] ?? ['html', 'json']);
        }

        foreach ($formats as $format) {
            if (! in_array($format, $renderers->formats(), true)) {
                $this->components->error("Unsupported format [{$format}]. Available: ".implode(', ', $renderers->formats()));
                return self::FAILURE;
            }
        }

        $outputRelative = (string) ($this->option('path') ?: ($config['output_path'] ?? 'storage/project-docs'));
        $outputDirectory = base_path($outputRelative);

        if ((bool) $this->option('include-env')) {
            if ((bool) $this->option('quality')) {
                $this->components->warn('ENV REVIEW MODE: environment-file checks are enabled. The quality-only report does not embed .env values.');
            } else {
                $this->components->warn('SENSITIVE MODE: the complete .env file will be embedded when it exists. Generated output may contain passwords, API keys and tokens.');
            }
        }

        $this->components->info('Analysing Laravel project...');
        $this->components->info('Mode: STATIC / READ ONLY - no application business methods or tests are invoked by the analyser.');
        if ((bool) $this->option('quality')) {
            $this->components->info('Report: QUALITY ONLY - focused findings, score, scope and problem-code excerpts.');
        }

        $documentation = $analyzer->analyze(new ProjectContext(
            rootPath: base_path(),
            config: $config,
            includeSource: ! (bool) $this->option('no-source'),
        ));

        $renderFailures = [];
        foreach ($formats as $format) {
            try {
                $path = $renderers->render($format, $documentation, $outputDirectory);
                $this->components->twoColumnDetail(strtoupper($format), $path);
            } catch (\Throwable $exception) {
                $renderFailures[$format] = $exception->getMessage();
                $this->components->error(strtoupper($format).' FAILED: '.$exception->getMessage());
            }
        }

        $qualityReport = (array) ($documentation->sections['intelligence']['quality_report'] ?? []);
        $qualitySummary = (array) ($qualityReport['summary'] ?? []);
        $this->components->twoColumnDetail('Static findings', (string) ((int) ($qualityReport['finding_count'] ?? 0)));
        $this->components->twoColumnDetail('High / Critical', (string) (((int) ($qualitySummary['high'] ?? 0)) + ((int) ($qualitySummary['critical'] ?? 0))));
        $qualityScope = (array) ($qualityReport['scope'] ?? []);
        $this->components->twoColumnDetail('Quality scope', 'Application-owned code');
        $this->components->twoColumnDetail('Scaffold PHP skipped', (string) ((int) ($qualityScope['excluded_php_files'] ?? 0)));
        $frontendStack = array_values(array_filter(array_map('strval', (array) ($documentation->sections['project']['frontend_stack']['detected'] ?? []))));
        if ($frontendStack !== []) {
            $this->components->twoColumnDetail('Frontend stack', implode(', ', $frontendStack));
        }
        $coverage = (array) ($documentation->sections['coverage'] ?? []);
        $phpCoverage = (array) ($coverage['php'] ?? []);
        $frontendCoverage = (array) ($coverage['frontend'] ?? []);
        $this->components->twoColumnDetail('PHP coverage', (int) ($phpCoverage['structurally_parsed'] ?? 0).' / '.(int) ($phpCoverage['files'] ?? 0).' parsed');
        $this->components->twoColumnDetail('Frontend coverage', (int) ($frontendCoverage['source_included'] ?? 0).' / '.(int) ($frontendCoverage['files'] ?? 0).' source files included');
        $this->components->twoColumnDetail('Tests executed', '0');

        $warnings = count($documentation->warnings);
        if ($warnings > 0) {
            $this->components->warn("Documentation generated with {$warnings} scanner warning(s). See the output for details.");
        } else {
            $this->components->info('Documentation generated successfully.');
        }

        return $renderFailures === [] ? self::SUCCESS : self::FAILURE;
    }
}
