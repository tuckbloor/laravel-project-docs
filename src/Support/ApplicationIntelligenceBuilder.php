<?php

namespace DevDocs\LaravelProjectDocs\Support;

use Illuminate\Support\Str;

class ApplicationIntelligenceBuilder
{
    public function __construct(private readonly StaticQualityAnalyzer $staticQuality)
    {
    }

    /**
     * @param array<string, array<string, mixed>> $sections
     * @return array<string, mixed>
     */
    public function build(array $sections): array
    {
        $index = $this->classIndex($sections['php']['items'] ?? []);
        $usedBy = [];
        $calls = [];
        $models = [];
        $validations = [];
        $runtime = [];
        $quality = [];
        $qualityScope = QualityScope::fromSections($sections);
        $modelClasses = $this->modelClassSet($index);

        foreach ($index['by_fqcn'] as $fqcn => $entry) {
            $usedBy[$fqcn] = [];
            $class = $entry['class'];
            $category = (string) ($class['category'] ?? 'class');

            if (isset($modelClasses[$fqcn])) {
                $model = (array) ($class['model'] ?? []);
                $model['class'] = $fqcn;
                $model['name'] = $class['name'] ?? class_basename($fqcn);
                $model['path'] = $entry['path'];
                $model['table_effective'] = $model['table'] ?? Str::plural(Str::snake((string) $model['name']));
                $models[] = $model;
            }

            if ($category === 'request' && ! empty($class['validation']['rules'])) {
                $validations[] = [
                    'class' => $fqcn,
                    'name' => $class['name'] ?? class_basename($fqcn),
                    'path' => $entry['path'],
                    'rules' => $class['validation']['rules'],
                    'start_line' => $class['validation']['start_line'] ?? $class['start_line'] ?? 1,
                ];
            }

            if (in_array($category, ['job', 'event', 'listener', 'mail', 'notification', 'policy', 'command', 'middleware', 'observer'], true)) {
                $runtime[] = [
                    'class' => $fqcn,
                    'name' => $class['name'] ?? class_basename($fqcn),
                    'category' => $category,
                    'path' => $entry['path'],
                    'methods' => array_values(array_map(fn (array $method) => $method['name'] ?? '', $class['methods'] ?? [])),
                ];
            }

            $metrics = (array) ($class['metrics'] ?? []);
            $flags = [];
            if (($metrics['lines'] ?? 0) > 700) {
                $flags[] = 'Large class: '.(int) $metrics['lines'].' lines';
            }
            if (($metrics['methods'] ?? 0) > 25) {
                $flags[] = 'Many methods: '.(int) $metrics['methods'];
            }
            if (($metrics['dependencies'] ?? 0) > 10) {
                $flags[] = 'Many dependencies: '.(int) $metrics['dependencies'];
            }
            if (($metrics['max_method_lines'] ?? 0) > 120) {
                $flags[] = 'Long method: '.(int) $metrics['max_method_lines'].' lines';
            }
            if (($metrics['max_method_complexity'] ?? 0) > 15) {
                $flags[] = 'High method complexity: '.(int) $metrics['max_method_complexity'];
            }
            if ($flags !== [] && $qualityScope->includes((string) ($entry['path'] ?? ''))) {
                $quality[] = [
                    'class' => $fqcn,
                    'path' => $entry['path'],
                    'metrics' => $metrics,
                    'flags' => $flags,
                ];
            }
        }

        // The detailed Models section must never disagree with the scanned source.
        // If static/runtime Eloquent detection misses a file, conventional model
        // source paths still produce a model card with sensible defaults.
        $models = $this->mergeModelsFromScannedFiles(
            $models,
            (array) ($sections['php']['items'] ?? []),
        );

        foreach (($sections['routes']['items'] ?? []) as $route) {
            [$routeClass, $routeMethod] = $this->actionParts((string) ($route['action'] ?? ''));
            $target = $this->resolveRouteController($routeClass, $index);
            if ($target !== null) {
                $usedBy[$target][] = [
                    'type' => 'route',
                    'source' => implode('|', $route['methods'] ?? []).' '.($route['uri'] ?? ''),
                    'context' => $routeMethod,
                ];
            }
        }

        foreach (($sections['php']['items'] ?? []) as $file) {
            $sourcePath = (string) ($file['path'] ?? '');
            $sourceClass = $this->firstNamedClass($file);
            $imports = $this->importMap($file);
            $namespace = (string) ($file['namespace'] ?? '');

            foreach (($file['classes'] ?? []) as $class) {
                $fromClass = ltrim((string) ($class['fqcn'] ?? ''), '\\');
                if ($fromClass === '') {
                    continue;
                }

                foreach (($class['dependencies'] ?? []) as $dependency) {
                    $resolved = $this->resolveClass((string) ($dependency['type'] ?? ''), $file, $index, $imports, $namespace);
                    if ($resolved !== null && $resolved !== $fromClass) {
                        $usedBy[$resolved][] = [
                            'type' => 'dependency',
                            'source' => $fromClass,
                            'context' => (string) ($dependency['variable'] ?? ''),
                        ];
                    }
                }

                $dependencyVariables = [];
                foreach (($class['dependencies'] ?? []) as $dependency) {
                    $resolved = $this->resolveClass((string) ($dependency['type'] ?? ''), $file, $index, $imports, $namespace);
                    if ($resolved !== null) {
                        $dependencyVariables[(string) ($dependency['variable'] ?? '')] = $resolved;
                    }
                }

                foreach (($class['methods'] ?? []) as $method) {
                    foreach (($method['calls'] ?? []) as $call) {
                        $target = null;
                        $rawTarget = (string) ($call['target'] ?? '');
                        if (isset($dependencyVariables[$rawTarget])) {
                            $target = $dependencyVariables[$rawTarget];
                        } elseif (in_array($call['type'] ?? '', ['static', 'new'], true)) {
                            $target = $this->resolveClass($rawTarget, $file, $index, $imports, $namespace);
                        }

                        $callEntry = [
                            'from_class' => $fromClass,
                            'from_method' => (string) ($method['name'] ?? ''),
                            'target_raw' => $rawTarget,
                            'target_class' => $target,
                            'target_method' => $call['method'] ?? null,
                            'type' => $call['type'] ?? 'call',
                            'line' => $call['line'] ?? null,
                        ];
                        $calls[] = $callEntry;

                        if ($target !== null && $target !== $fromClass) {
                            $usedBy[$target][] = [
                                'type' => 'call',
                                'source' => $fromClass.'::'.($method['name'] ?? '').'()',
                                'context' => ($call['method'] ?? null) ? 'calls '.$call['method'].'()' : null,
                            ];
                        }
                    }
                }
            }

            foreach (($file['uses'] ?? []) as $use) {
                if (($use['type'] ?? 'class') !== 'class' || $sourceClass === null) {
                    continue;
                }
                $target = $this->resolveClass((string) ($use['name'] ?? ''), $file, $index, $imports, $namespace);
                if ($target !== null && $target !== $sourceClass) {
                    $usedBy[$target][] = [
                        'type' => 'import',
                        'source' => $sourceClass,
                        'context' => $sourcePath,
                    ];
                }
            }
        }

        foreach ($usedBy as $target => &$references) {
            $references = array_values(array_unique($references, SORT_REGULAR));
        }
        unset($references);

        $workflows = $this->workflows($sections, $index, $calls, $models);
        $possiblyUnused = [];
        foreach ($index['by_fqcn'] as $fqcn => $entry) {
            $category = (string) ($entry['class']['category'] ?? 'class');
            if ($qualityScope->includes((string) ($entry['path'] ?? ''))
                && ($usedBy[$fqcn] ?? []) === []
                && ! in_array($category, ['provider', 'middleware', 'command', 'event', 'listener', 'observer'], true)) {
                $possiblyUnused[] = [
                    'class' => $fqcn,
                    'category' => $category,
                    'path' => $entry['path'],
                    'note' => 'No static incoming references were detected. Laravel may still resolve this class dynamically.',
                ];
            }
        }

        usort($models, fn (array $a, array $b) => strcmp((string) $a['class'], (string) $b['class']));
        usort($validations, fn (array $a, array $b) => strcmp((string) $a['class'], (string) $b['class']));
        usort($runtime, fn (array $a, array $b) => [$a['category'], $a['class']] <=> [$b['category'], $b['class']]);

        $frontendMap = [];
        foreach (($sections['relationships']['items'] ?? []) as $relationship) {
            if (! in_array($relationship['type'] ?? '', ['frontend-import', 'frontend-http', 'frontend-route', 'blade-include', 'blade-route'], true)) { continue; }
            $source = (string) ($relationship['from'] ?? '');
            $frontendMap[$source] ??= [];
            $frontendMap[$source][] = [
                'type' => $relationship['type'] ?? 'reference',
                'to' => $relationship['to'] ?? '',
                'target_path' => $relationship['target_path'] ?? null,
            ];
        }
        ksort($frontendMap);

        $erd = $this->databaseErd($sections, $models, $index);
        $frontendBackend = $this->frontendBackendMap($sections, $index);
        $qualityReport = $this->staticQuality->analyze($sections, $usedBy, $calls, $models);

        return [
            'models' => $models,
            'model_detection' => [
                'count' => count($models),
                'classes' => array_values(array_map(
                    static fn (array $model): string => (string) ($model['class'] ?? ''),
                    $models,
                )),
            ],
            'validation' => $validations,
            'runtime' => $runtime,
            'used_by' => $usedBy,
            'calls' => $calls,
            'workflows' => $workflows,
            'quality' => $quality,
            'quality_report' => $qualityReport,
            'possibly_unused' => $possiblyUnused,
            'frontend_map' => $frontendMap,
            'frontend_backend' => $frontendBackend,
            'erd' => $erd,
        ];
    }


    /**
     * Resolve Eloquent models, including project-specific base model chains.
     *
     * @param array{by_fqcn:array<string,array<string,mixed>>,short:array<string,array<int,string>>} $index
     * @return array<string, true>
     */
    private function modelClassSet(array $index): array
    {
        $models = [];

        foreach ($index['by_fqcn'] as $fqcn => $entry) {
            if (
                $this->isDirectModelClass((array) ($entry['class'] ?? []), (string) ($entry['path'] ?? ''))
            ) {
                $models[$fqcn] = true;
            }
        }

        // A project may have CustomBaseModel extends Model and then many models
        // extending CustomBaseModel. Resolve that inheritance transitively.
        do {
            $changed = false;
            foreach ($index['by_fqcn'] as $fqcn => $entry) {
                if (isset($models[$fqcn])) {
                    continue;
                }

                $class = (array) ($entry['class'] ?? []);
                $extends = trim((string) ($class['extends'] ?? ''));
                if ($extends === '') {
                    continue;
                }

                $parent = $this->resolveClass($extends, (array) ($entry['file'] ?? []), $index);
                if ($parent !== null && isset($models[$parent])) {
                    $models[$fqcn] = true;
                    $changed = true;
                }
            }
        } while ($changed);

        return $models;
    }

    /** @param array<string, mixed> $class */
    private function isDirectModelClass(array $class, string $path): bool
    {
        if (($class['category'] ?? null) === 'model' || is_array($class['model'] ?? null)) {
            return true;
        }

        $normalPath = strtolower(str_replace('\\', '/', $path));
        $fqcn = strtolower(ltrim((string) ($class['fqcn'] ?? ''), '\\'));
        $extends = strtolower(ltrim((string) ($class['extends'] ?? ''), '\\'));

        $pathSegments = array_values(array_filter(explode('/', trim($normalPath, '/'))));
        if (in_array('models', $pathSegments, true)) {
            return true;
        }

        if (str_starts_with($fqcn, 'app\\models\\')) {
            return true;
        }

        $knownBases = [
            'model',
            'authenticatable',
            'pivot',
            'morphpivot',
            'illuminate\\database\\eloquent\\model',
            'illuminate\\foundation\\auth\\user',
            'illuminate\\database\\eloquent\\relations\\pivot',
            'illuminate\\database\\eloquent\\relations\\morphpivot',
        ];

        return in_array($extends, $knownBases, true)
            || str_ends_with($extends, '\\model')
            || str_ends_with($extends, 'basemodel');
    }

    /**
     * Merge conventional model source files into the intelligence model list.
     * This is intentionally source-driven: if the package scanned a model file,
     * the Models section must display it even when deeper Eloquent parsing fails.
     *
     * @param array<int, array<string,mixed>> $models
     * @param array<int, array<string,mixed>> $phpFiles
     * @return array<int, array<string,mixed>>
     */
    private function mergeModelsFromScannedFiles(array $models, array $phpFiles): array
    {
        $merged = [];
        foreach ($models as $model) {
            $identity = strtolower((string) ($model['class'] ?? $model['path'] ?? ''));
            if ($identity !== '') {
                $merged[$identity] = $model;
            }
        }

        foreach ($phpFiles as $file) {
            $path = (string) ($file['path'] ?? '');
            if (! $this->isConventionalModelPath($path)) {
                continue;
            }

            $classes = array_values(array_filter(
                (array) ($file['classes'] ?? []),
                static fn ($class): bool => is_array($class) && (string) ($class['name'] ?? '') !== 'anonymous',
            ));

            if ($classes === []) {
                // Parser fallback may have failed to recover a class declaration.
                // Still represent the model file by its conventional class name.
                $name = pathinfo($path, PATHINFO_FILENAME);
                $namespace = trim((string) ($file['namespace'] ?? 'App\\Models'), '\\');
                $classes[] = [
                    'name' => $name,
                    'fqcn' => ($namespace !== '' ? $namespace.'\\' : '').$name,
                    'model' => null,
                ];
            }

            foreach ($classes as $class) {
                $name = (string) ($class['name'] ?? pathinfo($path, PATHINFO_FILENAME));
                $fqcn = ltrim((string) ($class['fqcn'] ?? ''), '\\');
                if ($fqcn === '') {
                    $namespace = trim((string) ($file['namespace'] ?? 'App\\Models'), '\\');
                    $fqcn = ($namespace !== '' ? $namespace.'\\' : '').$name;
                }

                $identity = strtolower($fqcn !== '' ? $fqcn : $path.'#'.$name);
                $metadata = is_array($class['model'] ?? null) ? (array) $class['model'] : [];
                $existing = (array) ($merged[$identity] ?? []);
                $relationships = (array) ($existing['relationships'] ?? $metadata['relationships'] ?? []);
                if ($relationships === []) {
                    $relationships = $this->relationshipsFromSource((string) ($file['source'] ?? ''));
                }

                $merged[$identity] = array_merge([
                    'table' => null,
                    'connection' => null,
                    'primary_key' => 'id',
                    'timestamps' => true,
                    'soft_deletes' => false,
                    'fillable' => [],
                    'guarded' => [],
                    'hidden' => [],
                    'casts' => [],
                    'relationships' => [],
                ], $metadata, $existing, [
                    'class' => $fqcn,
                    'name' => $name,
                    'path' => $path,
                    'table_effective' => ($existing['table_effective'] ?? null)
                        ?: (($metadata['table'] ?? null) ?: Str::plural(Str::snake($name))),
                    'relationships' => $relationships,
                    'detection' => $existing['detection'] ?? 'model-source-path',
                ]);
            }
        }

        return array_values($merged);
    }

    private function isConventionalModelPath(string $path): bool
    {
        $normal = strtolower(str_replace('\\', '/', trim($path, '/')));
        return preg_match('~(^|/)(models?|eloquent)(/|$)~', $normal) === 1;
    }

    /** @return array<int, array<string,mixed>> */
    private function relationshipsFromSource(string $source): array
    {
        if ($source === '') {
            return [];
        }

        $types = 'belongsTo|hasOne|hasMany|belongsToMany|hasOneThrough|hasManyThrough|morphTo|morphOne|morphMany|morphToMany|morphedByMany';
        $relationships = [];
        $callPattern = '/\$this->('.$types.')\s*\(\s*(?:([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class)?/i';

        if (! preg_match_all($callPattern, $source, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($calls as $call) {
            $offset = (int) ($call[0][1] ?? 0);
            $prefix = substr($source, 0, $offset);
            $method = 'relationship';
            if (preg_match_all('/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\([^)]*\)/i', $prefix, $functions, PREG_SET_ORDER)) {
                $last = end($functions);
                if (is_array($last) && isset($last[1])) {
                    $method = (string) $last[1];
                }
            }

            $target = ltrim((string) ($call[2][0] ?? 'dynamic'), '\\');
            $relationships[] = [
                'method' => $method,
                'type' => (string) ($call[1][0] ?? ''),
                'target' => $target !== '' ? $target : 'dynamic',
            ];
        }

        return array_values(array_unique($relationships, SORT_REGULAR));
    }

    private function resolveRouteController(string $rawClass, array $index): ?string
    {
        $rawClass = ltrim(trim($rawClass), '\\');
        if ($rawClass === '') {
            return null;
        }

        $resolved = $this->resolveClass($rawClass, null, $index);
        if ($resolved !== null) {
            return $resolved;
        }

        $short = str_contains($rawClass, '\\')
            ? substr($rawClass, (int) strrpos($rawClass, '\\') + 1)
            : $rawClass;
        $matches = (array) ($index['short'][$short] ?? []);
        if (count($matches) === 1) {
            return $matches[0];
        }

        // Final static fallback: match a scanned controller filename. This also
        // works when PHP parsing recovered the file but not a perfect FQCN.
        foreach ($index['by_fqcn'] as $fqcn => $entry) {
            $path = strtolower(str_replace('\\', '/', (string) ($entry['path'] ?? '')));
            if (str_ends_with($path, '/'.strtolower($short).'.php') && str_contains($path, '/controllers/')) {
                return $fqcn;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function findControllerFile(string $rawClass, array $index): ?array
    {
        $short = str_contains($rawClass, '\\')
            ? substr($rawClass, (int) strrpos($rawClass, '\\') + 1)
            : $rawClass;
        $short = strtolower(trim($short));
        if ($short === '') {
            return null;
        }

        foreach ($index['by_fqcn'] as $entry) {
            $path = strtolower(str_replace('\\', '/', (string) ($entry['path'] ?? '')));
            if (str_ends_with($path, '/'.$short.'.php') && str_contains($path, '/controllers/')) {
                return (array) ($entry['file'] ?? []);
            }
        }

        return null;
    }

    /** @return array{by_fqcn:array<string,array<string,mixed>>,short:array<string,array<int,string>>} */
    private function classIndex(array $phpFiles): array
    {
        $byFqcn = [];
        $short = [];
        foreach ($phpFiles as $file) {
            foreach (($file['classes'] ?? []) as $class) {
                $name = (string) ($class['name'] ?? '');
                if ($name === '' || $name === 'anonymous') {
                    continue;
                }

                $fqcn = ltrim((string) ($class['fqcn'] ?? ''), '\\');
                if ($fqcn === '') {
                    $namespace = trim((string) ($file['namespace'] ?? ''), '\\');
                    $fqcn = $namespace !== '' ? $namespace.'\\'.$name : $name;
                    $class['fqcn'] = $fqcn;
                }

                $byFqcn[$fqcn] = ['class' => $class, 'file' => $file, 'path' => (string) ($file['path'] ?? '')];
                $short[$name][] = $fqcn;
            }
        }
        return ['by_fqcn' => $byFqcn, 'short' => $short];
    }

    private function resolveClass(string $name, ?array $file, array $index, ?array $imports = null, ?string $namespace = null): ?string
    {
        $name = ltrim(trim($name, '?'), '\\');
        if ($name === '' || in_array(strtolower($name), ['dynamic', 'expression', 'self', 'static', 'parent'], true)) {
            return null;
        }
        if (isset($index['by_fqcn'][$name])) {
            return $name;
        }

        $imports ??= $file ? $this->importMap($file) : [];
        $namespace ??= (string) ($file['namespace'] ?? '');
        if (isset($imports[$name]) && isset($index['by_fqcn'][$imports[$name]])) {
            return $imports[$name];
        }
        if (! str_contains($name, '\\') && $namespace !== '') {
            $candidate = trim($namespace, '\\').'\\'.$name;
            if (isset($index['by_fqcn'][$candidate])) {
                return $candidate;
            }
        }
        $short = str_contains($name, '\\') ? substr($name, (int) strrpos($name, '\\') + 1) : $name;
        $matches = $index['short'][$short] ?? [];
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return array<string,string> */
    private function importMap(array $file): array
    {
        $map = [];
        foreach (($file['uses'] ?? []) as $use) {
            if (($use['type'] ?? 'class') !== 'class') {
                continue;
            }
            $fqcn = ltrim((string) ($use['name'] ?? ''), '\\');
            $short = (string) ($use['alias'] ?? '');
            if ($short === '') {
                $short = str_contains($fqcn, '\\') ? substr($fqcn, (int) strrpos($fqcn, '\\') + 1) : $fqcn;
            }
            $map[$short] = $fqcn;
        }
        return $map;
    }

    private function firstNamedClass(array $file): ?string
    {
        foreach (($file['classes'] ?? []) as $class) {
            $fqcn = ltrim((string) ($class['fqcn'] ?? ''), '\\');
            if ($fqcn !== '') {
                return $fqcn;
            }
        }
        return null;
    }

    /** @return array{0:string,1:?string} */
    private function actionParts(string $action): array
    {
        $action = trim($action);
        if ($action === '' || strcasecmp($action, 'Closure') === 0) {
            return ['', null];
        }

        // Laravel normally exposes controller actions as Class@method, but a
        // few integrations/custom routers expose Class::method or Class::__invoke.
        if (str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);
            return [ltrim(trim($class), '\\'), trim($method) ?: '__invoke'];
        }
        if (str_contains($action, '::')) {
            [$class, $method] = explode('::', $action, 2);
            $class = preg_replace('/::class$/', '', $class) ?: $class;
            return [ltrim(trim($class), '\\'), trim($method) ?: '__invoke'];
        }

        return [ltrim(preg_replace('/::class$/', '', $action) ?: $action, '\\'), '__invoke'];
    }

    /** @return array<int, array<string,mixed>> */
    private function workflows(array $sections, array $index, array $calls, array $models): array
    {
        $byMethod = [];
        foreach ($calls as $call) {
            $key = ($call['from_class'] ?? '').'::'.($call['from_method'] ?? '');
            $byMethod[$key][] = $call;
        }

        $fileByClass = [];
        foreach ($index['by_fqcn'] as $fqcn => $entry) {
            $fileByClass[$fqcn] = $entry['file'];
        }

        $modelTables = [];
        foreach ($models as $model) {
            $class = ltrim((string) ($model['class'] ?? ''), '\\');
            if ($class !== '') {
                $modelTables[$class] = (string) ($model['table_effective'] ?? $model['table'] ?? '');
            }
        }

        $workflows = [];
        foreach (($sections['routes']['items'] ?? []) as $route) {
            [$rawClass, $method] = $this->actionParts((string) ($route['action'] ?? ''));
            if ($rawClass === '' || $method === null) {
                continue;
            }

            $controller = $this->resolveRouteController($rawClass, $index);
            $controllerLabel = $controller ?? ltrim($rawClass, '\\');
            $controllerFile = $controller !== null
                ? ($fileByClass[$controller] ?? null)
                : $this->findControllerFile($rawClass, $index);

            $steps = [];
            $frontendSources = $this->frontendSourcesForRoute($route, $sections);
            foreach ($frontendSources as $source) {
                $steps[] = [
                    'type' => 'frontend',
                    'label' => $source['path'].(! empty($source['line']) ? ':'.$source['line'] : ''),
                    'path' => $source['path'],
                    'line' => $source['line'] ?? null,
                ];
            }

            $steps[] = [
                'type' => 'route',
                'label' => implode('|', $route['methods'] ?? []).' '.($route['uri'] ?? ''),
                'route_name' => $route['name'] ?? null,
            ];

            foreach ((array) ($route['middleware'] ?? []) as $middleware) {
                $steps[] = ['type' => 'middleware', 'label' => (string) $middleware];
            }

            $steps[] = [
                'type' => 'controller',
                'label' => $controllerLabel.'::'.$method.'()',
                'class' => $controller,
                'resolved' => $controller !== null,
            ];

            if ($controller !== null) {
                $methodEntry = $this->methodEntry($controller, $method, $index);
                if ($methodEntry !== null) {
                    $controllerFileArray = (array) ($index['by_fqcn'][$controller]['file'] ?? []);
                    $imports = $this->importMap($controllerFileArray);
                    $namespace = (string) ($controllerFileArray['namespace'] ?? '');
                    foreach ((array) ($methodEntry['parameters'] ?? []) as $parameter) {
                        $type = (string) ($parameter['type'] ?? '');
                        $resolved = $this->resolveClass($type, $controllerFileArray, $index, $imports, $namespace);
                        if ($resolved !== null && (($index['by_fqcn'][$resolved]['class']['category'] ?? '') === 'request')) {
                            $steps[] = [
                                'type' => 'request',
                                'label' => $resolved,
                                'class' => $resolved,
                            ];
                        }
                    }
                }

                $seen = [];
                foreach ($this->workflowCallSteps($controller, $method, $byMethod, $index, $modelTables, 0, $seen) as $step) {
                    $steps[] = $step;
                }
            }

            $file = is_array($controllerFile) ? $controllerFile : [];
            foreach (($file['references']['dispatches'] ?? []) as $job) {
                $resolvedJob = $this->resolveClass((string) $job, $file ?: null, $index);
                $steps[] = [
                    'type' => 'dispatch',
                    'label' => 'Dispatch: '.$job,
                    'class' => $resolvedJob,
                ];
            }
            foreach (($file['references']['inertia_pages'] ?? []) as $page) {
                $steps[] = ['type' => 'inertia', 'label' => 'Inertia: '.$page];
            }
            foreach (($file['references']['views'] ?? []) as $view) {
                $steps[] = ['type' => 'view', 'label' => 'View: '.$view];
            }

            $steps = array_values(array_unique($steps, SORT_REGULAR));
            $workflows[] = [
                'name' => ($route['name'] ?? null) ?: (implode('|', $route['methods'] ?? []).' '.($route['uri'] ?? '')),
                'uri' => $route['uri'] ?? '',
                'methods' => $route['methods'] ?? [],
                'middleware' => $route['middleware'] ?? [],
                'controller' => $controller,
                'controller_method' => $method,
                'controller_resolved' => $controller !== null,
                'frontend_sources' => $frontendSources,
                'steps' => $steps,
            ];
        }

        return $workflows;
    }

    /** @return array<string,mixed>|null */
    private function methodEntry(string $class, string $method, array $index): ?array
    {
        foreach ((array) ($index['by_fqcn'][$class]['class']['methods'] ?? []) as $entry) {
            if ((string) ($entry['name'] ?? '') === $method) {
                return $entry;
            }
        }
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    private function workflowCallSteps(string $class, string $method, array $byMethod, array $index, array $modelTables, int $depth, array &$seen): array
    {
        if ($depth > 2) {
            return [];
        }

        $key = $class.'::'.$method;
        if (isset($seen[$key])) {
            return [];
        }
        $seen[$key] = true;

        $steps = [];
        foreach (array_slice((array) ($byMethod[$key] ?? []), 0, 12) as $call) {
            $target = ltrim((string) ($call['target_class'] ?? ''), '\\');
            if ($target === '' || $target === $class) {
                continue;
            }
            $targetMethod = (string) ($call['target_method'] ?? '');
            $category = (string) ($index['by_fqcn'][$target]['class']['category'] ?? 'call');
            $type = match ($category) {
                'model' => 'model',
                'job' => 'job',
                'service' => 'service',
                'request' => 'request',
                'event' => 'event',
                'mail' => 'mail',
                'notification' => 'notification',
                default => 'call',
            };
            $steps[] = [
                'type' => $type,
                'label' => $target.($targetMethod !== '' ? '::'.$targetMethod.'()' : ''),
                'class' => $target,
                'depth' => $depth,
            ];

            if (isset($modelTables[$target]) && $modelTables[$target] !== '') {
                $steps[] = [
                    'type' => 'database',
                    'label' => 'Table: '.$modelTables[$target],
                    'table' => $modelTables[$target],
                    'depth' => $depth,
                ];
            }

            if ($targetMethod !== '' && isset($index['by_fqcn'][$target])) {
                foreach ($this->workflowCallSteps($target, $targetMethod, $byMethod, $index, $modelTables, $depth + 1, $seen) as $nested) {
                    $steps[] = $nested;
                }
            }
        }

        return $steps;
    }

    /** @return array<int,array{path:string,line:?int}> */
    private function frontendSourcesForRoute(array $route, array $sections): array
    {
        $action = (string) ($route['action'] ?? '');
        $name = (string) ($route['name'] ?? '');
        $uri = (string) ($route['uri'] ?? '');
        $sources = [];

        foreach ((array) ($sections['relationships']['items'] ?? []) as $relation) {
            $type = (string) ($relation['type'] ?? '');
            if (! in_array($type, ['frontend-http', 'frontend-route', 'blade-route'], true)) {
                continue;
            }
            $matches = ($action !== '' && (string) ($relation['route_action'] ?? '') === $action)
                || ($name !== '' && (string) ($relation['route_name'] ?? '') === $name)
                || ($uri !== '' && (string) ($relation['route_uri'] ?? '') === $uri)
                || ($action !== '' && str_contains((string) ($relation['to'] ?? ''), $action));
            if (! $matches) {
                continue;
            }
            $sources[] = [
                'path' => (string) ($relation['from'] ?? ''),
                'line' => isset($relation['source_line']) ? (int) $relation['source_line'] : null,
            ];
        }

        return array_values(array_unique($sources, SORT_REGULAR));
    }

    /** @return array<string,mixed> */
    private function databaseErd(array $sections, array $models, array $index): array
    {
        $nodes = [];
        foreach ((array) ($sections['database']['tables'] ?? []) as $table) {
            $name = (string) ($table['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $nodes[$name] = [
                'table' => $name,
                'columns' => (array) ($table['columns'] ?? []),
                'foreign_keys' => (array) ($table['foreign_keys'] ?? []),
                'models' => [],
            ];
        }

        $modelByClass = [];
        foreach ($models as $model) {
            $class = ltrim((string) ($model['class'] ?? ''), '\\');
            $table = (string) ($model['table_effective'] ?? $model['table'] ?? '');
            if ($class === '' || $table === '') {
                continue;
            }
            $modelByClass[$class] = $model;
            $nodes[$table] ??= ['table' => $table, 'columns' => [], 'foreign_keys' => [], 'models' => []];
            $nodes[$table]['models'][] = $class;
        }

        $edges = [];
        foreach ($nodes as $tableName => $node) {
            foreach ((array) ($node['foreign_keys'] ?? []) as $foreign) {
                $targetTable = (string) ($foreign['table'] ?? '');
                if ($targetTable === '') {
                    continue;
                }
                $edges[] = [
                    'type' => 'foreign-key',
                    'from_table' => $tableName,
                    'from_column' => (string) ($foreign['column'] ?? ''),
                    'to_table' => $targetTable,
                    'to_column' => (string) ($foreign['references'] ?? 'id'),
                    'label' => ($foreign['column'] ?? '').' → '.$targetTable.'.'.($foreign['references'] ?? 'id'),
                ];
            }
        }

        foreach ($models as $model) {
            $fromClass = ltrim((string) ($model['class'] ?? ''), '\\');
            $fromTable = (string) ($model['table_effective'] ?? $model['table'] ?? '');
            if ($fromTable === '') {
                continue;
            }
            foreach ((array) ($model['relationships'] ?? []) as $relationship) {
                $targetRaw = ltrim((string) ($relationship['target'] ?? ''), '\\');
                $target = $this->resolveClass($targetRaw, null, $index);
                $toTable = $target !== null ? (string) ($modelByClass[$target]['table_effective'] ?? $modelByClass[$target]['table'] ?? '') : '';
                if ($toTable === '') {
                    continue;
                }
                $edges[] = [
                    'type' => 'eloquent',
                    'from_table' => $fromTable,
                    'from_column' => null,
                    'to_table' => $toTable,
                    'to_column' => null,
                    'label' => (string) ($relationship['method'] ?? 'relationship').'(): '.($relationship['type'] ?? 'relation'),
                    'from_model' => $fromClass,
                    'to_model' => $target,
                ];
            }
        }

        ksort($nodes);
        return [
            'nodes' => array_values($nodes),
            'edges' => array_values(array_unique($edges, SORT_REGULAR)),
            'table_count' => count($nodes),
            'edge_count' => count(array_unique($edges, SORT_REGULAR)),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function frontendBackendMap(array $sections, array $index): array
    {
        $items = [];
        foreach ((array) ($sections['relationships']['items'] ?? []) as $relation) {
            $type = (string) ($relation['type'] ?? '');
            if (! in_array($type, ['frontend-http', 'frontend-http-unresolved', 'frontend-route', 'blade-route'], true)) {
                continue;
            }

            $action = (string) ($relation['route_action'] ?? '');
            $controller = null;
            $method = null;
            if ($action !== '') {
                [$rawClass, $method] = $this->actionParts($action);
                $controller = $this->resolveRouteController($rawClass, $index);
            } elseif ($type === 'blade-route' && str_contains((string) ($relation['to'] ?? ''), ' → ')) {
                $action = trim((string) substr((string) $relation['to'], (int) strrpos((string) $relation['to'], ' → ') + 5));
                [$rawClass, $method] = $this->actionParts($action);
                $controller = $this->resolveRouteController($rawClass, $index);
            }

            $items[] = [
                'frontend' => (string) ($relation['from'] ?? ''),
                'source_line' => $relation['source_line'] ?? null,
                'type' => $type,
                'http_method' => $relation['http_method'] ?? null,
                'route_uri' => $relation['route_uri'] ?? null,
                'route_name' => $relation['route_name'] ?? null,
                'controller' => $controller,
                'controller_method' => $method,
                'resolved' => $controller !== null,
                'raw' => (string) ($relation['to'] ?? ''),
            ];
        }

        return array_values(array_unique($items, SORT_REGULAR));
    }

}
