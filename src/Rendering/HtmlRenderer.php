<?php

namespace DevDocs\LaravelProjectDocs\Rendering;

use DevDocs\LaravelProjectDocs\Contracts\Renderer;
use DevDocs\LaravelProjectDocs\Data\ProjectDocumentation;
use DevDocs\LaravelProjectDocs\Support\SyntaxHighlighter;

class HtmlRenderer implements Renderer
{
    private bool $pdfMode = false;

    private bool $pdfIncludeSource = true;

    private bool $qualityOnlyMode = false;

    /** @var array<string,array{source:string,kind:string}> */
    private array $qualitySourceLookup = [];

    /** @var array<string,array<int,array<int,array<string,mixed>>>> */
    private array $qualitySourceFindings = [];

    private const PDF_SOURCE_LINES_PER_CHUNK = 18;

    public function __construct(private readonly SyntaxHighlighter $highlighter)
    {
    }

    public function format(): string
    {
        return 'html';
    }

    public function render(ProjectDocumentation $documentation, string $outputDirectory): string
    {
        $this->ensureDirectory($outputDirectory);
        $qualityOnly = (($documentation->meta['report_mode'] ?? 'full') === 'quality');
        $filename = $qualityOnly ? 'project-quality-report.html' : 'project-documentation.html';
        $path = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, $this->html($documentation));

        return $path;
    }

    public function pdfHtml(ProjectDocumentation $documentation, bool $includeSource = true): string
    {
        $previousMode = $this->pdfMode;
        $previousIncludeSource = $this->pdfIncludeSource;
        $this->pdfMode = true;
        $this->pdfIncludeSource = $includeSource;

        try {
            return $this->html($documentation);
        } finally {
            $this->pdfMode = $previousMode;
            $this->pdfIncludeSource = $previousIncludeSource;
        }
    }

    public function html(ProjectDocumentation $documentation): string
    {
        $data = $documentation->toArray();
        $meta = $data['meta'];
        $routes = $data['sections']['routes']['items'] ?? [];
        $phpFiles = $data['sections']['php']['items'] ?? [];
        $frontend = $data['sections']['frontend']['items'] ?? [];
        $relationships = $data['sections']['relationships']['items'] ?? [];
        $warnings = $data['warnings'] ?? [];
        $intelligence = $data['sections']['intelligence'] ?? [];
        $models = $intelligence['models'] ?? [];
        $validations = $intelligence['validation'] ?? [];
        $runtime = $intelligence['runtime'] ?? [];
        $workflows = $intelligence['workflows'] ?? [];
        $quality = $intelligence['quality'] ?? [];
        $qualityReport = (array) ($intelligence['quality_report'] ?? []);
        $this->qualitySourceFindings = $this->buildQualitySourceFindingMap($qualityReport);
        $possiblyUnused = $intelligence['possibly_unused'] ?? [];
        $usedBy = $intelligence['used_by'] ?? [];
        $allCalls = (array) ($intelligence['calls'] ?? []);
        $callsLookup = $this->buildCallsLookup($allCalls);
        $frontendMap = $intelligence['frontend_map'] ?? [];
        $frontendBackend = (array) ($intelligence['frontend_backend'] ?? []);
        $erd = (array) ($intelligence['erd'] ?? []);
        $databaseTables = $data['sections']['database']['tables'] ?? [];
        $projectInfo = $data['sections']['project'] ?? [];
        $coverage = (array) ($data['sections']['coverage'] ?? []);
        $frontendStack = (array) ($projectInfo['frontend_stack'] ?? []);
        $envFile = (array) ($projectInfo['env_file'] ?? []);
        $projectName = (string) ($meta['project_name'] ?? 'Laravel Project');
        $classIndex = $this->buildClassIndex($phpFiles);
        $modelCount = $this->summaryModelCount($phpFiles, $models);
        $classLookup = $this->buildClassLookup($classIndex);
        $pathLookup = $this->buildPathLookup($phpFiles, $frontend);
        $sourceFiles = array_merge($phpFiles, $frontend);
        $sourceTotal = count($sourceFiles);
        $sourceIncluded = count(array_filter($sourceFiles, static fn (array $file) => array_key_exists('source', $file) && $file['source'] !== null));
        $sourceLines = array_sum(array_map(static fn (array $file) => (int) ($file['source_meta']['lines'] ?? 0), $sourceFiles));
        $searchIndex = $this->buildGlobalSearchIndex($classIndex, $routes, $workflows, $models, $databaseTables, $runtime, $frontend, $qualityReport);

        if (($meta['report_mode'] ?? 'full') === 'quality') {
            return $this->qualityOnlyHtml(
                $documentation,
                $projectName,
                $qualityReport,
                (array) $quality,
                (array) $possiblyUnused,
                $phpFiles,
                $frontend,
                $coverage,
                $warnings,
            );
        }

        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $html .= '<title>'.$this->e($projectName).' - Developer Documentation</title>';
        $html .= '<style>'.$this->css().'</style></head><body><div class="doc-shell">';
        if (! $this->pdfMode) {
            $html .= $this->sidebar($warnings);
        }
        $html .= '<main class="doc-content">';

        $html .= '<header class="hero" id="top"><div class="hero-accent"></div><div class="hero-content">';
        $html .= '<p class="eyebrow">Laravel Project Documentation</p><h1>'.$this->e($projectName).'</h1>';
        $html .= '<p class="hero-copy">Generated technical reference for routes, application classes, frontend files and detected relationships.</p>';
        $html .= '<div class="hero-meta"><span>Generated '.$this->e((string) ($meta['generated_at'] ?? '')).'</span><span>Laravel '.$this->e((string) ($meta['laravel_version'] ?? '')).'</span><span>PHP '.$this->e((string) ($meta['php_version'] ?? '')).'</span>';
        if (! empty($meta['git_branch'])) { $html .= '<span>Git '.$this->e((string) $meta['git_branch']).' @ '.$this->e((string) ($meta['git_commit'] ?? '')).'</span>'; }
        $html .= '</div>';
        $html .= '</div></header>';

        $html .= $this->analysisModeBanner((array) $meta);
        $html .= $this->environmentStatusBanner($envFile);

        $html .= '<nav class="doc-nav" id="navigation" aria-label="Documentation navigation">';
        $html .= '<a href="#summary">Summary</a><a href="#contents">Contents</a><a href="#class-index">Classes</a><a href="#routes">Routes</a><a href="#workflows">Workflows</a><a href="#models">Models</a><a href="#database">Database</a><a href="#erd">ERD</a><a href="#frontend-backend">Frontend → Backend</a><a href="#validation">Validation</a><a href="#runtime">Runtime</a><a href="#dependencies">Dependencies</a><a href="#environment-file">Environment</a><a href="#quality">Quality</a><a href="#relationships">Relationships</a><a href="#call-graph">Call graph</a><a href="#php-classes">PHP</a><a href="#frontend">Frontend</a>';
        if ($warnings) {
            $html .= '<a href="#warnings">Warnings</a>';
        }
        $html .= '</nav>';

        $html .= '<section class="summary-section" id="summary"><div class="section-heading"><span class="section-number">01</span><div><p class="section-kicker">At a glance</p><h2>Project summary</h2></div></div><div class="cards">';
        $html .= $this->card('Routes', count($routes), 'Registered endpoints', '#routes');
        $html .= $this->card('Classes', count($classIndex), 'Indexed PHP types', '#class-index');
        $html .= $this->card('PHP files', count($phpFiles), 'Application source', '#php-classes');
        $stackLabel = $this->frontendStackLabel($frontendStack);
        $html .= $this->card('Frontend', count($frontend), $stackLabel !== '' ? $stackLabel : 'Frontend source', '#frontend');
        $html .= $this->card('Models', $modelCount, 'Model files', '#models');
        $html .= $this->card('DB tables', count($databaseTables), 'Migration schema', '#database');
        $html .= $this->card('Relationships', count($relationships), 'Detected links', '#relationships');
        $sourceDescription = $sourceIncluded === $sourceTotal ? '100% source included' : $sourceIncluded.' / '.$sourceTotal.' source files';
        $html .= $this->card('Source lines', $sourceLines, $sourceDescription, '#php-classes');
        $html .= $this->card('Findings', (int) ($qualityReport['finding_count'] ?? 0), 'Static review signals', '#quality');
        $html .= '</div>';
        $html .= $this->projectOverviewBlock($frontendStack, $coverage);
        $html .= $this->needsAttentionBlock($qualityReport, $classLookup);
        $html .= '</section>';

        $html .= $this->contentsSection($warnings);

        $html .= '<section id="class-index"><div class="section-heading"><span class="section-number">03</span><div><p class="section-kicker">Quick navigation</p><h2>Class index</h2></div></div>';
        $html .= '<p class="section-intro">Select a class to jump to its documentation. Blue SOURCE buttons jump directly to the relevant source code line in HTML and PDF.</p>';
        if ($classIndex) {
            $html .= '<div class="class-search-hint"><strong>'.count($classIndex).' classes indexed</strong><input id="class-search" type="search" placeholder="Search classes, namespace or path…" aria-label="Search class index"><span>Links work in HTML and PDF.</span></div>';
            $html .= '<div class="table-wrap"><table class="class-index-table"><thead><tr><th>Class</th><th>Type</th><th>Namespace</th><th>Location</th></tr></thead><tbody>';
            foreach ($classIndex as $entry) {
                $html .= '<tr><td><a class="nav-link class-index-name" href="#'.$this->e($entry['class_anchor']).'">'.$this->e($entry['name']).'</a></td>';
                $html .= '<td><span class="kind-badge">'.$this->e(ucfirst($entry['category'] !== 'class' ? $entry['category'] : $entry['kind'])).'</span></td>';
                $html .= '<td><code class="inline-code">'.$this->e($entry['namespace']).'</code></td>';
                $html .= '<td><a class="location-link source-link" href="#'.$this->e($entry['source_anchor']).'"><span class="source-link-label">SOURCE</span>'.$this->e($entry['path']).':'.$entry['start_line'].'</a></td></tr>';
            }
            $html .= '</tbody></table></div>';
        } else {
            $html .= '<p class="muted">No named PHP classes were discovered.</p>';
        }
        $html .= '</section>';

        $html .= '<section id="routes"><div class="section-heading"><span class="section-number">04</span><div><p class="section-kicker">HTTP surface</p><h2>Routes</h2></div></div>';
        $html .= '<div class="table-wrap"><table class="routes-table"><thead><tr><th>Methods</th><th>URI</th><th>Name</th><th>Action</th><th>Middleware</th></tr></thead><tbody>';
        foreach ($routes as $route) {
            $action = (string) ($route['action'] ?? '');
            $routeAnchor = $this->routeAnchor($route);
            $routeSearch = implode(' ', array_filter([implode('|', $route['methods'] ?? []), (string) ($route['uri'] ?? ''), (string) ($route['name'] ?? ''), $action, implode(' ', $route['middleware'] ?? [])]));
            $html .= '<tr id="'.$this->e($routeAnchor).'" data-search="'.$this->e($routeSearch).'"><td><code class="inline-code method-code">'.$this->e(implode('|', $route['methods'] ?? [])).'</code></td><td>'.$this->e((string) ($route['uri'] ?? '')).'</td><td>'.$this->e((string) ($route['name'] ?? '')).'</td><td>'.$this->linkedClassReference($action, $classLookup).'</td><td>'.$this->e(implode(', ', $route['middleware'] ?? [])).'</td></tr>';
        }
        $html .= '</tbody></table></div></section>';

        $html .= $this->workflowsSection($workflows, $classLookup);
        $html .= $this->modelsSection($models, $classLookup, $usedBy);
        $html .= $this->databaseSection($databaseTables);
        $html .= $this->erdSection($erd, $classLookup);
        $html .= $this->frontendBackendSection($frontendBackend, $classLookup, $pathLookup);
        $html .= $this->validationSection($validations, $classLookup);
        $html .= $this->runtimeSection($runtime, $classLookup, $usedBy);
        $html .= $this->dependenciesSection($projectInfo, $envFile);
        $html .= $this->qualitySection($qualityReport, $quality, $possiblyUnused, $classLookup);

        $html .= '<section id="relationships"><div class="section-heading"><span class="section-number">14</span><div><p class="section-kicker">Dependency map</p><h2>Application relationships</h2></div></div>';
        $html .= '<div class="table-wrap"><table class="relationships-table"><thead><tr><th>Type</th><th>From</th><th>To</th><th>Resolved file</th></tr></thead><tbody>';
        foreach ($relationships as $relationship) {
            $from = (string) ($relationship['from'] ?? '');
            $to = (string) ($relationship['to'] ?? '');
            $targetPath = (string) ($relationship['target_path'] ?? '');
            $html .= '<tr><td><span class="pill">'.$this->e((string) ($relationship['type'] ?? '')).'</span></td>';
            $html .= '<td>'.$this->linkedPathOrCode($from, $pathLookup).'</td>';
            $html .= '<td>'.$this->linkedClassReference($to, $classLookup).'</td>';
            $html .= '<td>'.($targetPath !== '' ? $this->linkedPath($targetPath, $pathLookup) : '<span class="muted">unresolved</span>').'</td></tr>';
        }
        $html .= '</tbody></table></div></section>';

        $html .= $this->callGraphSection($allCalls, $classLookup);

        $html .= '<section id="php-classes"><div class="section-heading"><span class="section-number">16</span><div><p class="section-kicker">Backend</p><h2>PHP classes and files</h2></div></div>';
        foreach ($phpFiles as $file) {
            $path = (string) ($file['path'] ?? '');
            $fileAnchor = $this->fileAnchor($path);
            $html .= '<article class="file" id="'.$this->e($fileAnchor).'"><div class="file-heading"><div class="file-icon">PHP</div><div><h3>'.$this->e($path).'</h3>';
            if (! empty($file['namespace'])) {
                $html .= '<p class="muted"><strong>Namespace</strong> <code class="inline-code">'.$this->e((string) $file['namespace']).'</code></p>';
            }
            $html .= '</div><a class="back-link" href="#class-index">Class index ↑</a></div>';

            $hasSource = array_key_exists('source', $file) && $file['source'] !== null;
            foreach (($file['classes'] ?? []) as $class) {
                $kind = ucfirst((string) ($class['kind'] ?? 'class'));
                $fqcn = ltrim((string) ($class['fqcn'] ?? ''), '\\');
                $classIdentity = $fqcn !== '' ? $fqcn : $path.'::'.(string) ($class['name'] ?? 'anonymous');
                $classAnchor = $this->classAnchor($classIdentity);
                $startLine = max(1, (int) ($class['start_line'] ?? 1));
                $endLine = max($startLine, (int) ($class['end_line'] ?? $startLine));
                $sourceAnchor = $hasSource ? $this->sourceLineAnchor($path, $startLine) : $fileAnchor;

                $html .= '<div class="class-block" id="'.$this->e($classAnchor).'" data-search="'.$this->e(trim($fqcn.' '.$path.' '.(string) ($class['category'] ?? 'class'))).'"><div class="class-title"><span class="kind-badge">'.$this->e($kind).'</span>';
                if (($class['category'] ?? 'class') !== 'class') { $html .= '<span class="category-badge">'.$this->e((string) $class['category']).'</span>'; }
                $html .= '<h4>'.$this->e((string) ($class['name'] ?? '')).'</h4><a class="class-location source-link" href="#'.$this->e($sourceAnchor).'"><span class="source-link-label">SOURCE</span>'.$this->e($path).':'.$startLine.'-'.$endLine.'</a></div>';
                if ($fqcn !== '') {
                    $html .= '<p class="class-fqcn"><code class="inline-code">'.$this->e($fqcn).'</code></p>';
                }
                $html .= '<p class="class-description">'.$this->e((string) ($class['description'] ?? '')).'</p>';
                if ($fqcn !== '' && ! empty($usedBy[$fqcn])) {
                    $html .= $this->usedByBlock($usedBy[$fqcn], $classLookup);
                }
                if (! empty($class['extends'])) {
                    $html .= '<p class="muted"><strong>Extends:</strong> '.$this->linkedClassReference((string) $class['extends'], $classLookup).'</p>';
                }
                if (! empty($class['implements'])) {
                    $implements = array_map(fn ($name) => $this->linkedClassReference((string) $name, $classLookup), $class['implements']);
                    $html .= '<p class="muted"><strong>Implements:</strong> '.implode(', ', $implements).'</p>';
                }

                if (! empty($class['dependencies'])) {
                    $html .= '<p class="dependency-line"><strong>Dependencies</strong> ';
                    foreach ($class['dependencies'] as $dependency) {
                        $html .= '<span class="dependency-chip">'.$this->e((string) ($dependency['variable'] ?? '')).' → '.$this->linkedClassReference((string) ($dependency['type'] ?? ''), $classLookup).'</span> ';
                    }
                    $html .= '</p>';
                }

                if (! empty($class['methods'])) {
                    $html .= '<table class="methods-table"><thead><tr><th>Method</th><th>Purpose</th><th>Parameters</th><th>Returns</th><th>Calls</th></tr></thead><tbody>';
                    foreach ($class['methods'] as $method) {
                        $params = array_map(function (array $p) {
                            return trim(($p['type'] ?? '').' $'.($p['name'] ?? ''));
                        }, $method['parameters'] ?? []);
                        $visibility = (string) ($method['visibility'] ?? '');
                        $methodLine = max(1, (int) ($method['start_line'] ?? 1));
                        $methodAnchor = $hasSource ? $this->sourceLineAnchor($path, $methodLine) : $classAnchor;
                        $callKey = $fqcn.'::'.(string) ($method['name'] ?? '');
                        $methodCalls = [];
                        foreach (array_slice($callsLookup[$callKey] ?? [], 0, 5) as $call) {
                            if (! empty($call['target_class'])) {
                                $label = (string) $call['target_class'].(! empty($call['target_method']) ? '::'.$call['target_method'].'()' : '');
                                $methodCalls[] = $this->linkedClassReference($label, $classLookup);
                            } elseif (! empty($call['target_raw']) && ! in_array($call['target_raw'], ['expression', 'dynamic'], true)) {
                                $methodCalls[] = '<code class="inline-code">'.$this->e((string) $call['target_raw']).'</code>';
                            }
                        }
                        $html .= '<tr><td><span class="visibility-badge">'.$this->e($visibility).'</span><a class="method-link source-link source-link-compact" href="#'.$this->e($methodAnchor).'"><code class="inline-code function-inline">'.$this->e((string) ($method['name'] ?? '')).'()</code><span class="source-link-mini">source</span></a></td><td>'.$this->e((string) ($method['description'] ?? '')).'</td><td><code class="inline-code">'.$this->e(implode(', ', $params)).'</code></td><td><code class="inline-code">'.$this->e((string) ($method['return_type'] ?? '')).'</code></td><td>'.($methodCalls ? implode('<br>', $methodCalls) : '<span class="muted">—</span>').'</td></tr>';
                    }
                    $html .= '</tbody></table>';
                }
                $html .= '<div class="class-actions"><a href="#class-index">Back to class index</a><a href="#'.$this->e($fileAnchor).'">File heading</a><a class="source-link source-action-link" href="#'.$this->e($sourceAnchor).'"><span class="source-link-label">SOURCE</span>View source line '.$startLine.' →</a></div></div>';
            }

            if (! empty($file['references']['views']) || ! empty($file['references']['inertia_pages'])) {
                $refs = array_merge($file['references']['views'] ?? [], $file['references']['inertia_pages'] ?? []);
                $html .= '<div class="refs"><strong>Framework references</strong><span>'.$this->e(implode(', ', $refs)).'</span></div>';
            }

            if (! empty($file['parse_error'])) {
                $html .= '<div class="source-warning"><strong>Parser warning:</strong> '.$this->e((string) $file['parse_error']).' <span>The raw source is still included below.</span></div>';
            }
            if (array_key_exists('source', $file) && $file['source'] !== null) {
                $html .= $this->sourceBlock((string) $file['source'], $path, 'php');
            } elseif (! empty($file['source_meta'])) {
                $html .= $this->sourceOmittedNotice((array) $file['source_meta']);
            }
            $html .= '</article>';
        }
        $html .= '</section>';

        $html .= '<section id="frontend"><div class="section-heading"><span class="section-number">17</span><div><p class="section-kicker">Frontend</p><h2>Frontend source</h2></div></div>';
        $html .= $this->frontendMapBlock($frontendMap, $pathLookup, $classLookup);
        foreach ($frontend as $file) {
            $path = (string) ($file['path'] ?? '');
            $kind = (string) ($file['kind'] ?? 'frontend');
            $fileAnchor = $this->fileAnchor($path);
            $html .= '<article class="file" id="'.$this->e($fileAnchor).'"><div class="file-heading"><div class="file-icon frontend-icon">'.$this->e(strtoupper(substr($kind, 0, 3))).'</div><div><h3>'.$this->e($path).'</h3><span class="pill">'.$this->e($kind).'</span></div><a class="back-link" href="#top">Top ↑</a></div>';
            foreach (($file['references'] ?? []) as $label => $refs) {
                if (! empty($refs)) {
                    $displayRefs = [];
                    foreach ((array) $refs as $ref) {
                        if (is_array($ref)) {
                            $displayRefs[] = trim((string) ($ref['method'] ?? '').' '.(string) ($ref['url'] ?? '').(! empty($ref['line']) ? ' @ line '.(int) $ref['line'] : ''));
                        } elseif (is_scalar($ref)) {
                            $displayRefs[] = (string) $ref;
                        }
                    }
                    $html .= '<p class="reference-line"><strong>'.$this->e(ucwords(str_replace('_', ' ', (string) $label))).'</strong><span>'.$this->e(implode(', ', array_filter($displayRefs))).'</span></p>';
                }
            }
            if (array_key_exists('source', $file) && $file['source'] !== null) {
                $html .= $this->sourceBlock((string) $file['source'], $path, $kind);
            } elseif (! empty($file['source_meta'])) {
                $html .= $this->sourceOmittedNotice((array) $file['source_meta']);
            }
            $html .= '</article>';
        }
        $html .= '</section>';

        if ($warnings) {
            $html .= '<section class="warnings" id="warnings"><div class="section-heading"><span class="section-number">18</span><div><p class="section-kicker">Review</p><h2>Warnings</h2></div></div><ul>';
            foreach ($warnings as $warning) {
                $html .= '<li><strong>'.$this->e((string) ($warning['scanner'] ?? 'scanner')).'</strong><span>'.$this->e((string) ($warning['message'] ?? '')).'</span></li>';
            }
            $html .= '</ul></section>';
        }

        $html .= '<a class="floating-top" href="#top" aria-label="Back to top">↑ Top</a><div class="pdf-page-nav"><a href="#top">↑ Back to top</a><span> · </span><a href="#navigation">Navigation</a></div>';
        if (! $this->pdfMode) {
            $html .= $this->searchScript($searchIndex);
        }
        $html .= '</main></div></body></html>';
        $html = str_replace('</section>', '<div class="section-footer"><a href="#top">↑ Back to top</a><a href="#navigation">Navigation</a></div></section>', $html);

        return $html;
    }

    /** @param array<int, array<string, mixed>> $calls */
    private function buildCallsLookup(array $calls): array
    {
        $lookup = [];
        foreach ($calls as $call) {
            $key = (string) ($call['from_class'] ?? '').'::'.(string) ($call['from_method'] ?? '');
            if ($key === '::') {
                continue;
            }
            $lookup[$key][] = $call;
        }
        return $lookup;
    }

    private function frontendMapBlock(array $frontendMap, array $pathLookup, array $classLookup): string
    {
        if ($frontendMap === []) {
            return '';
        }
        $html = '<div class="frontend-map"><h3 class="subheading">Component and backend links</h3><p class="section-intro">Static imports, Blade includes and resolvable frontend HTTP/route references.</p>';
        foreach ($frontendMap as $source => $links) {
            $html .= '<div class="frontend-map-row"><strong>'.$this->linkedPathOrCode((string) $source, $pathLookup).'</strong><div>';
            foreach ($links as $link) {
                $type = (string) ($link['type'] ?? 'reference');
                $to = (string) ($link['to'] ?? '');
                $targetPath = (string) ($link['target_path'] ?? '');
                $content = $this->linkedClassReference($to, $classLookup);
                if ($targetPath !== '' && $content === '<code class="inline-code">'.$this->e($to).'</code>') {
                    $content = $this->linkedPath($targetPath, $pathLookup);
                }
                $html .= '<span class="frontend-map-link"><em>'.$this->e($type).'</em>'.$content.'</span>';
            }
            $html .= '</div></div>';
        }
        return $html.'</div>';
    }

    /** @param array<int, array<string, mixed>> $workflows */
    private function workflowsSection(array $workflows, array $classLookup): string
    {
        $html = '<section id="workflows"><div class="section-heading"><span class="section-number">05</span><div><p class="section-kicker">Feature paths</p><h2>Application workflows</h2></div></div>';
        $html .= '<p class="section-intro">Route-to-code flows inferred from registered routes, controller methods, resolved calls, jobs and returned views. These are static-analysis guides rather than runtime traces.</p>';
        if ($workflows === []) {
            return $html.'<p class="muted">No controller workflows could be resolved.</p></section>';
        }

        foreach ($workflows as $workflow) {
            $workflowAnchor = $this->workflowAnchor($workflow);
            $workflowSearch = trim((string) ($workflow['name'] ?? '').' '.implode('|', $workflow['methods'] ?? []).' '.(string) ($workflow['uri'] ?? '').' '.(string) ($workflow['controller'] ?? ''));
            $html .= '<div class="workflow-card" id="'.$this->e($workflowAnchor).'" data-search="'.$this->e($workflowSearch).'"><div class="workflow-title"><strong>'.$this->e((string) ($workflow['name'] ?? 'Workflow')).'</strong><code class="inline-code">'.$this->e(implode('|', $workflow['methods'] ?? [])).' '.$this->e((string) ($workflow['uri'] ?? '')).'</code></div><div class="workflow-steps">';
            foreach (($workflow['steps'] ?? []) as $index => $step) {
                if ($index > 0) {
                    $html .= '<span class="workflow-arrow">↓</span>';
                }
                $label = (string) ($step['label'] ?? '');
                $type = (string) ($step['type'] ?? 'step');
                if (! empty($step['class'])) {
                    $content = $this->linkedClassReference($label, $classLookup);
                } elseif (! empty($step['table'])) {
                    $content = '<a class="nav-link" href="#'.$this->e($this->tableAnchor((string) $step['table'])).'">'.$this->e($label).'</a>';
                } else {
                    $content = $this->e($label);
                }
                $html .= '<div class="workflow-step"><span class="workflow-type">'.$this->e($type).'</span><div>'.$content.'</div></div>';
            }
            $html .= '</div></div>';
        }
        return $html.'</section>';
    }

    /** @param array<int, array<string, mixed>> $models */
    private function modelsSection(array $models, array $classLookup, array $usedBy): string
    {
        $html = '<section id="models"><div class="section-heading"><span class="section-number">06</span><div><p class="section-kicker">Eloquent</p><h2>Models and relationships</h2></div></div>';
        $html .= '<p class="section-intro">Detected Eloquent configuration, mass-assignment settings, casts and relationship methods.</p>';
        if ($models === []) {
            return $html.'<p class="muted">No Eloquent models were detected.</p></section>';
        }

        foreach ($models as $model) {
            $modelClass = (string) ($model['class'] ?? '');
            $modelAnchor = $modelClass !== '' ? $this->classAnchor($modelClass) : $this->tableAnchor((string) ($model['table_effective'] ?? 'model'));
            $html .= '<div class="intel-card" data-search="'.$this->e($modelClass.' '.(string) ($model['table_effective'] ?? '')).'"><div class="intel-card-title"><h3><a class="nav-link" href="#'.$this->e($modelAnchor).'">'.$this->e($modelClass !== '' ? $this->classPart($modelClass) : (string) ($model['name'] ?? 'Model')).'</a></h3><span class="category-badge">model</span></div>';
            if ($modelClass !== '' && ! empty($usedBy[$modelClass])) {
                $html .= $this->usedByBlock((array) $usedBy[$modelClass], $classLookup);
            }
            $html .= '<div class="meta-grid">'.$this->metaItem('Table', (string) ($model['table_effective'] ?? ''))
                .$this->metaItem('Connection', (string) (($model['connection'] ?? null) ?: 'default'))
                .$this->metaItem('Primary key', (string) ($model['primary_key'] ?? 'id'))
                .$this->metaItem('Timestamps', ($model['timestamps'] ?? true) ? 'yes' : 'no')
                .$this->metaItem('Soft deletes', ($model['soft_deletes'] ?? false) ? 'yes' : 'no').'</div>';

            foreach (['fillable' => 'Fillable', 'guarded' => 'Guarded', 'hidden' => 'Hidden'] as $key => $label) {
                if (! empty($model[$key])) {
                    $html .= '<p class="chip-line"><strong>'.$label.'</strong> '.$this->chips((array) $model[$key]).'</p>';
                }
            }
            if (! empty($model['casts'])) {
                $castParts = [];
                foreach ((array) $model['casts'] as $field => $cast) {
                    $castParts[] = is_string($field) ? $field.' → '.(is_scalar($cast) ? (string) $cast : json_encode($cast)) : (string) $cast;
                }
                $html .= '<p class="chip-line"><strong>Casts</strong> '.$this->chips($castParts).'</p>';
            }
            if (! empty($model['relationships'])) {
                $html .= '<table class="compact-table"><thead><tr><th>Method</th><th>Relationship</th><th>Target</th></tr></thead><tbody>';
                foreach ($model['relationships'] as $relation) {
                    $html .= '<tr><td><code class="inline-code function-inline">'.$this->e((string) ($relation['method'] ?? '')).'()</code></td><td>'.$this->e((string) ($relation['type'] ?? '')).'</td><td>'.$this->linkedClassReference((string) ($relation['target'] ?? 'dynamic'), $classLookup).'</td></tr>';
                }
                $html .= '</tbody></table>';
            }
            $html .= '</div>';
        }
        return $html.'</section>';
    }

    /** @param array<int, array<string, mixed>> $tables */
    private function databaseSection(array $tables): string
    {
        $html = '<section id="database"><div class="section-heading"><span class="section-number">07</span><div><p class="section-kicker">Schema</p><h2>Database structure</h2></div></div>';
        $html .= '<p class="section-intro">Schema inferred from Laravel migrations. Runtime-only database changes may not be represented.</p>';
        if ($tables === []) {
            return $html.'<p class="muted">No migration-created or modified tables were detected.</p></section>';
        }

        foreach ($tables as $table) {
            $tableName = (string) ($table['name'] ?? '');
            $html .= '<div class="intel-card table-card" id="'.$this->e($this->tableAnchor($tableName)).'" data-search="'.$this->e('database table '.$tableName).'"><div class="intel-card-title"><h3><code class="inline-code table-name">'.$this->e($tableName).'</code></h3>';
            if (! empty($table['created_by'])) {
                $html .= '<span class="muted">Created by '.$this->e((string) $table['created_by']).'</span>';
            }
            $html .= '</div>';
            if (! empty($table['columns'])) {
                $html .= '<table class="compact-table"><thead><tr><th>Column</th><th>Type</th><th>Modifiers / arguments</th></tr></thead><tbody>';
                foreach ($table['columns'] as $column) {
                    $extra = array_merge((array) ($column['arguments'] ?? []), (array) ($column['modifiers'] ?? []));
                    $html .= '<tr><td><code class="inline-code">'.$this->e((string) ($column['name'] ?? '')).'</code></td><td>'.$this->e((string) ($column['type'] ?? '')).'</td><td>'.$this->e(implode(', ', $extra)).'</td></tr>';
                }
                $html .= '</tbody></table>';
            }
            if (! empty($table['foreign_keys'])) {
                $html .= '<p class="chip-line"><strong>Foreign keys</strong> ';
                $links = [];
                foreach ($table['foreign_keys'] as $foreign) {
                    $links[] = ($foreign['column'] ?? '?').' → '.($foreign['table'] ?? '?').'.'.($foreign['references'] ?? 'id');
                }
                $html .= $this->chips($links).'</p>';
            }
            $html .= '</div>';
        }
        return $html.'</section>';
    }

    /** @param array<string,mixed> $erd */
    private function erdSection(array $erd, array $classLookup): string
    {
        $nodes = (array) ($erd['nodes'] ?? []);
        $edges = (array) ($erd['edges'] ?? []);
        $html = '<section id="erd"><div class="section-heading"><span class="section-number">08</span><div><p class="section-kicker">Data map</p><h2>Entity relationship diagram</h2></div></div>';
        $html .= '<p class="section-intro">Combined migration foreign keys and resolvable Eloquent relationships. Table cards link back to the detailed schema.</p>';
        if ($nodes === []) {
            return $html.'<p class="muted">No database entities were available for the ERD.</p></section>';
        }

        $html .= '<div class="erd-grid">';
        foreach ($nodes as $node) {
            $table = (string) ($node['table'] ?? '');
            $html .= '<a class="erd-node" href="#'.$this->e($this->tableAnchor($table)).'" data-search="'.$this->e('erd '.$table).'"><strong>'.$this->e($table).'</strong>';
            if (! empty($node['models'])) {
                $html .= '<span class="erd-models">'.implode(', ', array_map(fn ($model) => $this->e($this->classPart((string) $model)), (array) $node['models'])).'</span>';
            }
            $columns = array_slice((array) ($node['columns'] ?? []), 0, 7);
            foreach ($columns as $column) {
                $html .= '<small><code>'.$this->e((string) ($column['name'] ?? '')).'</code><em>'.$this->e((string) ($column['type'] ?? '')).'</em></small>';
            }
            if (count((array) ($node['columns'] ?? [])) > 7) {
                $html .= '<small class="erd-more">+'.(count((array) $node['columns']) - 7).' more columns</small>';
            }
            $html .= '</a>';
        }
        $html .= '</div>';

        if ($edges !== []) {
            $html .= '<div class="table-wrap erd-links"><table class="compact-table"><thead><tr><th>From</th><th>Relationship</th><th>To</th><th>Source</th></tr></thead><tbody>';
            foreach ($edges as $edge) {
                $from = (string) ($edge['from_table'] ?? '');
                $to = (string) ($edge['to_table'] ?? '');
                $html .= '<tr><td><a class="nav-link" href="#'.$this->e($this->tableAnchor($from)).'">'.$this->e($from).'</a></td><td>'.$this->e((string) ($edge['label'] ?? '')).'</td><td><a class="nav-link" href="#'.$this->e($this->tableAnchor($to)).'">'.$this->e($to).'</a></td><td><span class="pill">'.$this->e((string) ($edge['type'] ?? '')).'</span></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        return $html.'</section>';
    }

    /** @param array<int,array<string,mixed>> $items */
    private function frontendBackendSection(array $items, array $classLookup, array $pathLookup): string
    {
        $html = '<section id="frontend-backend"><div class="section-heading"><span class="section-number">09</span><div><p class="section-kicker">Request tracing</p><h2>Frontend → backend map</h2></div></div>';
        $html .= '<p class="section-intro">Static Axios/fetch and Blade route references mapped to Laravel routes and controller actions where possible.</p>';
        if ($items === []) {
            return $html.'<p class="muted">No frontend-to-backend references were detected.</p></section>';
        }
        $html .= '<div class="trace-list">';
        foreach ($items as $item) {
            $frontend = (string) ($item['frontend'] ?? '');
            $method = (string) ($item['http_method'] ?? '');
            $uri = (string) ($item['route_uri'] ?? '');
            $controller = (string) ($item['controller'] ?? '');
            $controllerMethod = (string) ($item['controller_method'] ?? '');
            $html .= '<div class="trace-card" data-search="'.$this->e($frontend.' '.$method.' '.$uri.' '.$controller.' '.$controllerMethod).'">';
            $html .= '<div class="trace-step"><span>Frontend</span>'.$this->linkedPath($frontend, $pathLookup).(! empty($item['source_line']) ? '<small>line '.(int) $item['source_line'].'</small>' : '').'</div><b>→</b>';
            if ($uri !== '') {
                $html .= '<div class="trace-step"><span>Route</span><code class="inline-code">'.$this->e(trim($method.' '.$uri)).'</code></div><b>→</b>';
            } else {
                $html .= '<div class="trace-step trace-unresolved"><span>Route</span><code class="inline-code">unresolved</code></div><b>→</b>';
            }
            $html .= '<div class="trace-step"><span>Controller</span>'.($controller !== '' ? $this->linkedClassReference($controller.($controllerMethod !== '' ? '::'.$controllerMethod.'()' : ''), $classLookup) : '<span class="muted">unresolved</span>').'</div>';
            $html .= '</div>';
        }
        return $html.'</div></section>';
    }

    /** @param array<int,array<string,mixed>> $calls */
    private function callGraphSection(array $calls, array $classLookup): string
    {
        $html = '<section id="call-graph"><div class="section-heading"><span class="section-number">15</span><div><p class="section-kicker">Execution links</p><h2>Class and method call graph</h2></div></div>';
        $html .= '<p class="section-intro">Resolved static calls and injected-service method calls. Dynamic calls are listed only when a meaningful target could be inferred.</p>';
        $resolved = array_values(array_filter($calls, static fn (array $call): bool => ! empty($call['target_class'])));
        if ($resolved === []) {
            return $html.'<p class="muted">No resolvable cross-class calls were detected.</p></section>';
        }
        $html .= '<div class="table-wrap"><table class="compact-table"><thead><tr><th>Caller</th><th>Calls</th><th>Type</th><th>Line</th></tr></thead><tbody>';
        foreach ($resolved as $call) {
            $from = (string) ($call['from_class'] ?? '').'::'.(string) ($call['from_method'] ?? '').'()';
            $to = (string) ($call['target_class'] ?? '').(! empty($call['target_method']) ? '::'.$call['target_method'].'()' : '');
            $html .= '<tr data-search="'.$this->e($from.' '.$to).'"><td>'.$this->linkedClassReference($from, $classLookup).'</td><td>'.$this->linkedClassReference($to, $classLookup).'</td><td><span class="pill">'.$this->e((string) ($call['type'] ?? 'call')).'</span></td><td>'.(int) ($call['line'] ?? 0).'</td></tr>';
        }
        return $html.'</tbody></table></div></section>';
    }

    /** @param array<int, array<string, mixed>> $validations */
    private function validationSection(array $validations, array $classLookup): string
    {
        $html = '<section id="validation"><div class="section-heading"><span class="section-number">10</span><div><p class="section-kicker">Input contracts</p><h2>Form Request validation</h2></div></div>';
        if ($validations === []) {
            return $html.'<p class="muted">No Form Request <code class="inline-code">rules()</code> arrays were detected.</p></section>';
        }
        foreach ($validations as $validation) {
            $html .= '<div class="intel-card"><div class="intel-card-title"><h3>'.$this->linkedClassReference((string) ($validation['class'] ?? ''), $classLookup).'</h3><span class="category-badge">request</span></div>';
            $html .= '<table class="compact-table"><thead><tr><th>Field</th><th>Rules</th></tr></thead><tbody>';
            foreach (($validation['rules'] ?? []) as $field => $rules) {
                $text = is_array($rules) ? implode(' | ', array_map('strval', $rules)) : (string) $rules;
                $html .= '<tr><td><code class="inline-code">'.$this->e((string) $field).'</code></td><td><code class="inline-code">'.$this->e($text).'</code></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        return $html.'</section>';
    }

    /** @param array<int, array<string, mixed>> $runtime */
    private function runtimeSection(array $runtime, array $classLookup, array $usedBy): string
    {
        $html = '<section id="runtime"><div class="section-heading"><span class="section-number">11</span><div><p class="section-kicker">Runtime behaviour</p><h2>Jobs, events, mail, policies and middleware</h2></div></div>';
        if ($runtime === []) {
            return $html.'<p class="muted">No runtime/support classes were classified.</p></section>';
        }
        $html .= '<div class="table-wrap"><table class="runtime-table"><thead><tr><th>Type</th><th>Class</th><th>Key methods</th><th>Used by</th><th>Location</th></tr></thead><tbody>';
        foreach ($runtime as $item) {
            $runtimeClass = (string) ($item['class'] ?? '');
            $usedCount = count((array) ($usedBy[$runtimeClass] ?? []));
            $html .= '<tr data-search="'.$this->e($runtimeClass.' '.(string) ($item['category'] ?? '')).'"><td><span class="category-badge">'.$this->e((string) ($item['category'] ?? 'class')).'</span></td><td>'.$this->linkedClassReference($runtimeClass, $classLookup).'</td><td>'.$this->e(implode(', ', array_slice((array) ($item['methods'] ?? []), 0, 8))).'</td><td>'.$usedCount.'</td><td><code class="inline-code">'.$this->e((string) ($item['path'] ?? '')).'</code></td></tr>';
        }
        return $html.'</tbody></table></div></section>';
    }

    private function dependenciesSection(array $project, array $envFile): string
    {
        $composer = $project['composer'] ?? [];
        $npm = $project['npm'] ?? [];
        $environment = $project['environment'] ?? [];
        $html = '<section id="dependencies"><div class="section-heading"><span class="section-number">12</span><div><p class="section-kicker">Project requirements</p><h2>Dependencies and environment</h2></div></div>';
        $html .= '<div class="dependency-columns">';
        $html .= $this->packageList('Composer', array_merge((array) ($composer['require'] ?? []), (array) ($composer['require_dev'] ?? [])));
        $html .= $this->packageList('NPM', array_merge((array) ($npm['dependencies'] ?? []), (array) ($npm['dev_dependencies'] ?? [])));
        $html .= '</div>';
        $html .= '<h3 class="subheading">Environment keys</h3>';
        if (! empty($envFile['included'])) {
            $html .= '<p class="section-intro env-sensitive-copy"><strong>Warning:</strong> this document was generated with <code class="inline-code">--include-env</code>, so the complete <code class="inline-code">.env</code> file is included below and may contain secrets.</p>';
        } else {
            $html .= '<p class="section-intro">Only key names referenced by config are documented here. Secret values are not read unless <code class="inline-code">--include-env</code> is explicitly supplied.</p>';
        }
        if ($environment === []) {
            $html .= '<p class="muted">No <code class="inline-code">env()</code> keys were detected in config.</p>';
        } else {
            $html .= '<div class="table-wrap"><table class="compact-table"><thead><tr><th>Key</th><th>Referenced in</th></tr></thead><tbody>';
            foreach ($environment as $env) {
                $html .= '<tr><td><code class="inline-code env-key">'.$this->e((string) ($env['key'] ?? '')).'</code></td><td>'.$this->e(implode(', ', (array) ($env['files'] ?? []))).'</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= $this->environmentFileBlock($envFile);
        return $html.'</section>';
    }


    /** @param array<string,mixed> $frontendStack @param array<string,mixed> $coverage */
    private function projectOverviewBlock(array $frontendStack, array $coverage): string
    {
        $detected = array_values(array_filter(array_map('strval', (array) ($frontendStack['detected'] ?? []))));
        $php = (array) ($coverage['php'] ?? []);
        $frontend = (array) ($coverage['frontend'] ?? []);
        $routes = (array) ($coverage['routes'] ?? []);
        $database = (array) ($coverage['database'] ?? []);
        $quality = (array) ($coverage['quality'] ?? []);
        $complete = (bool) ($coverage['complete'] ?? false);

        $html = '<div class="release-overview"><div class="overview-panel"><h3>Detected frontend stack</h3>';
        if ($detected === []) {
            $html .= '<p class="muted">No recognised frontend framework/tooling package was detected from Composer/package.json metadata.</p>';
        } else {
            $html .= '<div class="stack-chips">';
            foreach ($detected as $name) {
                $html .= '<span>'.$this->e($name).'</span>';
            }
            $html .= '</div>';
        }
        $html .= '</div><div class="overview-panel"><h3>Analysis coverage <span class="coverage-state '.($complete ? 'coverage-complete' : 'coverage-review').'">'.($complete ? 'COMPLETE' : 'REVIEW').'</span></h3>';
        $html .= '<div class="coverage-grid">';
        $html .= $this->coverageItem('PHP', (int) ($php['structurally_parsed'] ?? 0).' / '.(int) ($php['files'] ?? 0), (int) ($php['parse_errors'] ?? 0).' parser error(s)');
        $html .= $this->coverageItem('Frontend', (int) ($frontend['source_included'] ?? 0).' / '.(int) ($frontend['files'] ?? 0), (int) ($frontend['read_errors'] ?? 0).' read error(s)');
        $html .= $this->coverageItem('Routes', (string) ((int) ($routes['routes'] ?? 0)), (int) ($routes['file_errors'] ?? 0).' route-file error(s)');
        $html .= $this->coverageItem('Migrations', (string) ((int) ($database['migrations_parsed'] ?? 0)), (int) ($database['migration_errors'] ?? 0).' migration error(s)');
        $html .= $this->coverageItem('Quality', (string) ((int) ($quality['reviewed_php_files'] ?? 0)), (int) ($quality['excluded_php_files'] ?? 0).' scaffold PHP skipped');
        $html .= $this->coverageItem('Scanner warnings', (string) ((int) ($coverage['scanner_warnings'] ?? 0)), 'Generation continues when possible');
        $html .= '</div>';
        $issues = array_values(array_filter((array) ($coverage['issues'] ?? []), 'is_array'));
        if ($issues !== []) {
            $html .= '<div class="coverage-issues"><strong>Recovered analysis issues</strong>';
            foreach (array_slice($issues, 0, 5) as $issue) {
                $html .= '<span><b>'.$this->e((string) ($issue['type'] ?? 'Issue')).'</b>'.$this->e((string) ($issue['path'] ?? '')).'</span>';
            }
            if (count($issues) > 5) {
                $html .= '<small>+'.(count($issues) - 5).' more issue(s) are represented in the detailed report / JSON output.</small>';
            }
            $html .= '</div>';
        }
        $html .= '</div></div>';

        return $html;
    }

    private function coverageItem(string $label, string $value, string $detail): string
    {
        return '<div class="coverage-item"><span>'.$this->e($label).'</span><strong>'.$this->e($value).'</strong><small>'.$this->e($detail).'</small></div>';
    }

    /** @param array<string,mixed> $report */
    private function needsAttentionBlock(array $report, array $classLookup): string
    {
        $ranked = $this->topQualityFindings($report, 10);
        if ($ranked === []) {
            return '<div class="needs-attention needs-attention-clear"><div><strong>Needs attention</strong><span>No static findings currently require review.</span></div><a href="#quality">Open quality section →</a></div>';
        }

        $html = '<div class="needs-attention"><div class="attention-heading"><div><strong>Needs attention</strong><span>Top '.count($ranked).' findings by severity and confidence. Full detail remains in Quality.</span></div><a href="#quality">Open full quality review →</a></div><div class="attention-list">';
        foreach ($ranked as $entry) {
            $finding = $entry['finding'];
            $index = (int) $entry['index'];
            $severity = strtolower((string) ($finding['severity'] ?? 'low'));
            $tier = $this->qualityTier($severity);
            $path = (string) ($finding['path'] ?? '');
            $line = (int) ($finding['line'] ?? $finding['start_line'] ?? 0);
            $location = $path.($line > 0 ? ':'.$line : '');
            $anchor = $this->qualityFindingAnchor($finding, $index);
            $html .= '<a class="attention-row attention-'.$this->e($severity).'" href="#'.$this->e($anchor).'"><span class="attention-tier">'.$this->e($tier).'</span><strong>'.$this->e((string) ($finding['title'] ?? 'Finding')).'</strong><small>'.$this->e($location).'</small><b>→</b></a>';
        }
        return $html.'</div></div>';
    }

    /** @param array<string,mixed> $report @return array<int,array{index:int,finding:array<string,mixed>}> */
    private function topQualityFindings(array $report, int $limit): array
    {
        $entries = [];
        foreach ((array) ($report['findings'] ?? []) as $index => $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $entries[] = ['index' => (int) $index, 'finding' => $finding];
        }
        $severityRank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        $confidenceRank = ['high' => 3, 'medium' => 2, 'low' => 1];
        usort($entries, static function (array $a, array $b) use ($severityRank, $confidenceRank): int {
            $af = $a['finding'];
            $bf = $b['finding'];
            $severity = ($severityRank[strtolower((string) ($bf['severity'] ?? 'low'))] ?? 0) <=> ($severityRank[strtolower((string) ($af['severity'] ?? 'low'))] ?? 0);
            if ($severity !== 0) {
                return $severity;
            }
            $confidence = ($confidenceRank[strtolower((string) ($bf['confidence'] ?? 'medium'))] ?? 0) <=> ($confidenceRank[strtolower((string) ($af['confidence'] ?? 'medium'))] ?? 0);
            if ($confidence !== 0) {
                return $confidence;
            }
            return [(string) ($af['path'] ?? ''), (int) ($af['line'] ?? 0)] <=> [(string) ($bf['path'] ?? ''), (int) ($bf['line'] ?? 0)];
        });

        return array_slice($entries, 0, max(0, $limit));
    }

    private function qualityTier(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical', 'high' => 'ERROR',
            'medium' => 'WARNING',
            default => 'OBSERVATION',
        };
    }

    /** @param array<string,mixed> $frontendStack */
    private function frontendStackLabel(array $frontendStack): string
    {
        $detected = array_values(array_filter(array_map('strval', (array) ($frontendStack['detected'] ?? []))));
        if ($detected === []) {
            return '';
        }
        $preferred = array_values(array_filter($detected, static fn (string $name): bool => ! in_array($name, ['Vite', 'TypeScript', 'Tailwind CSS'], true)));
        $labels = $preferred !== [] ? $preferred : $detected;
        return implode(' · ', array_slice($labels, 0, 2));
    }

    private function contentsSection(array $warnings): string
    {
        $items = [
            ['03', 'Class index', 'All named PHP classes and direct source links', '#class-index'],
            ['04', 'Routes', 'Static HTTP route map', '#routes'],
            ['05', 'Workflows', 'Route-to-application feature paths', '#workflows'],
            ['06', 'Models', 'Eloquent models and relationships', '#models'],
            ['07', 'Database', 'Migration-derived schema', '#database'],
            ['08', 'ERD', 'Entity relationship view', '#erd'],
            ['09', 'Frontend → Backend', 'Frontend request tracing', '#frontend-backend'],
            ['10', 'Validation', 'Form Request rules', '#validation'],
            ['11', 'Runtime', 'Jobs, events, policies and middleware', '#runtime'],
            ['12', 'Dependencies', 'Composer, NPM and environment keys', '#dependencies'],
            ['13', 'Quality', 'Static findings with highlighted source', '#quality'],
            ['14', 'Relationships', 'Detected application links', '#relationships'],
            ['15', 'Call graph', 'Class and method calls', '#call-graph'],
            ['16', 'PHP source', 'Backend classes and complete source', '#php-classes'],
            ['17', 'Frontend source', 'Blade / Vue / React / JS / TS source', '#frontend'],
        ];
        if ($warnings !== []) {
            $items[] = ['18', 'Scanner warnings', 'Generation issues that were safely recovered', '#warnings'];
        }

        $html = '<section id="contents" class="contents-section"><div class="section-heading"><span class="section-number">02</span><div><p class="section-kicker">Document map</p><h2>Contents & navigation</h2></div></div><p class="section-intro">Every item is clickable in HTML and PDF. Source buttons throughout the report jump to exact source lines.</p><div class="contents-grid">';
        foreach ($items as [$number, $title, $description, $href]) {
            $html .= '<a class="contents-item" href="'.$this->e($href).'"><span>'.$this->e($number).'</span><div><strong>'.$this->e($title).'</strong><small>'.$this->e($description).'</small></div><b>→</b></a>';
        }
        return $html.'</div></section>';
    }

    /**
     * @param array<string,mixed> $report
     * @param array<int,array<string,mixed>> $legacyQuality
     * @param array<int,array<string,mixed>> $possiblyUnused
     */
    private function qualitySection(array $report, array $legacyQuality, array $possiblyUnused, array $classLookup): string
    {
        $summary = (array) ($report['summary'] ?? []);
        $findings = (array) ($report['findings'] ?? []);
        $score = (int) ($report['score'] ?? 100);
        $sectionNumber = $this->qualityOnlyMode ? '01' : '13';
        $html = '<section id="quality"><div class="section-heading"><span class="section-number">'.$sectionNumber.'</span><div><p class="section-kicker">Static code review</p><h2>Code quality & risk analysis</h2></div></div>';
        $html .= '<div class="static-analysis-note"><strong>STATIC / READ-ONLY ANALYSIS</strong><span>No application business methods, database queries, external HTTP requests, jobs, events, mail, migrations or tests are executed by this analyser.</span></div>';
        $scope = (array) ($report['scope'] ?? []);
        $html .= '<div class="static-analysis-note"><strong>QUALITY SCOPE: APPLICATION-OWNED CODE ONLY</strong><span>Laravel/framework and common official starter-kit scaffolding are documented but excluded from quality findings and the review score. Framework inheritance is respected, so Eloquent relationships, framework hooks and protected framework configuration are not treated as unused merely because their caller lives in a parent/framework class. Reviewed PHP files: '.(int) ($scope['reviewed_php_files'] ?? 0).' · excluded scaffold PHP files: '.(int) ($scope['excluded_php_files'] ?? 0).'.</span></div>';
        $html .= '<p class="section-intro">Findings are source-analysis signals with confidence levels. They are review prompts, not proof that code is incorrect or unreachable.</p>';
        $sourceLegend = $this->qualityOnlyMode
            ? 'View source jumps to a compact syntax-highlighted problem-code excerpt inside this quality report.'
            : 'View source jumps to the highlighted problem line; the source badge links back to the finding.';
        $html .= '<div class="source-quality-legend"><strong>Source highlighting</strong><span class="legend-critical">Critical</span><span class="legend-high">High</span><span class="legend-medium">Medium</span><span class="legend-low">Low</span><small>'.$this->e($sourceLegend).'</small></div>';
        $html .= '<div class="quality-summary">';
        $html .= '<div class="quality-score"><span>Review score</span><strong>'.$score.'</strong><small>/ 100</small></div>';
        foreach (['critical','high','medium','low'] as $severity) {
            $html .= '<div class="quality-stat quality-'.$severity.'"><span>'.ucfirst($severity).'</span><strong>'.(int) ($summary[$severity] ?? 0).'</strong></div>';
        }
        $html .= '<div class="quality-stat"><span>Total findings</span><strong>'.count($findings).'</strong></div></div>';
        $errorCount = (int) ($summary['critical'] ?? 0) + (int) ($summary['high'] ?? 0);
        $warningCount = (int) ($summary['medium'] ?? 0);
        $observationCount = (int) ($summary['low'] ?? 0);
        $html .= '<div class="review-tier-summary"><span><strong>Errors / high-risk signals</strong>'.$errorCount.'</span><span><strong>Warnings</strong>'.$warningCount.'</span><span><strong>Observations</strong>'.$observationCount.'</span><small>These are static review groups, not test failures.</small></div>';

        if ($findings === []) {
            $html .= '<div class="success-note">No static quality findings were detected by the current rules.</div>';
        } else {
            $html .= '<div class="quality-findings">';
            foreach ($findings as $findingIndex => $finding) {
                $severity = strtolower((string) ($finding['severity'] ?? 'low'));
                $confidence = strtolower((string) ($finding['confidence'] ?? 'medium'));
                $path = (string) ($finding['path'] ?? '');
                $line = isset($finding['line']) ? (int) $finding['line'] : null;
                $class = (string) ($finding['class'] ?? '');
                $method = (string) ($finding['method'] ?? '');
                $location = $path.($line ? ':'.$line : '');
                $href = $this->qualityOnlyMode
                    ? ($path !== '' && $line ? '#'.$this->qualitySnippetAnchor((int) $findingIndex) : null)
                    : ($path !== '' && $line ? '#'.$this->sourceLineAnchor($path, $line) : ($class !== '' ? '#'.$this->classAnchor($class) : null));
                $findingAnchor = $this->qualityFindingAnchor($finding, (int) $findingIndex);
                $findingCode = $this->qualityFindingCode((int) $findingIndex);
                $html .= '<article class="quality-finding severity-'.$this->e($severity).'" id="'.$this->e($findingAnchor).'">';
                $html .= '<div class="quality-finding-head"><span class="quality-code">'.$this->e($findingCode).'</span><span class="severity-badge severity-'.$this->e($severity).'">'.$this->e(strtoupper($severity)).'</span><span class="confidence-badge">'.$this->e(strtoupper($confidence)).' CONFIDENCE</span><span class="quality-category">'.$this->e((string) ($finding['category'] ?? 'review')).'</span></div>';
                $html .= '<h3>'.$this->e((string) ($finding['title'] ?? 'Finding')).'</h3><p>'.$this->e((string) ($finding['message'] ?? '')).'</p>';
                if ($class !== '' || $method !== '') {
                    $html .= '<div class="quality-context">'.($class !== '' ? $this->linkedClassReference($class, $classLookup) : '').($method !== '' ? '<code class="inline-code">::'.$this->e($method).'()</code>' : '').'</div>';
                }
                if ($location !== '') {
                    $html .= $href !== null ? '<a class="quality-location source-link quality-source-link" href="'.$this->e($href).'"><span class="source-link-label">VIEW SOURCE CODE</span><span class="source-link-path">'.$this->e($location).'</span><span class="source-link-arrow">→</span></a>' : '<code class="inline-code">'.$this->e($location).'</code>';
                }
                $meta = (array) ($finding['meta'] ?? []);
                if ($meta !== []) {
                    $safeMeta = [];
                    foreach ($meta as $key => $value) {
                        if (is_scalar($value) || $value === null) {
                            $safeMeta[] = (string) $key.': '.(string) $value;
                        }
                    }
                    if ($safeMeta !== []) {
                        $html .= '<div class="quality-meta">'.$this->chips($safeMeta, 'info-chip').'</div>';
                    }
                }
                if ($this->qualityOnlyMode && $path !== '' && ((int) ($finding['start_line'] ?? $finding['line'] ?? 0)) > 0) {
                    $html .= $this->qualitySourceSnippet((array) $finding, (int) $findingIndex);
                }
                $html .= '</article>';
            }
            $html .= '</div>';
        }

        if ($legacyQuality !== []) {
            $html .= '<h3 class="subheading">Class size & complexity thresholds</h3>';
            foreach ($legacyQuality as $item) {
                $html .= '<div class="quality-row"><div><strong>'.$this->linkedClassReference((string) ($item['class'] ?? ''), $classLookup).'</strong><small>'.$this->e((string) ($item['path'] ?? '')).'</small></div><div>'.$this->chips((array) ($item['flags'] ?? []), 'warning-chip').'</div></div>';
            }
        }

        $html .= '<h3 class="subheading">Possibly unused classes</h3>';
        if ($possiblyUnused === []) {
            $html .= '<p class="muted">No obvious unused classes were found by static incoming-reference analysis.</p>';
        } else {
            $html .= '<div class="table-wrap"><table class="compact-table"><thead><tr><th>Class</th><th>Type</th><th>Location</th><th>Note</th></tr></thead><tbody>';
            foreach ($possiblyUnused as $item) {
                $html .= '<tr><td>'.$this->linkedClassReference((string) ($item['class'] ?? ''), $classLookup).'</td><td>'.$this->e((string) ($item['category'] ?? '')).'</td><td><code class="inline-code">'.$this->e((string) ($item['path'] ?? '')).'</code></td><td>'.$this->e((string) ($item['note'] ?? '')).'</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        return $html.'</section>';
    }

    /**
     * Render the focused --quality report without the architecture/manual or full source appendices.
     * Small source excerpts remain inline so every finding still shows the exact problem code.
     */
    private function qualityOnlyHtml(
        ProjectDocumentation $documentation,
        string $projectName,
        array $qualityReport,
        array $legacyQuality,
        array $possiblyUnused,
        array $phpFiles,
        array $frontend,
        array $coverage,
        array $warnings,
    ): string {
        $previousQualityMode = $this->qualityOnlyMode;
        $previousSourceLookup = $this->qualitySourceLookup;
        $this->qualityOnlyMode = true;
        $this->qualitySourceLookup = $this->buildQualitySourceLookup($phpFiles, $frontend);
        $this->qualitySourceFindings = $this->buildQualitySourceFindingMap($qualityReport);

        try {
            $meta = $documentation->meta;
            $summary = (array) ($qualityReport['summary'] ?? []);
            $score = (int) ($qualityReport['score'] ?? 100);
            $findingCount = (int) ($qualityReport['finding_count'] ?? count((array) ($qualityReport['findings'] ?? [])));
            $qualityCoverage = (array) ($coverage['quality'] ?? []);

            $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
            $html .= '<title>'.$this->e($projectName).' - Quality Report</title><style>'.$this->css().'</style></head><body><div class="doc-shell quality-only-shell">';
            $html .= '<main class="doc-content quality-only-content">';
            $html .= '<header class="hero" id="top"><div class="hero-accent"></div><div class="hero-content">';
            $html .= '<p class="eyebrow">Laravel Project Quality Report</p><h1>'.$this->e($projectName).'</h1>';
            $html .= '<p class="hero-copy">Focused static quality review only. Architecture, route, class and complete source appendices are intentionally omitted.</p>';
            $html .= '<div class="hero-meta"><span>Generated '.$this->e((string) ($meta['generated_at'] ?? '')).'</span><span>Laravel '.$this->e((string) ($meta['laravel_version'] ?? '')).'</span><span>PHP '.$this->e((string) ($meta['php_version'] ?? '')).'</span></div></div></header>';
            $html .= $this->analysisModeBanner((array) $meta);
            $html .= '<nav class="doc-nav quality-only-nav" id="navigation" aria-label="Quality report navigation"><a href="#top">Overview</a><a href="#quality">Quality findings</a></nav>';
            $html .= '<section class="quality-only-overview"><div class="section-heading"><span class="section-number">00</span><div><p class="section-kicker">Focused review</p><h2>Quality overview</h2></div></div><div class="cards">';
            $html .= $this->card('Review score', $score, '/ 100', '#quality');
            $html .= $this->card('Findings', $findingCount, 'Static review signals', '#quality');
            $html .= $this->card('Critical', (int) ($summary['critical'] ?? 0), 'Highest priority', '#quality');
            $html .= $this->card('High', (int) ($summary['high'] ?? 0), 'High-risk signals', '#quality');
            $html .= $this->card('Medium', (int) ($summary['medium'] ?? 0), 'Warnings', '#quality');
            $html .= $this->card('Low', (int) ($summary['low'] ?? 0), 'Observations', '#quality');
            $html .= '</div><div class="quality-only-scope"><strong>Application-owned code only</strong><span>Reviewed PHP files: '.(int) ($qualityCoverage['reviewed_php_files'] ?? 0).' · scaffold/framework PHP skipped: '.(int) ($qualityCoverage['excluded_php_files'] ?? 0).' · tests executed: 0.</span></div></section>';
            $html .= $this->qualitySection($qualityReport, $legacyQuality, $possiblyUnused, []);

            if ($warnings !== []) {
                $html .= '<section class="warnings" id="warnings"><div class="section-heading"><span class="section-number">02</span><div><p class="section-kicker">Scanner</p><h2>Generation warnings</h2></div></div><ul>';
                foreach ($warnings as $warning) {
                    $html .= '<li><strong>'.$this->e((string) ($warning['scanner'] ?? 'scanner')).'</strong><span>'.$this->e((string) ($warning['message'] ?? '')).'</span></li>';
                }
                $html .= '</ul></section>';
            }

            $html .= '<a class="floating-top" href="#top" aria-label="Back to top">↑ Top</a><div class="pdf-page-nav"><a href="#top">↑ Back to top</a><span> · </span><a href="#navigation">Navigation</a></div>';
            $html .= '</main></div></body></html>';
            $html = str_replace('</section>', '<div class="section-footer"><a href="#top">↑ Back to top</a><a href="#navigation">Navigation</a></div></section>', $html);

            return $html;
        } finally {
            $this->qualityOnlyMode = $previousQualityMode;
            $this->qualitySourceLookup = $previousSourceLookup;
        }
    }

    /** @return array<string,array{source:string,kind:string}> */
    private function buildQualitySourceLookup(array $phpFiles, array $frontend): array
    {
        $lookup = [];
        foreach (array_merge($phpFiles, $frontend) as $file) {
            $source = $file['source'] ?? null;
            $path = $this->normaliseSourcePath((string) ($file['path'] ?? ''));
            if ($path === '' || ! is_string($source)) {
                continue;
            }
            $lookup[$path] = [
                'source' => $source,
                'kind' => (string) ($file['kind'] ?? (str_ends_with(strtolower($path), '.php') ? 'php' : 'frontend')),
            ];
        }
        return $lookup;
    }

    private function qualitySnippetAnchor(int $findingIndex): string
    {
        return 'quality-code-'.str_pad((string) ($findingIndex + 1), 3, '0', STR_PAD_LEFT);
    }

    private function qualitySourceSnippet(array $finding, int $findingIndex): string
    {
        $path = $this->normaliseSourcePath((string) ($finding['path'] ?? ''));
        $start = max(1, (int) ($finding['start_line'] ?? $finding['line'] ?? 1));
        $meta = (array) ($finding['meta'] ?? []);
        $end = max($start, (int) ($finding['end_line'] ?? $meta['end_line'] ?? $start));
        $file = $this->qualitySourceLookup[$path] ?? null;
        $anchor = $this->qualitySnippetAnchor($findingIndex);

        if (! is_array($file) || ! isset($file['source'])) {
            return '<div class="quality-code-unavailable" id="'.$this->e($anchor).'"><strong>Problem code excerpt unavailable.</strong><span>Source embedding is disabled or the file could not be read.</span></div>';
        }

        $language = $this->languageFor($path, (string) ($file['kind'] ?? 'frontend'));
        $lines = $this->highlighter->lines((string) $file['source'], $language);
        if ($lines === []) {
            return '';
        }

        $maxLine = count($lines);
        $start = min($start, $maxLine);
        $end = min(max($start, $end), $maxLine);
        // Keep quality-only reports compact: show context plus at most six affected lines.
        $visibleProblemEnd = min($end, $start + 5);
        $from = max(1, $start - 2);
        $to = min($maxLine, $visibleProblemEnd + 2);
        $findingCode = $this->qualityFindingCode($findingIndex);
        $severity = strtolower((string) ($finding['severity'] ?? 'low'));

        $html = '<div class="quality-code-snippet" id="'.$this->e($anchor).'"><div class="quality-code-snippet-head"><strong>'.$this->e($findingCode).' problem code</strong><span>'.$this->e($path).':'.$start.($end > $start ? '-'.$end : '').'</span></div><table><tbody>';
        for ($lineNumber = $from; $lineNumber <= $to; $lineNumber++) {
            $isProblem = $lineNumber >= $start && $lineNumber <= $visibleProblemEnd;
            $line = $lines[$lineNumber - 1] ?? '';
            $html .= '<tr class="'.($isProblem ? 'problem severity-'.$this->e($severity) : '').'">';
            $html .= '<td class="quality-code-line-number">'.$lineNumber.($isProblem ? '<b>!</b>' : '').'</td>';
            $html .= '<td class="quality-code-line"><code>'.($line === '' ? '&nbsp;' : $line).'</code></td></tr>';
        }
        if ($end > $visibleProblemEnd) {
            $html .= '<tr class="quality-code-truncated"><td>…</td><td>Finding continues through line '.$end.'; excerpt shortened to keep the quality-only report compact.</td></tr>';
        }
        $html .= '</tbody></table><a class="quality-code-back" href="#'.$this->e($this->qualityFindingAnchor($finding, $findingIndex)).'">↑ Back to '.$this->e($findingCode).'</a></div>';
        return $html;
    }

    private function analysisModeBanner(array $meta): string
    {
        return '<div class="analysis-mode-banner"><span class="analysis-mode-icon">◈</span><span><strong>STATIC / READ-ONLY ANALYSIS</strong><small>Source files are read and parsed only. Project business methods, controllers, services, database queries, HTTP requests, jobs, events, mail, migrations and tests are not invoked by the analyser.</small></span><b>NO TESTS RUN</b></div>';
    }

    /** @param array<int, array<string, mixed>> $references */
    private function usedByBlock(array $references, array $classLookup): string
    {
        $html = '<div class="used-by"><strong>Used by</strong><div class="used-by-items">';
        foreach (array_slice($references, 0, 12) as $reference) {
            $source = (string) ($reference['source'] ?? '');
            $content = str_contains($source, '\\') ? $this->linkedClassReference($source, $classLookup) : '<code class="inline-code">'.$this->e($source).'</code>';
            $html .= '<span class="used-by-item"><em>'.$this->e((string) ($reference['type'] ?? 'reference')).'</em>'.$content;
            if (! empty($reference['context'])) {
                $html .= '<small>'.$this->e((string) $reference['context']).'</small>';
            }
            $html .= '</span>';
        }
        if (count($references) > 12) {
            $html .= '<span class="muted">+'.(count($references) - 12).' more references</span>';
        }
        return $html.'</div></div>';
    }

    private function metaItem(string $label, string $value): string
    {
        return '<div class="meta-item"><span>'.$this->e($label).'</span><strong>'.$this->e($value).'</strong></div>';
    }

    private function chips(array $items, string $class = 'info-chip'): string
    {
        $html = '';
        foreach ($items as $key => $item) {
            $label = is_string($key) ? $key.' → '.(is_scalar($item) ? (string) $item : json_encode($item)) : (is_scalar($item) ? (string) $item : json_encode($item));
            $html .= '<span class="'.$this->e($class).'">'.$this->e($label).'</span> ';
        }
        return $html;
    }

    private function packageList(string $title, array $packages): string
    {
        ksort($packages);
        $html = '<div class="dependency-panel"><h3>'.$this->e($title).'</h3>';
        if ($packages === []) {
            return $html.'<p class="muted">None detected.</p></div>';
        }
        $html .= '<table class="compact-table"><thead><tr><th>Package</th><th>Version</th></tr></thead><tbody>';
        foreach ($packages as $name => $version) {
            $html .= '<tr><td><code class="inline-code">'.$this->e((string) $name).'</code></td><td>'.$this->e((string) $version).'</td></tr>';
        }
        return $html.'</tbody></table></div>';
    }

    /** @param array<int, array<string, mixed>> $phpFiles */
    private function buildClassIndex(array $phpFiles): array
    {
        $items = [];

        foreach ($phpFiles as $file) {
            $path = (string) ($file['path'] ?? '');
            foreach (($file['classes'] ?? []) as $class) {
                $name = (string) ($class['name'] ?? 'anonymous');
                if ($name === 'anonymous') {
                    continue;
                }

                $fqcn = ltrim((string) ($class['fqcn'] ?? ''), '\\');
                $identity = $fqcn !== '' ? $fqcn : $path.'::'.$name;
                $startLine = max(1, (int) ($class['start_line'] ?? 1));
                $namespace = $fqcn !== '' && str_contains($fqcn, '\\')
                    ? substr($fqcn, 0, (int) strrpos($fqcn, '\\'))
                    : (string) ($file['namespace'] ?? '');

                $classAnchor = $this->classAnchor($identity);
                $hasSource = array_key_exists('source', $file) && $file['source'] !== null;

                $items[] = [
                    'name' => $name,
                    'fqcn' => $fqcn,
                    'namespace' => $namespace,
                    'kind' => (string) ($class['kind'] ?? 'class'),
                    'category' => (string) ($class['category'] ?? 'class'),
                    'path' => $path,
                    'start_line' => $startLine,
                    'class_anchor' => $classAnchor,
                    'source_anchor' => $hasSource ? $this->sourceLineAnchor($path, $startLine) : $classAnchor,
                ];
            }
        }

        usort($items, static fn (array $a, array $b) => strcasecmp($a['fqcn'] ?: $a['name'], $b['fqcn'] ?: $b['name']));

        return $items;
    }

    /** @param array<int, array<string, mixed>> $classIndex */
    private function buildClassLookup(array $classIndex): array
    {
        $lookup = [];
        $shortNames = [];

        foreach ($classIndex as $entry) {
            if ($entry['fqcn'] !== '') {
                $lookup[ltrim($entry['fqcn'], '\\')] = $entry['class_anchor'];
            }
            $shortNames[$entry['name']][] = $entry['class_anchor'];
        }

        foreach ($shortNames as $name => $anchors) {
            if (count($anchors) === 1) {
                $lookup[$name] = $anchors[0];
            }
        }

        return $lookup;
    }

    /** @param array<int, array<string, mixed>> $phpFiles @param array<int, array<string, mixed>> $frontend */
    private function buildPathLookup(array $phpFiles, array $frontend): array
    {
        $lookup = [];
        foreach (array_merge($phpFiles, $frontend) as $file) {
            $path = str_replace('\\', '/', (string) ($file['path'] ?? ''));
            if ($path !== '') {
                $lookup[$path] = $this->fileAnchor($path);
            }
        }

        return $lookup;
    }

    private function sourceBlock(string $source, string $path, string $kind): string
    {
        if ($this->pdfMode && ! $this->pdfIncludeSource) {
            $lines = substr_count(str_replace(["\r\n", "\r"], "\n", $source), "\n") + 1;
            $anchor = $this->sourceLineAnchor($path, 1);

            return '<div class="source-warning pdf-source-appendix-note"><strong>Complete source code:</strong> '
                .'<a href="#'.$this->e($anchor).'">'.$lines.' lines are included in the Source Code Appendix</a>. '
                .'<span>The PDF generator streams the appendix separately to keep memory usage bounded.</span></div>';
        }

        return $this->pdfMode
            ? $this->pdfSourceBlock($source, $path, $kind)
            : $this->htmlSourceBlock($source, $path, $kind);
    }

    private function htmlSourceBlock(string $source, string $path, string $kind): string
    {
        $language = $this->languageFor($path, $kind);
        $lines = $this->highlighter->lines($source, $language);
        $label = $this->languageLabel($language);

        $bytes = strlen($source);
        $size = $bytes >= 1024 ? number_format($bytes / 1024, 1).' KB' : $bytes.' B';
        $html = '<details class="source-details" data-language="'.$this->e($language).'"><summary><span>Complete source code</span><span class="summary-meta">'.$this->e($label).' · '.count($lines).' lines · '.$this->e($size).' · 100% included</span></summary>';
        $html .= '<div class="code-editor"><div class="editor-bar"><span class="window-dots"><i></i><i></i><i></i></span><span class="editor-file">'.$this->e($path).'</span><span class="editor-language">'.$this->e($label).'</span></div>';
        $html .= '<div class="continuous-source">';

        $lineNumber = 1;
        $normalisedPath = $this->normaliseSourcePath($path);
        foreach ($lines as $highlightedLine) {
            $content = $highlightedLine === '' ? '&nbsp;' : $highlightedLine;
            $lineAnchor = $this->sourceLineAnchor($path, $lineNumber);
            $lineFindings = (array) ($this->qualitySourceFindings[$normalisedPath][$lineNumber] ?? []);
            $severity = $this->highestFindingSeverity($lineFindings);
            $classes = 'code-line source-line-anchor'.($lineFindings !== [] ? ' code-line-problem severity-'.$severity : '');
            $html .= '<div class="'.$this->e($classes).'" id="'.$this->e($lineAnchor).'">';
            $html .= '<span class="code-line-number">'.$lineNumber.($lineFindings !== [] ? '<b class="code-problem-dot" aria-hidden="true">!</b>' : '').'</span>';
            $html .= '<code class="code-line-source">'.$content.'</code>';
            if ($lineFindings !== []) {
                $first = $this->highestSeverityFinding($lineFindings);
                $count = count($lineFindings);
                $label = strtoupper((string) ($first['severity'] ?? $severity)).($count > 1 ? ' +'.($count - 1) : '');
                $titles = implode(' · ', array_map(static fn (array $finding): string => (string) ($finding['title'] ?? 'Quality finding'), $lineFindings));
                $html .= '<span class="code-quality-markers" title="'.$this->e($titles).'">';
                $html .= '<a class="code-quality-badge severity-'.$this->e((string) ($first['severity'] ?? $severity)).'" href="#'.$this->e((string) ($first['_anchor'] ?? 'quality')).'">'.$this->e($label).'</a>';
                $html .= '</span>';
            }
            $html .= '</div>';
            $lineNumber++;
        }

        return $html.'</div></div></details>';
    }

    /**
     * PDF source is deliberately grouped into lightweight chunks. Visually the
     * chunks form one continuous editor, but Dompdf only has to lay out one
     * table row per group instead of several positioned elements per source
     * line. This keeps large projects well below Dompdf's memory ceiling.
     */
    private function pdfSourceBlock(string $source, string $path, string $kind): string
    {
        $language = $this->languageFor($path, $kind);
        $lines = $this->highlighter->lines($source, $language);
        $chunks = array_chunk($lines, self::PDF_SOURCE_LINES_PER_CHUNK);
        $label = $this->languageLabel($language);
        $bytes = strlen($source);
        $size = $bytes >= 1024 ? number_format($bytes / 1024, 1).' KB' : $bytes.' B';

        $html = '<details class="source-details pdf-source-details" data-language="'.$this->e($language).'"><summary><span>Complete source code</span><span class="summary-meta">'.$this->e($label).' · '.count($lines).' lines · '.$this->e($size).' · 100% included</span></summary>';
        $html .= '<div class="code-editor pdf-code-editor"><div class="editor-bar"><span class="editor-file">'.$this->e($path).'</span><span class="editor-language">'.$this->e($label).'</span></div>';

        $lineNumber = 1;
        foreach ($chunks as $chunk) {
            $numberLines = [];
            $sourceLines = [];

            foreach ($chunk as $highlightedLine) {
                $content = $highlightedLine === '' ? '&nbsp;' : $highlightedLine;
                $lineAnchor = $this->sourceLineAnchor($path, $lineNumber);
                $numberLines[] = (string) $lineNumber;
                $sourceLines[] = '<span class="source-line-anchor" id="'.$this->e($lineAnchor).'">'.$content.'</span>';
                $lineNumber++;
            }

            $html .= '<div class="pdf-code-chunk"><table class="pdf-code-table" role="presentation"><tbody><tr>';
            $html .= '<td class="pdf-line-number"><code>'.implode('<br>', $numberLines).'</code></td>';
            $html .= '<td class="pdf-line-source"><code>'.implode('<br>', $sourceLines).'</code></td>';
            $html .= '</tr></tbody></table></div>';
        }

        return $html.'</div></details>';
    }

    /** @param array<string, mixed> $meta */
    private function sourceOmittedNotice(array $meta): string
    {
        $lines = (int) ($meta['lines'] ?? 0);
        $bytes = (int) ($meta['bytes'] ?? 0);
        $reason = (string) ($meta['reason'] ?? 'Source was not embedded.');

        return '<div class="source-warning"><strong>Source not embedded.</strong> ' .
            $this->e($reason).' <span>'.$lines.' lines · '.$bytes.' bytes.</span></div>';
    }

    private function linkedClassReference(string $value, array $classLookup): string
    {
        if ($value === '') {
            return '<span class="muted">—</span>';
        }

        $class = $this->classPart($value);
        $key = ltrim($class, '\\');
        $anchor = $classLookup[$key] ?? null;

        if ($anchor === null && str_contains($key, '\\')) {
            $short = substr($key, (int) strrpos($key, '\\') + 1);
            $anchor = $classLookup[$short] ?? null;
        }

        $content = '<code class="inline-code">'.$this->e($value).'</code>';

        return $anchor !== null
            ? '<a class="nav-link code-link" href="#'.$this->e($anchor).'">'.$content.'</a>'
            : $content;
    }

    private function classPart(string $value): string
    {
        $value = trim($value);
        if (str_contains($value, '@')) {
            return explode('@', $value, 2)[0];
        }
        if (str_contains($value, '::')) {
            return explode('::', $value, 2)[0];
        }

        return $value;
    }

    private function linkedPathOrCode(string $value, array $pathLookup): string
    {
        $normalised = str_replace('\\', '/', $value);
        if (isset($pathLookup[$normalised])) {
            return '<a class="location-link source-link" href="#'.$this->e($pathLookup[$normalised]).'"><span class="source-link-label">SOURCE</span>'.$this->e($value).'</a>';
        }

        return '<code class="inline-code">'.$this->e($value).'</code>';
    }

    private function linkedPath(string $path, array $pathLookup): string
    {
        $normalised = str_replace('\\', '/', $path);
        $anchor = $pathLookup[$normalised] ?? null;

        return $anchor !== null
            ? '<a class="location-link source-link" href="#'.$this->e($anchor).'"><span class="source-link-label">SOURCE</span>'.$this->e($path).'</a>'
            : $this->e($path);
    }

    private function routeAnchor(array $route): string
    {
        return 'route-'.substr(sha1(implode('|', (array) ($route['methods'] ?? [])).'|'.(string) ($route['uri'] ?? '').'|'.(string) ($route['name'] ?? '')), 0, 14);
    }

    private function workflowAnchor(array $workflow): string
    {
        return 'workflow-'.substr(sha1((string) ($workflow['name'] ?? '').'|'.(string) ($workflow['uri'] ?? '')), 0, 14);
    }

    private function tableAnchor(string $table): string
    {
        return 'table-'.substr(sha1(strtolower($table)), 0, 14);
    }

    private function sidebar(array $warnings): string
    {
        $links = [
            '#summary' => 'Overview',
            '#contents' => 'Contents',
            '#class-index' => 'Classes',
            '#routes' => 'Routes',
            '#workflows' => 'Workflows',
            '#models' => 'Models',
            '#database' => 'Database',
            '#erd' => 'ERD',
            '#frontend-backend' => 'Frontend → Backend',
            '#validation' => 'Validation',
            '#runtime' => 'Runtime',
            '#dependencies' => 'Dependencies',
            '#quality' => 'Quality',
            '#relationships' => 'Relationships',
            '#call-graph' => 'Call graph',
            '#php-classes' => 'PHP source',
            '#frontend' => 'Frontend source',
        ];
        if ($warnings !== []) {
            $links['#warnings'] = 'Warnings';
        }

        $html = '<aside class="doc-sidebar" aria-label="Developer documentation sidebar"><div class="sidebar-brand"><strong>Project Docs</strong><small>Laravel intelligence</small></div>';
        $html .= '<label class="global-search"><span>Search everything</span><input id="global-search" type="search" placeholder="Route, model, class, table…" autocomplete="off"></label><div id="global-search-results" class="global-search-results" hidden></div>';
        $html .= '<nav class="sidebar-nav">';
        foreach ($links as $href => $label) {
            $html .= '<a href="'.$this->e($href).'">'.$this->e($label).'</a>';
        }
        $html .= '</nav><a class="sidebar-top" href="#top">↑ Back to top</a></aside>';
        return $html;
    }

    /** @return array<int,array{label:string,type:string,detail:string,href:string}> */
    private function buildGlobalSearchIndex(array $classIndex, array $routes, array $workflows, array $models, array $tables, array $runtime, array $frontend, array $qualityReport = []): array
    {
        $items = [];
        foreach ($classIndex as $entry) {
            $items[] = ['label' => (string) $entry['name'], 'type' => ucfirst((string) ($entry['category'] ?? 'class')), 'detail' => (string) $entry['path'], 'href' => '#'.(string) $entry['class_anchor']];
        }
        foreach ($routes as $route) {
            $label = implode('|', (array) ($route['methods'] ?? [])).' '.(string) ($route['uri'] ?? '');
            $items[] = ['label' => $label, 'type' => 'Route', 'detail' => trim((string) ($route['name'] ?? '').' '.(string) ($route['action'] ?? '')), 'href' => '#'.$this->routeAnchor($route)];
        }
        foreach ($workflows as $workflow) {
            $items[] = ['label' => (string) ($workflow['name'] ?? 'Workflow'), 'type' => 'Workflow', 'detail' => implode('|', (array) ($workflow['methods'] ?? [])).' '.(string) ($workflow['uri'] ?? ''), 'href' => '#'.$this->workflowAnchor($workflow)];
        }
        foreach ($models as $model) {
            $class = (string) ($model['class'] ?? $model['name'] ?? 'Model');
            $items[] = ['label' => $this->classPart($class), 'type' => 'Model', 'detail' => (string) ($model['table_effective'] ?? ''), 'href' => '#'.$this->classAnchor($class)];
        }
        foreach ($tables as $table) {
            $name = (string) ($table['name'] ?? '');
            $items[] = ['label' => $name, 'type' => 'DB table', 'detail' => count((array) ($table['columns'] ?? [])).' columns', 'href' => '#'.$this->tableAnchor($name)];
        }
        foreach ($runtime as $item) {
            $class = (string) ($item['class'] ?? '');
            $items[] = ['label' => $this->classPart($class), 'type' => ucfirst((string) ($item['category'] ?? 'Runtime')), 'detail' => (string) ($item['path'] ?? ''), 'href' => '#'.$this->classAnchor($class)];
        }
        foreach ($frontend as $file) {
            $path = (string) ($file['path'] ?? '');
            $items[] = ['label' => basename($path), 'type' => ucfirst((string) ($file['kind'] ?? 'Frontend')), 'detail' => $path, 'href' => '#'.$this->fileAnchor($path)];
        }
        foreach ((array) ($qualityReport['findings'] ?? []) as $finding) {
            $path = (string) ($finding['path'] ?? '');
            $line = isset($finding['line']) ? (int) $finding['line'] : null;
            $href = $path !== '' && $line ? '#'.$this->sourceLineAnchor($path, $line) : '#quality';
            $items[] = ['label' => (string) ($finding['title'] ?? 'Quality finding'), 'type' => strtoupper((string) ($finding['severity'] ?? 'review')), 'detail' => trim((string) ($finding['category'] ?? '').' '.$path.($line ? ':'.$line : '')), 'href' => $href];
        }
        return array_values(array_unique($items, SORT_REGULAR));
    }

    private function searchScript(array $searchIndex): string
    {
        $json = json_encode($searchIndex, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
        return '<script>(function(){'
            .'var classInput=document.getElementById("class-search");if(classInput){classInput.addEventListener("input",function(){var q=this.value.toLowerCase().trim();document.querySelectorAll(".class-index-table tbody tr").forEach(function(row){row.style.display=!q||row.innerText.toLowerCase().indexOf(q)!==-1?"":"none";});});}'
            .'var index='.$json.';var input=document.getElementById("global-search"),box=document.getElementById("global-search-results");if(!input||!box)return;'
            .'function esc(v){return String(v).replace(/[&<>"]/g,function(c){if(c.charCodeAt(0)===34)return "&quot;";return {"&":"&amp;","<":"&lt;",">":"&gt;"}[c]||c;});}'
            .'function render(){var q=input.value.toLowerCase().trim();if(!q){box.hidden=true;box.innerHTML="";return;}var words=q.split(/\\s+/);var matches=index.filter(function(item){var hay=(item.label+" "+item.type+" "+item.detail).toLowerCase();return words.every(function(w){return hay.indexOf(w)!==-1;});}).slice(0,14);box.hidden=false;box.innerHTML=matches.length?matches.map(function(item){return "<a href=\""+esc(item.href)+"\"><b>"+esc(item.label)+"</b><span>"+esc(item.type)+" · "+esc(item.detail)+"</span></a>";}).join(""):"<p>No matches</p>";}'
            .'input.addEventListener("input",render);box.addEventListener("click",function(){box.hidden=true;});document.addEventListener("keydown",function(e){if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==="k"){e.preventDefault();input.focus();input.select();}});'
            .'})();</script>';
    }

    private function fileAnchor(string $path): string
    {
        return 'file-'.substr(sha1(str_replace('\\', '/', $path)), 0, 14);
    }

    private function classAnchor(string $identity): string
    {
        return 'class-'.substr(sha1(ltrim($identity, '\\')), 0, 14);
    }

    /** @return array<string,array<int,array<int,array<string,mixed>>>> */
    private function buildQualitySourceFindingMap(array $report): array
    {
        $map = [];

        foreach ((array) ($report['findings'] ?? []) as $index => $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $path = $this->normaliseSourcePath((string) ($finding['path'] ?? ''));
            $start = (int) ($finding['start_line'] ?? $finding['line'] ?? 0);
            $meta = (array) ($finding['meta'] ?? []);
            $end = (int) ($finding['end_line'] ?? $meta['end_line'] ?? $start);

            if ($path === '' || $start < 1) {
                continue;
            }

            $end = max($start, min($end, $start + 500));
            $finding['_anchor'] = $this->qualityFindingAnchor($finding, (int) $index);
            $finding['_code'] = $this->qualityFindingCode((int) $index);

            for ($line = $start; $line <= $end; $line++) {
                $map[$path] ??= [];
                $map[$path][$line] ??= [];
                $map[$path][$line][] = $finding;
            }
        }

        return $map;
    }

    /** @param array<int,array<string,mixed>> $findings @return array<string,mixed> */
    private function highestSeverityFinding(array $findings): array
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $best = $findings[0] ?? [];
        foreach ($findings as $finding) {
            $severity = strtolower((string) ($finding['severity'] ?? 'low'));
            $bestSeverity = strtolower((string) ($best['severity'] ?? 'low'));
            if (($rank[$severity] ?? 1) > ($rank[$bestSeverity] ?? 1)) {
                $best = $finding;
            }
        }
        return $best;
    }

    /** @param array<int,array<string,mixed>> $findings */
    private function highestFindingSeverity(array $findings): string
    {
        $highest = 'low';
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

        foreach ($findings as $finding) {
            $severity = strtolower((string) ($finding['severity'] ?? 'low'));
            if (($rank[$severity] ?? 1) > ($rank[$highest] ?? 1)) {
                $highest = $severity;
            }
        }

        return $highest;
    }

    private function qualityFindingAnchor(array $finding, int $index): string
    {
        return 'quality-finding-'.substr(sha1(implode('|', [
            (string) $index,
            (string) ($finding['severity'] ?? ''),
            (string) ($finding['category'] ?? ''),
            (string) ($finding['title'] ?? ''),
            $this->normaliseSourcePath((string) ($finding['path'] ?? '')),
            (string) ($finding['line'] ?? $finding['start_line'] ?? ''),
            (string) ($finding['class'] ?? ''),
            (string) ($finding['method'] ?? ''),
        ])), 0, 14);
    }

    private function qualityFindingCode(int $index): string
    {
        return 'Q'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
    }

    private function normaliseSourcePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), './');
    }

    private function sourceLineAnchor(string $path, int $line): string
    {
        return 'src-'.substr(sha1(str_replace('\\', '/', $path)), 0, 12).'-L'.$line;
    }

    private function languageFor(string $path, string $kind): string
    {
        $lower = strtolower($path);

        return match (true) {
            $lower === '.env' || str_ends_with($lower, '/.env') || $kind === 'env' => 'env',
            str_ends_with($lower, '.blade.php') || $kind === 'blade' => 'blade',
            str_ends_with($lower, '.php') || $kind === 'php' => 'php',
            str_ends_with($lower, '.ts'), str_ends_with($lower, '.tsx'), $kind === 'typescript' => 'typescript',
            str_ends_with($lower, '.vue') || $kind === 'vue' => 'vue',
            default => 'javascript',
        };
    }

    private function languageLabel(string $language): string
    {
        return match ($language) {
            'env' => 'ENV',
            'php' => 'PHP',
            'blade' => 'Blade',
            'typescript' => 'TypeScript',
            'vue' => 'Vue',
            default => 'JavaScript',
        };
    }

    /**
     * The project-summary model count must be simple and dependable.
     *
     * The deeper intelligence layer can inspect inheritance, Eloquent metadata,
     * runtime autoloading, custom base models, etc. The summary card, however,
     * should never show zero while model source files are visibly present in the
     * documentation. Count any model already found by intelligence plus scanned
     * PHP classes/files living in conventional Model/Models locations.
     *
     * @param array<int, array<string, mixed>> $phpFiles
     * @param array<int, array<string, mixed>> $intelligenceModels
     */
    private function summaryModelCount(array $phpFiles, array $intelligenceModels): int
    {
        $found = [];

        foreach ($intelligenceModels as $model) {
            $identity = trim((string) ($model['class'] ?? $model['path'] ?? ''));
            if ($identity !== '') {
                $found['class:'.strtolower(str_replace('\\', '/', $identity))] = true;
            }
        }

        foreach ($phpFiles as $file) {
            $path = str_replace('\\', '/', (string) ($file['path'] ?? ''));
            $pathLower = strtolower($path);
            $modelPath = preg_match('~(^|/)(models?|eloquent)(/|$)~i', $pathLower) === 1;
            $matchedClass = false;

            foreach (($file['classes'] ?? []) as $class) {
                $fqcn = ltrim((string) ($class['fqcn'] ?? ''), '\\');
                $namespace = ltrim((string) ($class['namespace'] ?? $file['namespace'] ?? ''), '\\');
                $category = strtolower((string) ($class['category'] ?? ''));
                $fqcnLower = strtolower($fqcn);
                $namespaceLower = strtolower($namespace);

                $isModel = $modelPath
                    || $category === 'model'
                    || str_starts_with($fqcnLower, 'app\\models\\')
                    || $fqcnLower === 'app\\model'
                    || str_starts_with($namespaceLower, 'app\\models')
                    || str_starts_with($namespaceLower, 'app\\model');

                if (! $isModel) {
                    continue;
                }

                $matchedClass = true;
                $identity = $fqcn !== '' ? $fqcn : $path.'#'.($class['name'] ?? 'model');
                $found['class:'.strtolower(str_replace('\\', '/', $identity))] = true;
            }

            // Parser failures must not make the visible model-file count drop to 0.
            if ($modelPath && ! $matchedClass && $path !== '') {
                $found['file:'.strtolower($path)] = true;
            }
        }

        return count($found);
    }

    private function card(string $label, int $value, string $description, string $href): string
    {
        return '<a class="card card-link" href="'.$this->e($href).'"><span class="card-label">'.$this->e($label).'</span><strong>'.$value.'</strong><small>'.$this->e($description).'</small><span class="card-jump">Open section →</span></a>';
    }

    /** @param array<string, mixed> $envFile */
    private function environmentStatusBanner(array $envFile): string
    {
        $requested = (bool) ($envFile['requested'] ?? false);
        $exists = (bool) ($envFile['exists'] ?? false);
        $included = (bool) ($envFile['included'] ?? false);

        if ($included) {
            return '<a class="env-status-banner env-status-danger" href="#environment-file"><span class="env-status-icon">⚠</span><span><strong>SENSITIVE DOCUMENT — .env INCLUDED</strong><small>This documentation contains environment values and may contain passwords, API keys, tokens or credentials. Treat it as confidential and do not share it publicly.</small></span><b>View .env →</b></a>';
        }

        if ($requested && ! $exists) {
            return '<a class="env-status-banner env-status-warning" href="#environment-file"><span class="env-status-icon">!</span><span><strong>.env REQUESTED BUT NOT FOUND</strong><small>The <code>--include-env</code> option was used, but no .env file exists at the Laravel project root.</small></span><b>Details →</b></a>';
        }

        return '<a class="env-status-banner env-status-safe" href="#environment-file"><span class="env-status-icon">✓</span><span><strong>.env NOT INCLUDED — SECRET VALUES EXCLUDED</strong><small>Normal safe mode. Use <code>php artisan project-docs:generate --include-env</code> only when you deliberately want the environment file embedded.</small></span><b>Details →</b></a>';
    }

    /** @param array<string, mixed> $envFile */
    private function environmentFileBlock(array $envFile): string
    {
        $requested = (bool) ($envFile['requested'] ?? false);
        $exists = (bool) ($envFile['exists'] ?? false);
        $included = (bool) ($envFile['included'] ?? false);
        $html = '<div id="environment-file" class="environment-file-section"><h3 class="subheading">Environment file (.env)</h3>';

        if ($included) {
            $lines = (int) ($envFile['lines'] ?? 0);
            $bytes = (int) ($envFile['bytes'] ?? 0);
            $html .= '<div class="env-file-warning"><strong>⚠ SENSITIVE — COMPLETE .env FILE INCLUDED</strong><span>Generated with <code class="inline-code">--include-env</code>. '.$lines.' lines · '.$bytes.' bytes. This output may contain live credentials and must be handled as a secret.</span></div>';
            $html .= $this->sourceBlock((string) ($envFile['source'] ?? ''), '.env', 'env');
            return $html.'</div>';
        }

        if ($requested && ! $exists) {
            $html .= '<div class="env-file-notice env-file-missing"><strong>.env was requested but no file was found.</strong><span>Expected location: <code class="inline-code">'.'.env'.'</code> in the Laravel project root.</span></div>';
            return $html.'</div>';
        }

        $html .= '<div class="env-file-notice env-file-safe"><strong>.env is not included in this documentation.</strong><span>This is the default safe behaviour. To deliberately embed the complete file, generate with <code class="inline-code">--include-env</code>.</span></div>';
        return $html.'</div>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create output directory [{$directory}].");
        }
    }

    private function css(): string
    {
        return <<<'CSS'
:root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#18212f;background:#eef2f7;--ink:#18212f;--muted:#64748b;--line:#dce3ec;--navy:#101827;--blue:#2563eb;--blue-soft:#eff6ff;--panel:#fff;--code:#1e1f22;--code-2:#27282c}*{box-sizing:border-box}html{scroll-behavior:smooth;scroll-padding-top:18px}body{margin:0;background:linear-gradient(180deg,#eef3f8 0,#f8fafc 420px,#f8fafc 100%)}.doc-shell{display:grid;grid-template-columns:230px minmax(0,1220px);gap:20px;max-width:1490px;margin:0 auto;padding:0 18px}.doc-content{min-width:0;max-width:1220px;padding:42px 10px 100px}.doc-sidebar{position:sticky;top:18px;align-self:start;max-height:calc(100vh - 36px);overflow:auto;margin-top:42px;padding:14px;border:1px solid var(--line);border-radius:16px;background:rgba(255,255,255,.97);box-shadow:0 12px 30px rgba(15,23,42,.08);z-index:30}.sidebar-brand{padding:4px 4px 12px;border-bottom:1px solid #e5eaf0}.sidebar-brand strong{display:block;color:#0f172a;font-size:15px}.sidebar-brand small{color:#64748b}.global-search{display:block;margin:12px 0 8px}.global-search>span{display:block;margin-bottom:5px;color:#475569;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.07em}.global-search input{width:100%;padding:8px 9px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#0f172a;font:inherit;font-size:11px;outline:none}.global-search input:focus{border-color:#60a5fa;box-shadow:0 0 0 2px #dbeafe}.global-search-results{max-height:300px;overflow:auto;margin:6px 0 10px;border:1px solid #dbe3eb;border-radius:9px;background:#fff}.global-search-results a{display:block;padding:8px 9px;border-bottom:1px solid #edf2f7;color:#0f172a;text-decoration:none}.global-search-results a:last-child{border-bottom:0}.global-search-results a:hover{background:#eff6ff}.global-search-results b{display:block;font-size:10px}.global-search-results span{display:block;margin-top:2px;color:#64748b;font-size:8px}.global-search-results p{margin:0;padding:10px;color:#64748b;font-size:10px}.sidebar-nav{display:flex;flex-direction:column;padding:6px 0}.sidebar-nav a{padding:6px 8px;border-radius:7px;color:#334155;text-decoration:none;font-size:10px;font-weight:700}.sidebar-nav a:hover{background:#eff6ff;color:#1d4ed8}.sidebar-top{display:block;margin-top:5px;padding:8px;border-top:1px solid #e5eaf0;color:#1d4ed8;text-decoration:none;font-size:10px;font-weight:800}main{max-width:1220px;margin:0 auto}.hero{position:relative;overflow:hidden;background:linear-gradient(135deg,#0f172a,#172554 62%,#1e3a8a);color:#fff;border-radius:24px;padding:42px 46px;margin-bottom:14px;box-shadow:0 20px 55px rgba(15,23,42,.18)}.hero-accent{position:absolute;width:300px;height:300px;border-radius:50%;right:-95px;top:-160px;background:rgba(96,165,250,.18)}.hero-content{position:relative}.eyebrow,.section-kicker{text-transform:uppercase;letter-spacing:.15em;font-size:11px;font-weight:800}.eyebrow{color:#93c5fd;margin:0 0 6px}.hero h1{font-size:42px;line-height:1.05;margin:0 0 12px}.hero-copy{max-width:760px;color:#dbeafe;font-size:16px;line-height:1.55;margin:0}.hero-meta{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:24px;color:#bfdbfe;font-size:12px}.hero-meta span{padding-right:18px;border-right:1px solid rgba(191,219,254,.28)}.hero-meta span:last-child{border:0}.doc-nav{position:sticky;top:10px;z-index:20;display:none;flex-wrap:wrap;gap:7px;padding:9px;margin:0 0 16px;background:rgba(255,255,255,.94);backdrop-filter:blur(10px);border:1px solid var(--line);border-radius:13px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.doc-nav a,.class-actions a,.back-link{color:#1d4ed8;text-decoration:none;font-size:11px;font-weight:800}.doc-nav a{padding:6px 9px;border-radius:7px}.doc-nav a:hover{background:#eff6ff}.nav-link,.location-link,.method-link{color:#1d4ed8;text-decoration:none}.nav-link:hover,.location-link:hover,.method-link:hover{text-decoration:underline}.location-link{font-family:"SFMono-Regular",Consolas,"Liberation Mono",monospace;font-size:.9em;color:#0369a1}.code-link{display:inline-block}.section-intro{margin:-4px 0 13px;color:#64748b;line-height:1.55}.class-search-hint{display:flex;gap:10px;align-items:center;justify-content:space-between;margin:0 0 10px;padding:9px 11px;border:1px solid #dbeafe;border-radius:9px;background:#eff6ff;font-size:11px}.class-search-hint strong{color:#1e40af}.class-search-hint span{color:#64748b}section{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:28px;margin:18px 0;box-shadow:0 8px 22px rgba(15,23,42,.035)}.section-heading{display:flex;align-items:center;gap:13px;margin-bottom:18px}.section-number{display:inline-grid;place-items:center;width:38px;height:38px;border-radius:11px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:800;border:1px solid #dbeafe}.section-kicker{color:#2563eb;margin:0 0 2px}.section-heading h2{font-size:23px;line-height:1.2;margin:0;color:#0f172a}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}.card-link{position:relative;color:inherit;text-decoration:none;cursor:pointer;transition:transform .12s ease,border-color .12s ease,box-shadow .12s ease}.card-link:hover{transform:translateY(-2px);border-color:#93c5fd;box-shadow:0 8px 18px rgba(37,99,235,.10)}.card-jump{display:block;margin-top:7px;color:#2563eb;font-size:9px;font-weight:800;opacity:.8}.env-status-banner{display:flex;align-items:center;gap:13px;margin:0 0 14px;padding:13px 16px;border:2px solid;border-radius:14px;text-decoration:none;box-shadow:0 7px 20px rgba(15,23,42,.06)}.env-status-banner span:nth-child(2){flex:1;min-width:0}.env-status-banner strong{display:block;font-size:12px;letter-spacing:.055em}.env-status-banner small{display:block;margin-top:3px;font-size:11px;line-height:1.4}.env-status-banner b{font-size:10px;white-space:nowrap}.env-status-icon{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border-radius:50%;font-size:17px;font-weight:900}.env-status-danger{background:#fff1f2;border-color:#fb7185;color:#9f1239}.env-status-danger .env-status-icon{background:#be123c;color:#fff}.env-status-warning{background:#fffbeb;border-color:#fbbf24;color:#92400e}.env-status-warning .env-status-icon{background:#d97706;color:#fff}.env-status-safe{background:#f0fdf4;border-color:#86efac;color:#166534}.env-status-safe .env-status-icon{background:#16a34a;color:#fff}.environment-file-section{scroll-margin-top:18px;margin-top:18px;padding-top:5px;border-top:1px solid #e2e8f0}.env-file-warning,.env-file-notice{display:flex;flex-direction:column;gap:4px;margin:8px 0 10px;padding:12px 13px;border:1px solid;border-left-width:4px;border-radius:8px;font-size:11px;line-height:1.45}.env-file-warning{background:#fff1f2;border-color:#fb7185;color:#9f1239}.env-file-notice.env-file-safe{background:#f0fdf4;border-color:#86efac;color:#166534}.env-file-notice.env-file-missing{background:#fffbeb;border-color:#fbbf24;color:#92400e}.env-sensitive-copy{padding:8px 10px;background:#fff1f2;border-left:4px solid #e11d48;color:#9f1239}.env-status-banner code{font-family:"SFMono-Regular",Consolas,"Liberation Mono",monospace;font-size:.92em}.card{border:1px solid var(--line);border-radius:14px;padding:17px 18px;background:linear-gradient(180deg,#fff,#f8fafc)}.card-label{display:block;font-size:12px;font-weight:700;color:#475569}.card strong{display:block;font-size:29px;line-height:1.1;color:#0f172a;margin:5px 0}.card small{color:#94a3b8}.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:12px}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:10px 12px;border-bottom:1px solid #e7ecf2;text-align:left;vertical-align:top}th{background:#f8fafc;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.055em}tbody tr:nth-child(even){background:#fbfdff}tbody tr:last-child td{border-bottom:0}.class-index-table th:nth-child(1){width:24%}.class-index-table th:nth-child(2){width:10%}.class-index-table th:nth-child(3){width:31%}.class-index-table th:nth-child(4){width:35%}.class-index-name{font-weight:800;color:#0f4c81}.inline-code{font-family:"SFMono-Regular",Consolas,"Liberation Mono",monospace;font-size:.9em;color:#334155;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:5px;padding:1px 4px}.method-code{color:#1d4ed8}.function-inline{color:#075985}.pill,.kind-badge,.visibility-badge{display:inline-block;border-radius:999px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.065em}.pill{padding:4px 8px;background:#eef2ff;color:#4338ca}.kind-badge{padding:4px 8px;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}.visibility-badge{padding:3px 6px;margin-right:5px;background:#ecfeff;color:#0e7490}.file{border-top:1px solid #e2e8f0;padding:25px 0 4px;margin-top:4px}.file:first-of-type{border-top:0}.file-heading{display:flex;gap:12px;align-items:flex-start;margin-bottom:13px}.file-heading h3{font-size:15px;line-height:1.35;margin:0 0 4px;color:#0f172a;word-break:break-word}.file-heading>div:nth-child(2){min-width:0;flex:1}.back-link{margin-left:auto;white-space:nowrap;padding:5px 7px;border-radius:6px;background:#eff6ff}.file-icon{flex:0 0 38px;width:38px;height:38px;border-radius:10px;display:grid;place-items:center;background:#4f46e5;color:#fff;font-size:9px;font-weight:900;letter-spacing:.04em;box-shadow:inset 0 0 0 1px rgba(255,255,255,.16)}.frontend-icon{background:#0f766e}.muted{color:var(--muted);font-size:12px;margin:4px 0}.class-block{margin:12px 0 16px;padding:16px 17px;border:1px solid #dfe6ef;border-radius:12px;background:#fcfdff}.class-title{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:5px}.class-title h4{font-size:16px;margin:0;color:#111827}.class-location{margin-left:auto;color:#0369a1;text-decoration:none;font-family:"SFMono-Regular",Consolas,"Liberation Mono",monospace;font-size:10px}.class-location:hover{text-decoration:underline}.class-fqcn{margin:0 0 7px}.class-description{margin:0 0 9px;color:#475569;line-height:1.55}.class-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;padding-top:8px;border-top:1px solid #e8edf3}.class-actions a{font-size:10px}.refs,.reference-line{display:flex;gap:10px;align-items:flex-start;padding:9px 11px;background:#f8fafc;border-left:3px solid #60a5fa;border-radius:0 8px 8px 0;font-size:12px}.refs span,.reference-line span{color:#64748b}.reference-line{margin:7px 0}.source-warning{margin:10px 0;padding:9px 11px;border:1px solid #fed7aa;border-left:3px solid #f59e0b;border-radius:8px;background:#fff7ed;color:#9a3412;font-size:11px}.source-warning span{color:#64748b}.source-details{margin-top:15px;border:1px solid #ced6e1;border-radius:12px;overflow:hidden;background:#fff}.source-details>summary{display:flex;justify-content:space-between;align-items:center;gap:12px;cursor:pointer;padding:11px 14px;background:#f8fafc;color:#334155;font-size:12px;font-weight:800;list-style:none}.source-details>summary::-webkit-details-marker{display:none}.source-details>summary:before{content:"›";font-size:18px;line-height:1;color:#2563eb;transform:rotate(0deg);transition:.15s}.source-details[open]>summary:before{transform:rotate(90deg)}.source-details>summary>span:first-child{margin-right:auto}.summary-meta{font-weight:600;color:#94a3b8}.code-editor{background:var(--code);color:#d4d4d4;font-family:"SFMono-Regular",Consolas,"Liberation Mono","DejaVu Sans Mono",monospace}.continuous-source{padding:7px 0}.code-line{position:relative;display:block;min-height:1.48em;padding:0 10px 0 52px;font-family:inherit;font-size:12px;line-height:1.48;white-space:pre-wrap;overflow-wrap:anywhere;page-break-inside:avoid}.code-line-number{position:absolute;left:0;top:0;width:41px;padding-right:8px;text-align:right;color:#606366;border-right:1px solid #33353a;user-select:none;line-height:1.48}.code-line-source{display:block;padding-left:8px;margin:0;border:0;background:transparent;color:inherit;font:inherit;line-height:1.48;white-space:pre-wrap}.editor-bar{display:flex;align-items:center;gap:10px;min-height:36px;padding:7px 12px;background:#2b2d30;border-bottom:1px solid #37393d;color:#a9b7c6;font-size:11px}.window-dots{display:flex;gap:5px}.window-dots i{display:block;width:8px;height:8px;border-radius:50%;background:#ff5f57}.window-dots i:nth-child(2){background:#febc2e}.window-dots i:nth-child(3){background:#28c840}.editor-file{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.editor-language{margin-left:auto;padding:2px 6px;border-radius:4px;background:#3b3d41;color:#cbd5e1;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}.source-line-anchor{scroll-margin-top:18px}.code-line-problem{padding-right:92px}.code-line-problem.severity-critical{background:linear-gradient(90deg,rgba(220,38,38,.24),rgba(220,38,38,.07) 72%,rgba(220,38,38,.02));box-shadow:inset 3px 0 #ef4444}.code-line-problem.severity-high{background:linear-gradient(90deg,rgba(234,88,12,.22),rgba(234,88,12,.06) 72%,rgba(234,88,12,.02));box-shadow:inset 3px 0 #f97316}.code-line-problem.severity-medium{background:linear-gradient(90deg,rgba(217,119,6,.20),rgba(217,119,6,.055) 72%,rgba(217,119,6,.02));box-shadow:inset 3px 0 #f59e0b}.code-line-problem.severity-low{background:linear-gradient(90deg,rgba(37,99,235,.19),rgba(37,99,235,.05) 72%,rgba(37,99,235,.02));box-shadow:inset 3px 0 #3b82f6}.code-line-problem .code-line-number{font-weight:800}.code-problem-dot{position:absolute;right:2px;top:0;color:#fbbf24;font-size:10px;line-height:1.48}.code-quality-markers{position:absolute;right:7px;top:1px;display:flex;gap:3px;align-items:center}.code-quality-badge{display:inline-block;padding:1px 5px;border-radius:999px;text-decoration:none;font:800 8px/1.5 Inter,ui-sans-serif,system-ui;color:#fff;letter-spacing:.04em;box-shadow:0 1px 3px rgba(0,0,0,.3)}.code-quality-badge.severity-critical{background:#dc2626}.code-quality-badge.severity-high{background:#ea580c}.code-quality-badge.severity-medium{background:#d97706}.code-quality-badge.severity-low{background:#2563eb}.quality-code{display:inline-block;padding:2px 6px;border-radius:999px;background:#0f172a;color:#fff;font-size:8px;font-weight:900;letter-spacing:.05em}.syn-keyword{color:#cc7832;font-weight:600}.syn-type{color:#ffc66d}.syn-function{color:#6aa8d8}.syn-variable{color:#9876aa}.syn-string{color:#6a8759}.syn-comment{color:#808080;font-style:italic}.syn-number{color:#6897bb}.syn-property{color:#a9b7c6}.syn-attribute,.syn-directive{color:#bbb529}.syn-operator{color:#a9b7c6}.syn-identifier{color:#d4d4d4}.class-search-hint input{flex:1;min-width:220px;max-width:420px;padding:7px 10px;border:1px solid #bfdbfe;border-radius:8px;background:#fff;color:#0f172a;font:inherit;outline:none}.class-search-hint input:focus{border-color:#60a5fa;box-shadow:0 0 0 2px #dbeafe}.dependency-line{margin:7px 0 10px;font-size:10px}.dependency-chip{display:inline-block;padding:3px 6px;border:1px solid #dbeafe;border-radius:7px;background:#f8fafc;margin:2px}.dependency-chip .inline-code{border:0;background:transparent;padding:0}.frontend-map{margin:0 0 16px;padding:12px;border:1px solid #dbeafe;border-radius:12px;background:#f8fbff}.frontend-map-row{display:grid;grid-template-columns:minmax(220px,.7fr) 1.3fr;gap:12px;padding:7px 0;border-bottom:1px solid #e7eef8}.frontend-map-row:last-child{border-bottom:0}.frontend-map-row>div{display:flex;flex-wrap:wrap;gap:5px}.frontend-map-link{display:inline-flex;gap:5px;align-items:center;padding:4px 6px;border:1px solid #dbeafe;border-radius:7px;background:#fff;font-size:9px}.frontend-map-link em{font-style:normal;color:#2563eb;text-transform:uppercase;font-weight:800;font-size:8px}.category-badge{display:inline-block;padding:3px 7px;border-radius:999px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.workflow-card,.intel-card{border:1px solid var(--line);border-radius:14px;background:#fff;margin:10px 0;padding:15px 16px;page-break-inside:auto}.workflow-title,.intel-card-title{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}.workflow-title strong,.intel-card-title h3{margin:0;font-size:14px;color:#0f172a}.workflow-steps{display:flex;flex-direction:column;align-items:stretch;gap:4px}.workflow-step{display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc}.workflow-type{flex:0 0 70px;text-transform:uppercase;letter-spacing:.07em;font-size:9px;font-weight:800;color:#2563eb}.workflow-arrow{text-align:center;color:#94a3b8;font-weight:800;line-height:1}.meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin:8px 0 10px}.meta-item{padding:9px 10px;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc}.meta-item span{display:block;color:#64748b;font-size:9px;text-transform:uppercase;letter-spacing:.06em;font-weight:700}.meta-item strong{display:block;color:#0f172a;margin-top:2px;font-size:12px}.chip-line{margin:8px 0;font-size:11px;line-height:1.8}.info-chip,.warning-chip{display:inline-block;padding:2px 6px;border-radius:999px;font-size:9px;line-height:1.5;font-weight:700;margin:1px 2px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}.warning-chip{background:#fff7ed;color:#c2410c;border-color:#fed7aa}.compact-table{font-size:11px;margin:8px 0}.compact-table th,.compact-table td{padding:7px 8px}.table-name{font-size:13px;color:#0f4c81}.dependency-columns{display:grid;grid-template-columns:1fr 1fr;gap:14px}.dependency-panel{border:1px solid var(--line);border-radius:12px;padding:12px;min-width:0}.dependency-panel h3,.subheading{margin:0 0 8px;color:#0f172a;font-size:14px}.subheading{margin-top:16px}.env-key{color:#7c3aed}.quality-row{display:grid;grid-template-columns:minmax(220px,.8fr) 1.2fr;gap:14px;padding:10px 0;border-bottom:1px solid #edf0f4;align-items:start}.quality-row small{display:block;color:#64748b;margin-top:3px}.success-note{padding:10px 12px;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:9px;font-size:11px}.used-by{margin:8px 0 10px;padding:9px 10px;border-left:3px solid #8b5cf6;background:#faf5ff;border-radius:0 9px 9px 0}.used-by>strong{display:block;color:#6d28d9;font-size:10px;text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px}.used-by-items{display:flex;flex-wrap:wrap;gap:5px}.used-by-item{display:inline-flex;align-items:center;gap:5px;padding:4px 6px;border:1px solid #e9d5ff;border-radius:7px;background:#fff;font-size:10px}.used-by-item em{font-style:normal;color:#7c3aed;font-size:8px;text-transform:uppercase;font-weight:800}.used-by-item small{color:#64748b}.section-footer{display:flex;justify-content:flex-end;gap:12px;margin-top:17px;padding-top:9px;border-top:1px solid #eef2f7}.section-footer a{font-size:10px;font-weight:800;color:#2563eb;text-decoration:none}.floating-top{position:fixed;right:22px;bottom:22px;z-index:50;padding:9px 12px;border-radius:999px;background:#0f172a;color:#fff;text-decoration:none;font-size:11px;font-weight:800;box-shadow:0 10px 25px rgba(15,23,42,.25)}.pdf-page-nav{display:none}.runtime-table th:nth-child(1){width:10%}.runtime-table th:nth-child(2){width:27%}.runtime-table th:nth-child(3){width:22%}.runtime-table th:nth-child(4){width:8%}.runtime-table th:nth-child(5){width:33%}.warnings ul{padding:0;margin:0;list-style:none}.warnings li{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #edf0f4}.warnings li:last-child{border-bottom:0}.warnings li strong{color:#b45309}.warnings li span{color:#64748b}.erd-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin:10px 0 14px}.erd-node{display:block;padding:11px 12px;border:1px solid #bfdbfe;border-top:4px solid #2563eb;border-radius:10px;background:#fff;color:#0f172a;text-decoration:none;box-shadow:0 4px 12px rgba(15,23,42,.04)}.erd-node:hover{border-color:#60a5fa;background:#f8fbff}.erd-node>strong{display:block;font-family:"SFMono-Regular",Consolas,monospace;font-size:12px;color:#0f4c81;margin-bottom:3px}.erd-models{display:block;margin-bottom:7px;color:#7c3aed;font-size:9px}.erd-node small{display:flex;justify-content:space-between;gap:8px;padding:2px 0;border-top:1px solid #eef2f7;font-size:9px;color:#475569}.erd-node small code{color:#334155}.erd-node small em{font-style:normal;color:#94a3b8}.erd-node .erd-more{display:block;color:#64748b}.erd-links{margin-top:10px}.trace-list{display:flex;flex-direction:column;gap:8px}.trace-card{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,.8fr) auto minmax(0,1fr);gap:8px;align-items:center;padding:10px;border:1px solid #dbe3eb;border-radius:10px;background:#fbfdff}.trace-card>b{color:#94a3b8}.trace-step{min-width:0}.trace-step>span{display:block;margin-bottom:3px;color:#2563eb;font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.07em}.trace-step small{display:block;color:#94a3b8;font-size:8px}.trace-unresolved{opacity:.75}.analysis-mode-banner{display:flex;align-items:center;gap:10px;margin:0 0 10px;padding:10px 12px;border:1px solid #93c5fd;border-left:4px solid #2563eb;border-radius:10px;background:#eff6ff;color:#1e3a8a}.analysis-mode-banner>span:nth-child(2){flex:1}.analysis-mode-banner strong{display:block;font-size:11px;letter-spacing:.04em}.analysis-mode-banner small{display:block;margin-top:2px;color:#475569;font-size:10px;line-height:1.4}.analysis-mode-banner b{font-size:9px;color:#166534;background:#dcfce7;border:1px solid #86efac;border-radius:999px;padding:4px 7px}.analysis-mode-icon{font-size:18px}.static-analysis-note{margin:0 0 10px;padding:9px 11px;border:1px solid #bfdbfe;border-left:4px solid #2563eb;border-radius:9px;background:#eff6ff;color:#1e3a8a}.static-analysis-note strong{display:block;font-size:10px}.static-analysis-note span{display:block;margin-top:2px;font-size:10px;color:#475569}.source-quality-legend{display:flex;align-items:center;flex-wrap:wrap;gap:6px;margin:0 0 10px;padding:8px 10px;border:1px solid #dbe3eb;border-radius:9px;background:#f8fafc;font-size:9px}.source-quality-legend strong{margin-right:3px;color:#334155}.source-quality-legend span{display:inline-block;padding:2px 6px;border-radius:999px;color:#fff;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.source-quality-legend small{color:#64748b;margin-left:4px}.legend-critical{background:#dc2626}.legend-high{background:#ea580c}.legend-medium{background:#d97706}.legend-low{background:#2563eb}.quality-summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin:10px 0 14px}.quality-score,.quality-stat{padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;text-align:center}.quality-score span,.quality-stat span{display:block;color:#64748b;font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.quality-score strong,.quality-stat strong{font-size:22px;color:#0f172a}.quality-score small{color:#94a3b8}.quality-critical{border-color:#fecaca;background:#fff1f2}.quality-high{border-color:#fed7aa;background:#fff7ed}.quality-medium{border-color:#fde68a;background:#fffbeb}.quality-low{border-color:#bfdbfe;background:#eff6ff}.quality-findings{display:flex;flex-direction:column;gap:9px}.quality-finding{padding:11px 12px;border:1px solid #e2e8f0;border-left:4px solid #94a3b8;border-radius:10px;background:#fff}.quality-finding.severity-critical{border-left-color:#dc2626}.quality-finding.severity-high{border-left-color:#ea580c}.quality-finding.severity-medium{border-left-color:#d97706}.quality-finding.severity-low{border-left-color:#2563eb}.quality-finding-head{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.severity-badge,.confidence-badge,.quality-category{display:inline-block;padding:2px 6px;border-radius:999px;font-size:8px;font-weight:800}.severity-badge{background:#e2e8f0}.severity-critical{background:#fee2e2;color:#b91c1c}.severity-high{background:#ffedd5;color:#c2410c}.severity-medium{background:#fef3c7;color:#a16207}.severity-low{background:#dbeafe;color:#1d4ed8}.confidence-badge{background:#f1f5f9;color:#475569}.quality-category{background:#f5f3ff;color:#6d28d9;text-transform:uppercase}.quality-finding h3{margin:6px 0 3px;font-size:13px}.quality-finding p{margin:0 0 6px;color:#475569;font-size:11px;line-height:1.45}.quality-context{margin:4px 0}.quality-location{display:inline-block;margin-top:3px;color:#0369a1;font-family:"SFMono-Regular",Consolas,monospace;font-size:10px;font-weight:700;text-decoration:none}.quality-meta{margin-top:5px}.source-link{display:inline-flex;align-items:center;gap:6px;padding:4px 7px;border:1px solid #93c5fd;border-radius:7px;background:#eff6ff;color:#1d4ed8!important;text-decoration:none!important;font-weight:800;box-shadow:0 1px 0 rgba(37,99,235,.04)}.source-link:hover{background:#dbeafe;border-color:#60a5fa;box-shadow:0 3px 8px rgba(37,99,235,.12)}.source-link-label{display:inline-block;padding:2px 5px;border-radius:4px;background:#2563eb;color:#fff;font-family:Inter,ui-sans-serif,system-ui,sans-serif;font-size:8px;font-weight:900;line-height:1.2;letter-spacing:.06em;white-space:nowrap}.source-link-path{font-family:"SFMono-Regular",Consolas,"Liberation Mono",monospace}.source-link-arrow{font-size:13px;line-height:1}.source-link-compact{padding:3px 5px;gap:5px}.source-link-compact .inline-code{border:0;background:transparent;padding:0;color:#1d4ed8}.source-link-mini{font-family:Inter,ui-sans-serif,system-ui,sans-serif;font-size:7px;text-transform:uppercase;letter-spacing:.05em;color:#2563eb}.quality-source-link{margin-top:5px;padding:6px 8px}.quality-source-link .source-link-label{font-size:8px}.source-action-link{margin-top:2px}.class-location.source-link{float:right;max-width:58%;font-size:9px}.location-link.source-link{font-family:"SFMono-Regular",Consolas,"Liberation Mono",monospace;font-size:9px}.class-index-table .source-link{white-space:normal}.release-overview{display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.4fr);gap:12px;margin-top:14px}.overview-panel{padding:13px 14px;border:1px solid #dbe3eb;border-radius:12px;background:#fbfdff}.overview-panel h3{margin:0 0 9px;color:#0f172a;font-size:13px}.stack-chips{display:flex;flex-wrap:wrap;gap:6px}.stack-chips span{display:inline-block;padding:5px 8px;border:1px solid #bfdbfe;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:10px;font-weight:800}.coverage-state{float:right;padding:2px 5px;border-radius:999px;font-size:7px;letter-spacing:.05em}.coverage-complete{background:#dcfce7;color:#166534}.coverage-review{background:#fef3c7;color:#92400e}.coverage-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}.coverage-item{padding:7px 8px;border:1px solid #e2e8f0;border-radius:8px;background:#fff}.coverage-item span{display:block;color:#64748b;font-size:8px;font-weight:800;text-transform:uppercase}.coverage-item strong{display:block;margin:2px 0;color:#0f172a;font-size:14px}.coverage-item small{display:block;color:#94a3b8;font-size:8px;line-height:1.3}.coverage-issues{margin-top:6px;padding-top:5px;border-top:1px solid #e2e8f0}.coverage-issues>strong{display:block;margin-bottom:3px;color:#92400e;font-size:8px;text-transform:uppercase}.coverage-issues span{display:block;padding:2px 0;color:#64748b;font-size:8px}.coverage-issues span b{display:inline-block;min-width:78px;color:#b45309}.coverage-issues small{display:block;margin-top:2px;color:#94a3b8;font-size:7px}.needs-attention{margin-top:12px;padding:12px 13px;border:1px solid #fed7aa;border-left:4px solid #f59e0b;border-radius:10px;background:#fffaf5}.needs-attention-clear{display:flex;align-items:center;justify-content:space-between;border-color:#bbf7d0;border-left-color:#16a34a;background:#f0fdf4}.needs-attention-clear strong,.attention-heading strong{display:block;color:#0f172a;font-size:12px}.needs-attention-clear span,.attention-heading span{display:block;margin-top:2px;color:#64748b;font-size:9px}.needs-attention-clear a,.attention-heading a{color:#1d4ed8;text-decoration:none;font-size:9px;font-weight:800}.attention-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:7px}.attention-list{border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#fff}.attention-row{display:grid;grid-template-columns:82px minmax(0,1fr) minmax(160px,.65fr) 16px;gap:8px;align-items:center;padding:6px 8px;border-bottom:1px solid #edf2f7;color:#0f172a;text-decoration:none;font-size:9px}.attention-row:last-child{border-bottom:0}.attention-row:hover{background:#f8fafc}.attention-row small{color:#64748b;font-family:"SFMono-Regular",Consolas,monospace;overflow-wrap:anywhere}.attention-tier{font-size:7px;font-weight:900;letter-spacing:.05em}.attention-critical .attention-tier,.attention-high .attention-tier{color:#b91c1c}.attention-medium .attention-tier{color:#a16207}.attention-low .attention-tier{color:#1d4ed8}.contents-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px}.contents-item{display:grid;grid-template-columns:34px minmax(0,1fr) 16px;gap:9px;align-items:center;padding:9px 10px;border:1px solid #dbe3eb;border-radius:9px;background:#fbfdff;color:#0f172a;text-decoration:none}.contents-item:hover{border-color:#93c5fd;background:#eff6ff}.contents-item>span{display:grid;place-items:center;width:30px;height:30px;border-radius:8px;background:#eff6ff;color:#1d4ed8;font-size:9px;font-weight:900}.contents-item strong{display:block;font-size:11px}.contents-item small{display:block;margin-top:2px;color:#64748b;font-size:8px}.contents-item>b{color:#2563eb}.review-tier-summary{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:-5px 0 11px;padding:7px 9px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;font-size:8px}.review-tier-summary span{display:inline-flex;gap:5px;padding:3px 6px;border-radius:6px;background:#fff;border:1px solid #e2e8f0}.review-tier-summary small{color:#64748b}.review-tier-summary strong{color:#334155}.quality-only-content .hero-copy{max-width:1040px}.quality-only-content section{padding:30px 32px}.quality-only-content .quality-finding{width:100%;padding:15px 17px}.quality-only-content .quality-finding h3{font-size:15px}.quality-only-content .quality-finding p{font-size:12px;line-height:1.55}.quality-only-content .quality-source-link{max-width:100%}.quality-only-content .quality-source-link .source-link-path{overflow-wrap:anywhere}.quality-only-content .quality-code-snippet-head{padding:8px 10px;font-size:10px}.quality-only-content .quality-code-snippet td{padding:3px 8px;line-height:1.4}.quality-only-content .quality-code-line-number{width:58px}.quality-only-content .quality-summary{grid-template-columns:repeat(6,minmax(110px,1fr))}@media(max-width:1050px){.doc-shell{display:block;max-width:1220px;padding:0}.doc-sidebar{display:none}.doc-content{padding:28px 18px 90px}.doc-nav{display:flex;position:sticky}.trace-card{grid-template-columns:1fr}.trace-card>b{transform:rotate(90deg);text-align:center}.erd-grid{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}.release-overview,.contents-grid{grid-template-columns:1fr}.coverage-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.attention-row{grid-template-columns:78px minmax(0,1fr);}.attention-row small,.attention-row b{display:none}}@media print{.doc-sidebar{display:none}.doc-shell{display:block;padding:0}.doc-content{padding:0}.floating-top{display:none}.dependency-columns{display:block}.dependency-panel{margin-bottom:8px}body{background:#fff}main{max-width:none;padding:0}.doc-nav{position:static;box-shadow:none}section,.hero{box-shadow:none}.source-details>summary{display:none}.source-details>.code-editor{display:block}}

.quality-only-shell{display:block;width:100%;max-width:1640px;margin:0 auto;padding:0 24px}.quality-only-content{width:100%;max-width:1560px;margin:0 auto;padding-left:0;padding-right:0}.quality-only-nav{display:flex;position:sticky}.quality-only-overview{margin-bottom:18px}.quality-only-scope{display:flex;flex-direction:column;gap:3px;margin-top:11px;padding:10px 12px;border:1px solid #bfdbfe;border-left:4px solid #2563eb;border-radius:9px;background:#eff6ff;color:#1e3a8a}.quality-only-scope strong{font-size:11px;text-transform:uppercase}.quality-only-scope span{font-size:10px;color:#475569}.quality-code-snippet{width:100%;margin:11px 0 3px;border:1px solid #37393d;border-radius:8px;overflow-x:auto;overflow-y:hidden;background:#1e1f22;color:#d4d4d4}.quality-code-snippet-head{display:flex;justify-content:space-between;gap:12px;padding:6px 8px;background:#2b2d30;color:#a9b7c6;font-size:9px}.quality-code-snippet-head strong{color:#fff}.quality-code-snippet table{width:100%;min-width:100%;margin:0;border-collapse:collapse;table-layout:auto;background:#1e1f22;font-family:"SFMono-Regular",Consolas,"Liberation Mono",monospace;font-size:10.5px}.quality-code-snippet tr,.quality-code-snippet tr:nth-child(even){background:#1e1f22}.quality-code-snippet td{padding:2px 6px;border:0;line-height:1.35;vertical-align:top}.quality-code-line-number{width:52px;text-align:right;color:#6b7280;border-right:1px solid #35373b!important;white-space:nowrap}.quality-code-line-number b{display:inline-block;margin-left:5px;color:#fff}.quality-code-line{width:100%;white-space:pre;overflow-wrap:normal;word-break:normal}.quality-code-line code{font-family:inherit;color:#d4d4d4}.quality-code-snippet tr.problem.severity-critical td{background:#4a2025}.quality-code-snippet tr.problem.severity-high td{background:#4b2b1d}.quality-code-snippet tr.problem.severity-medium td{background:#453916}.quality-code-snippet tr.problem.severity-low td{background:#1c3557}.quality-code-snippet tr.problem td:first-child{box-shadow:inset 4px 0 0 #f59e0b}.quality-code-snippet tr.problem.severity-critical td:first-child{box-shadow:inset 4px 0 0 #ef4444}.quality-code-snippet tr.problem.severity-high td:first-child{box-shadow:inset 4px 0 0 #f97316}.quality-code-snippet tr.problem.severity-medium td:first-child{box-shadow:inset 4px 0 0 #f59e0b}.quality-code-snippet tr.problem.severity-low td:first-child{box-shadow:inset 4px 0 0 #3b82f6}.quality-code-truncated td{padding:5px 7px!important;color:#94a3b8!important;font-family:Inter,ui-sans-serif,system-ui,sans-serif;font-size:8px}.quality-code-back{display:block;padding:5px 8px;border-top:1px solid #37393d;background:#25262a;color:#93c5fd;text-decoration:none;font-size:8px;font-weight:800}.quality-code-unavailable{display:flex;flex-direction:column;gap:2px;margin-top:8px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:7px;background:#f8fafc;color:#475569;font-size:9px}.quality-code-unavailable strong{color:#334155}

CSS;
    }
}
