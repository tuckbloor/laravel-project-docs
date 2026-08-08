<?php

namespace DevDocs\LaravelProjectDocs\Support;

/**
 * Passive source-code review only.
 *
 * This class never includes host application files, instantiates project
 * classes, invokes project methods, opens database connections, makes HTTP
 * requests, dispatches jobs/events, sends mail, runs migrations, or runs tests.
 */
class StaticQualityAnalyzer
{
    /**
     * @param array<string,array<string,mixed>> $sections
     * @param array<string,array<string,mixed>> $usedBy
     * @param array<int,array<string,mixed>> $calls
     * @param array<int,array<string,mixed>> $models
     * @return array<string,mixed>
     */
    public function analyze(array $sections, array $usedBy, array $calls, array $models): array
    {
        $findings = [];
        $scope = QualityScope::fromSections($sections);
        $allPhpFiles = (array) ($sections['php']['items'] ?? []);
        $allRoutes = (array) ($sections['routes']['items'] ?? []);
        $phpFiles = array_values(array_filter($allPhpFiles, fn (array $file): bool => $scope->includes((string) ($file['path'] ?? ''))));
        $routes = array_values(array_filter($allRoutes, fn (array $route): bool => $scope->includes((string) ($route['source_path'] ?? ''))));
        $tables = (array) ($sections['database']['tables'] ?? []);
        $classIndex = $this->classIndex($allPhpFiles);
        $calledMethods = $this->calledMethods($calls, $allRoutes, $classIndex, $allPhpFiles);
        $methodBodies = [];
        $globalFunctions = [];
        $allPhpSource = '';
        foreach ($allPhpFiles as $file) {
            if (is_string($file['source'] ?? null)) {
                $allPhpSource .= "\n".(string) $file['source'];
            }
        }

        foreach ((array) ($sections['php']['errors'] ?? []) as $error) {
            if (! $scope->includes((string) ($error['path'] ?? ''))) {
                continue;
            }
            $findings[] = $this->finding('high', 'high', 'parser', 'PHP parser error',
                'The PHP parser could not structurally analyse this file. The raw source is still documented.',
                (string) ($error['path'] ?? ''), null, null, null, ['detail' => (string) ($error['message'] ?? '')]);
        }
        foreach (['routes' => 'Route parser error', 'database' => 'Migration parser error'] as $sectionKey => $title) {
            foreach ((array) ($sections[$sectionKey]['errors'] ?? []) as $error) {
                if (! $scope->includes((string) ($error['path'] ?? ''))) {
                    continue;
                }
                $findings[] = $this->finding('high', 'high', 'parser', $title,
                    'Static parsing failed for this source file. Review the reported parser detail.',
                    (string) ($error['path'] ?? ''), null, null, null, ['detail' => (string) ($error['message'] ?? '')]);
            }
        }

        foreach ($phpFiles as $file) {
            $path = (string) ($file['path'] ?? '');
            $source = is_string($file['source'] ?? null) ? (string) $file['source'] : '';
            if ($source === '') {
                continue;
            }
            try {
                token_get_all($source, TOKEN_PARSE);
            } catch (\ParseError $parseError) {
                $errorLine = method_exists($parseError, 'getLine') ? (int) $parseError->getLine() : null;
                $findings[] = $this->finding('critical', 'high', 'syntax', 'PHP syntax error',
                    'The current PHP runtime reports a syntax error: '.$parseError->getMessage(),
                    $path, $errorLine);
            }
            foreach ($this->globalFunctions($source, (array) ($file['classes'] ?? [])) as $function) {
                $function['path'] = $path;
                $globalFunctions[] = $function;
            }

            $this->sourceLevelFindings($findings, $path, $source, (array) ($file['uses'] ?? []));

            foreach ((array) ($file['classes'] ?? []) as $class) {
                $fqcn = ltrim((string) ($class['fqcn'] ?? $class['name'] ?? ''), '\\');
                $category = (string) ($class['category'] ?? 'class');
                $classSource = $this->slice($source, (int) ($class['start_line'] ?? 1), (int) ($class['end_line'] ?? 1));
                $metrics = (array) ($class['metrics'] ?? []);

                if ((int) ($metrics['lines'] ?? 0) > 700) {
                    $findings[] = $this->finding('medium', 'high', 'maintainability', 'Large class',
                        'This class is unusually large and may be difficult to maintain.', $path, (int) ($class['start_line'] ?? 1), $fqcn, null,
                        ['lines' => (int) ($metrics['lines'] ?? 0)]);
                }
                if ((int) ($metrics['methods'] ?? 0) > 25) {
                    $findings[] = $this->finding('medium', 'high', 'maintainability', 'Many methods in one class',
                        'This class has a high number of methods and may have too many responsibilities.', $path, (int) ($class['start_line'] ?? 1), $fqcn, null,
                        ['methods' => (int) ($metrics['methods'] ?? 0)]);
                }
                if ((int) ($metrics['dependencies'] ?? 0) > 10) {
                    $findings[] = $this->finding('medium', 'high', 'architecture', 'Many class dependencies',
                        'A large dependency count can indicate a class with too many responsibilities.', $path, (int) ($class['start_line'] ?? 1), $fqcn, null,
                        ['dependencies' => (int) ($metrics['dependencies'] ?? 0)]);
                }

                $this->unusedPropertyFindings(
                    $findings,
                    $path,
                    $fqcn,
                    $classSource,
                    (array) ($class['properties'] ?? []),
                    (int) ($class['start_line'] ?? 1),
                    $class,
                    $classIndex,
                );

                foreach ((array) ($class['methods'] ?? []) as $method) {
                    $methodName = (string) ($method['name'] ?? '');
                    $start = (int) ($method['start_line'] ?? 1);
                    $end = (int) ($method['end_line'] ?? $start);
                    $methodSource = $this->slice($source, $start, $end);
                    $this->methodFindings($findings, $path, $fqcn, $category, $method, $methodSource);

                    $normal = $this->normaliseMethodBody($methodSource);
                    if (strlen($normal) >= 120) {
                        $hash = sha1($normal);
                        $methodBodies[$hash][] = ['class' => $fqcn, 'method' => $methodName, 'path' => $path, 'line' => $start];
                    }

                    if ($this->shouldCheckUnusedMethod(
                            $methodName,
                            $category,
                            (string) ($method['visibility'] ?? 'public'),
                            $class,
                            $classIndex,
                        )
                        && ! isset($calledMethods[$fqcn.'::'.$methodName])) {
                        $visibility = (string) ($method['visibility'] ?? 'public');
                        $confidence = $visibility === 'private' ? 'high' : ($visibility === 'protected' ? 'medium' : 'low');
                        $severity = $visibility === 'private' ? 'medium' : 'low';
                        $findings[] = $this->finding($severity, $confidence, 'unused-code', 'Possibly unused method',
                            'No static route or method-call reference to this method was detected. Dynamic Laravel calls may still use it.',
                            $path, $start, $fqcn, $methodName, ['visibility' => $visibility]);
                    }
                }
            }
        }

        foreach ($globalFunctions as $function) {
            $name = (string) ($function['name'] ?? '');
            if ($name !== '' && preg_match_all('/\b'.preg_quote($name, '/').'\s*\(/', $allPhpSource) <= 1) {
                $findings[] = $this->finding('low', 'medium', 'unused-code', 'Possibly unused global function',
                    'No static call to this named function was detected in scanned PHP source.',
                    (string) ($function['path'] ?? ''), (int) ($function['line'] ?? 1), null, $name);
            }
        }

        foreach ($methodBodies as $group) {
            if (count($group) < 2) {
                continue;
            }
            $first = $group[0];
            $others = array_slice($group, 1);
            $findings[] = $this->finding('low', 'high', 'duplication', 'Duplicate method body candidate',
                'This method has an identical normalised body to '.implode(', ', array_map(fn (array $x) => $x['class'].'::'.$x['method'].'()', $others)).'.',
                $first['path'], $first['line'], $first['class'], $first['method']);
        }

        $this->routeDuplicateFindings($findings, $routes);
        $this->routeSafetyFindings($findings, $routes, $classIndex, $allPhpFiles, $scope);
        $this->databaseFindings($findings, $models, $tables, $scope);

        // Environment-file quality checks are opt-in. In normal safe mode the
        // analyser intentionally treats .env/.env.example as outside the quality
        // review, so a deliberately excluded environment file never creates a
        // warning or lowers the review score.
        $envFile = (array) ($sections['project']['env_file'] ?? []);
        $envRequested = (bool) ($envFile['requested'] ?? false);
        if ($envRequested) {
            if (! (bool) ($envFile['exists'] ?? false)) {
                $findings[] = $this->finding('medium', 'high', 'configuration', '.env requested but not found', 'The --include-env option was supplied, but no .env file was found at the project root.', '.env');
            }

            $envExample = (array) ($sections['project']['environment_example'] ?? []);
            if (! (bool) ($envExample['exists'] ?? false)) {
                $findings[] = $this->finding('low', 'high', 'configuration', '.env.example not found', 'No .env.example file was found. A redacted environment template makes setup and handover easier.', '.env.example');
            } else {
                foreach ((array) ($envExample['missing_required_keys'] ?? []) as $key) {
                    $findings[] = $this->finding('low', 'high', 'configuration', 'Environment key missing from .env.example', 'Required environment key '.$key.' is referenced by configuration but is not documented in .env.example.', '.env.example', null, null, null, ['key' => $key]);
                }
            }
        }
        $cycles = $this->dependencyCycles($phpFiles, $classIndex);
        foreach ($cycles as $cycle) {
            $first = $classIndex[$cycle[0]] ?? null;
            $findings[] = $this->finding('medium', 'medium', 'architecture', 'Circular dependency candidate',
                implode(' → ', $cycle).' → '.$cycle[0], (string) ($first['path'] ?? ''), (int) ($first['line'] ?? 1), $cycle[0]);
        }

        foreach ((array) ($sections['frontend']['items'] ?? []) as $file) {
            $source = is_string($file['source'] ?? null) ? (string) $file['source'] : '';
            $path = (string) ($file['path'] ?? '');
            if (! $scope->includes($path) || $source === '') {
                continue;
            }
            foreach ($this->lineMatches($source, '/\bconsole\.(?:log|debug|trace)\s*\(/i') as $line) {
                $findings[] = $this->finding('low', 'high', 'debug', 'Frontend debug statement', 'A console debugging statement remains in application source.', $path, $line);
            }
            foreach ($this->lineMatches($source, '/\bdebugger\s*;/i') as $line) {
                $findings[] = $this->finding('medium', 'high', 'debug', 'JavaScript debugger statement', 'A debugger statement remains in frontend source.', $path, $line);
            }
            if (preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=/', $source, $varMatches, PREG_OFFSET_CAPTURE)) {
                foreach ($varMatches[1] as $match) {
                    $name = (string) $match[0];
                    if (preg_match_all('/(?<![A-Za-z0-9_$])'.preg_quote($name, '/').'(?![A-Za-z0-9_$])/', $source) <= 1) {
                        $line = substr_count(substr($source, 0, (int) $match[1]), "\n") + 1;
                        $findings[] = $this->finding('low', 'low', 'unused-code', 'Possibly unused frontend variable', $name.' is declared but no second reference was detected.', $path, $line, null, null, ['variable' => $name]);
                    }
                }
            }
            foreach ($this->frontendImports($source) as $import) {
                $name = $import['local'];
                if ($name !== '' && preg_match_all('/(?<![A-Za-z0-9_$])'.preg_quote($name, '/').'(?![A-Za-z0-9_$])/', $source) <= 1) {
                    $findings[] = $this->finding('low', 'low', 'unused-code', 'Possibly unused frontend import', 'Imported name '.$name.' is not referenced elsewhere in this file.', $path, $import['line'], null, null, ['import' => $name]);
                }
            }
        }

        $findings = array_values(array_unique($findings, SORT_REGULAR));
        usort($findings, function (array $a, array $b): int {
            $severity = $this->severityRank((string) ($b['severity'] ?? 'low')) <=> $this->severityRank((string) ($a['severity'] ?? 'low'));
            return $severity !== 0 ? $severity : [(string) ($a['path'] ?? ''), (int) ($a['line'] ?? 0)] <=> [(string) ($b['path'] ?? ''), (int) ($b['line'] ?? 0)];
        });

        $summary = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $categories = [];
        foreach ($findings as $finding) {
            $severity = (string) ($finding['severity'] ?? 'low');
            $summary[$severity] = ($summary[$severity] ?? 0) + 1;
            $category = (string) ($finding['category'] ?? 'other');
            $categories[$category] = ($categories[$category] ?? 0) + 1;
        }
        ksort($categories);

        $penalty = min(100, ($summary['critical'] * 20) + ($summary['high'] * 7) + ($summary['medium'] * 2) + min(15, $summary['low']));

        return [
            'mode' => 'static-read-only',
            'score' => max(0, 100 - $penalty),
            'summary' => $summary,
            'categories' => $categories,
            'finding_count' => count($findings),
            'findings' => $findings,
            'cycles' => $cycles,
            'scope' => [
                'mode' => 'application-owned',
                'reviewed_php_files' => count($phpFiles),
                'excluded_php_files' => max(0, count($allPhpFiles) - count($phpFiles)),
                'reviewed_routes' => count($routes),
                'excluded_routes' => max(0, count($allRoutes) - count($routes)),
                'exclude_paths' => $scope->excludedPaths(),
                'inheritance_aware' => true,
            ],
            'notice' => 'Heuristic static analysis of application-owned source only. Laravel/framework/starter scaffolding is excluded from quality findings by default. Framework inheritance is respected: parent-consumed hooks, relationship methods and protected framework configuration are not reported as unused. No application business methods, database queries, HTTP calls, jobs, events, mail, migrations or tests are executed by the analyser.',
        ];
    }

    /** @param array<int,array<string,mixed>> $findings */
    private function sourceLevelFindings(array &$findings, string $path, string $source, array $uses): void
    {
        foreach ($this->lineMatches($source, '/\b(?:TODO|FIXME|HACK|XXX)\b/i') as $line) {
            $findings[] = $this->finding('low', 'high', 'maintenance-note', 'Outstanding code marker', 'TODO/FIXME/HACK/XXX marker found in source.', $path, $line);
        }
        foreach ($this->lineMatches($source, '/\b(?:dd|dump|var_dump|print_r|ray)\s*\(/i') as $line) {
            $findings[] = $this->finding('medium', 'high', 'debug', 'Debug statement', 'Development/debug output appears to remain in application source.', $path, $line);
        }
        foreach ($this->lineMatches($source, '/\b(?:die|exit)\s*(?:\(|;)/i') as $line) {
            $findings[] = $this->finding('medium', 'medium', 'debug', 'Direct process termination', 'die/exit can abruptly terminate a Laravel request and is worth reviewing.', $path, $line);
        }
        if (! str_starts_with(str_replace('\\', '/', $path), 'config/')) {
            foreach ($this->lineMatches($source, '/\benv\s*\(/i') as $line) {
                $findings[] = $this->finding('medium', 'high', 'configuration', 'env() used outside config', 'Laravel applications are usually safer when application code reads configuration via config() rather than calling env() directly.', $path, $line);
            }
        }
        foreach ($this->lineMatches($source, '/\b(?:DB::(?:raw|select|statement|unprepared)|whereRaw|orWhereRaw|orderByRaw|groupByRaw|havingRaw|selectRaw)\s*\(/i') as $line) {
            $findings[] = $this->finding('low', 'high', 'database', 'Raw SQL usage', 'Raw SQL/query-builder expression detected. Review parameter binding and maintainability.', $path, $line);
        }

        $secretPattern = '/\b(?:password|passwd|api[_-]?key|secret|token|client[_-]?secret|private[_-]?key)\b\s*(?:=>|=|:)\s*[\'\"](?!\s*(?:env\(|config\())[^\'\"]{8,}[\'\"]/i';
        foreach ($this->lineMatches($source, $secretPattern) as $line) {
            $findings[] = $this->finding('high', 'medium', 'security', 'Possible hard-coded credential', 'A credential-like literal may be hard-coded here. The value is intentionally omitted from this report.', $path, $line);
        }
        foreach ($this->lineMatches($source, '/\b(?:eval|exec|shell_exec|system|passthru|proc_open|popen)\s*\(/i') as $line) {
            $findings[] = $this->finding('high', 'high', 'security', 'Dynamic code / process execution API', 'A PHP execution/process function is used here. Review input validation and whether this capability is necessary.', $path, $line);
        }
        foreach ($this->lineMatches($source, '/\bunserialize\s*\(/i') as $line) {
            $findings[] = $this->finding('medium', 'high', 'security', 'unserialize() usage', 'Review whether untrusted data can reach unserialize().', $path, $line);
        }
        foreach ($this->lineMatches($source, '/\$_(?:GET|POST|REQUEST|COOKIE)\b/') as $line) {
            $findings[] = $this->finding('low', 'high', 'framework', 'Direct PHP superglobal access', 'Laravel request helpers / Request objects usually provide clearer validation and input handling than direct superglobal access.', $path, $line);
        }

        foreach ($uses as $use) {
            if (($use['type'] ?? 'class') !== 'class') {
                continue;
            }
            $name = (string) ($use['name'] ?? '');
            $alias = (string) (($use['alias'] ?? null) ?: basename(str_replace('\\', '/', $name)));
            if ($alias === '') {
                continue;
            }
            if (preg_match_all('/\b'.preg_quote($alias, '/').'\b/', $source) <= 1) {
                $line = $this->firstLineMatch($source, '/\buse\s+'.preg_quote($name, '/').'(?:\s+as\s+'.preg_quote($alias, '/').')?\s*;/i');
                $findings[] = $this->finding('low', 'medium', 'unused-code', 'Possibly unused import', 'The imported class name does not appear elsewhere in this file.', $path, $line, null, null, ['import' => $name]);
            }
        }
    }

    /** @param array<int,array<string,mixed>> $findings */
    private function methodFindings(array &$findings, string $path, string $class, string $category, array $method, string $source): void
    {
        $name = (string) ($method['name'] ?? '');
        $line = (int) ($method['start_line'] ?? 1);
        $complexity = (int) ($method['complexity'] ?? 1);
        $lines = (int) ($method['lines'] ?? 1);

        if ($lines > 120) {
            $findings[] = $this->finding('medium', 'high', 'maintainability', 'Long method', 'This method is '.$lines.' lines long.', $path, $line, $class, $name, ['lines' => $lines]);
        }
        if ($complexity > 15) {
            $findings[] = $this->finding('medium', 'high', 'complexity', 'High cyclomatic complexity', 'Static complexity is '.$complexity.'. Consider reducing branching/nesting where practical.', $path, $line, $class, $name, ['complexity' => $complexity]);
        }

        $vars = $this->variableUsage($source);
        $skipFrameworkSignatureParameterCheck = $this->frameworkMayOwnMethodSignature($category, $name);
        foreach ((array) ($method['parameters'] ?? []) as $param) {
            $var = '$'.(string) ($param['name'] ?? '');
            if (! $skipFrameworkSignatureParameterCheck
                && $var !== '$'
                && (($vars['counts'][$var] ?? 0) <= 1)) {
                $findings[] = $this->finding('low', 'medium', 'unused-code', 'Possibly unused parameter', $var.' is declared but no use was detected in the method body.', $path, $line, $class, $name, ['variable' => $var]);
            }
        }
        $declared = [];
        foreach ((array) ($method['parameters'] ?? []) as $param) {
            $declared['$'.(string) ($param['name'] ?? '')] = true;
        }

        // A nested closure / arrow function introduces its own parameter scope.
        // Those variables are defined by the function signature and must never
        // be reported as undefined in the containing method. Example:
        // ->through(fn ($video) => ['id' => $video->id])
        foreach ($this->nestedFunctionParameters($source) as $var) {
            $declared[$var] = true;
        }

        foreach (array_keys($vars['writes']) as $var) { $declared[$var] = true; }
        if (preg_match_all('/\bcatch\s*\([^)]*(\$[A-Za-z_][A-Za-z0-9_]*)\s*\)/', $source, $catchVars)) {
            foreach ($catchVars[1] as $var) { $declared[$var] = true; }
        }
        if (preg_match_all('/\bglobal\s+([^;]+);/', $source, $globalVars)) {
            foreach ($globalVars[1] as $declaration) {
                if (preg_match_all('/\$[A-Za-z_][A-Za-z0-9_]*/', (string) $declaration, $names)) {
                    foreach ($names[0] as $var) { $declared[$var] = true; }
                }
            }
        }
        if (preg_match_all('/\bstatic\s+([^;]+);/', $source, $staticVars)) {
            foreach ($staticVars[1] as $declaration) {
                if (preg_match_all('/\$[A-Za-z_][A-Za-z0-9_]*/', (string) $declaration, $names)) {
                    foreach ($names[0] as $var) { $declared[$var] = true; }
                }
            }
        }
        foreach ($vars['counts'] as $var => $count) {
            if ($this->ignoreVariable($var) || isset($declared[$var])) { continue; }
            $varLine = $this->firstLineMatch($source, '/'.preg_quote($var, '/').'\b/') ?? 1;
            $findings[] = $this->finding('low', 'medium', 'variables', 'Possibly undefined variable',
                $var.' is read but is not a method parameter, closure/arrow-function parameter, foreach/catch/global/static variable, or obvious assigned local variable.',
                $path, $line + max(0, $varLine - 1), $class, $name, ['variable' => $var]);
        }

        foreach ($vars['writes'] as $var => $writes) {
            if ($this->ignoreVariable($var)) {
                continue;
            }
            if (($vars['counts'][$var] ?? 0) <= $writes) {
                $varLine = $this->firstLineMatch($source, '/'.preg_quote($var, '/').'\s*(?:=|\+=|-=|\*=|\/=|\.=|\?\?=)/') ?? $line;
                $findings[] = $this->finding('low', 'medium', 'unused-code', 'Possibly unused local variable', $var.' is assigned but no later read was detected.', $path, $line + max(0, $varLine - 1), $class, $name, ['variable' => $var]);
            }
        }

        if (preg_match('/\b(?:foreach|for|while)\s*\(/i', $source)
            && preg_match('/(?:->|::)(?:get|first|find|findOrFail|count|exists|pluck|sum|avg|value|save|update|delete)\s*\(/i', $source)) {
            $findings[] = $this->finding('medium', 'medium', 'database', 'Possible query inside loop / N+1 pattern', 'A query-like call and loop occur in the same method. Review whether eager loading, batching or prefetching would reduce repeated queries.', $path, $line, $class, $name);
        }
        if (preg_match('/(?:create|update|fill|forceFill)\s*\(\s*\$request\s*->\s*(?:all|input)\s*\(/is', $source)) {
            $findings[] = $this->finding('high', 'high', 'security', 'Request data passed directly to mass assignment', 'Review validation and fillable/guarded rules before passing the complete request payload into create/update/fill.', $path, $line, $class, $name);
        }
        if (preg_match('/catch\s*\([^)]*\)\s*\{\s*\}/is', $source)) {
            $findings[] = $this->finding('medium', 'high', 'error-handling', 'Empty catch block', 'An exception appears to be swallowed without logging, handling or rethrowing.', $path, $line, $class, $name);
        }
        if (preg_match('/catch\s*\(\s*\\?(?:Exception|Throwable)\b/i', $source)) {
            $findings[] = $this->finding('low', 'medium', 'error-handling', 'Broad exception catch', 'A broad Exception/Throwable catch was detected. Review whether narrower exception types would preserve intent.', $path, $line, $class, $name);
        }
        if (preg_match('/catch\s*\([^)]*\)\s*\{\s*return\s+null\s*;\s*\}/is', $source)) {
            $findings[] = $this->finding('low', 'medium', 'error-handling', 'Exception converted directly to null', 'A caught exception is reduced to null with no visible logging or context.', $path, $line, $class, $name);
        }
        if (preg_match('/\b(?:echo|print)\s+/i', $source) && ! str_contains(strtolower($path), 'command')) {
            $findings[] = $this->finding('low', 'medium', 'output', 'Direct output in application method', 'Direct echo/print output was detected. Laravel responses/logging are usually preferable in web application code.', $path, $line, $class, $name);
        }
    }

    /** @param array<int,array<string,mixed>> $findings */
    private function unusedPropertyFindings(
        array &$findings,
        string $path,
        string $class,
        string $source,
        array $properties,
        int $classLine,
        array $classMeta,
        array $classIndex,
    ): void {
        $frameworkManaged = $this->isFrameworkManagedClass($classMeta, $classIndex);

        foreach ($properties as $property) {
            $name = (string) ($property['name'] ?? '');
            $visibility = (string) ($property['visibility'] ?? 'public');
            if ($name === '' || $visibility === 'public') {
                continue;
            }

            /*
             * Protected properties on framework-derived classes are frequently
             * configuration consumed by the parent/framework through inheritance
             * rather than explicit child-class property access. Examples include
             * Eloquent $fillable/$casts/$table, FormRequest redirect settings,
             * Console Command $signature/$description and Mailable/Notification
             * state. Static "unused" warnings for those are misleading.
             *
             * Private properties remain safe to review because a parent class
             * cannot directly consume a child's private property.
             */
            if (($frameworkManaged || $this->hasScannedSubclass($class, $classIndex))
                && $visibility === 'protected') {
                continue;
            }

            if ($this->isFrameworkConfigurationProperty((string) ($classMeta['category'] ?? ''), $name)) {
                continue;
            }

            $matches = preg_match_all('/(?:->|::\$)'.preg_quote($name, '/').'\b/', $source);
            if ($matches === 0) {
                $findings[] = $this->finding('low', $visibility === 'private' ? 'medium' : 'low', 'unused-code', 'Possibly unused property',
                    '$'.$name.' is declared but no property access was detected in this class.', $path, $classLine, $class, null,
                    ['property' => '$'.$name, 'visibility' => $visibility]);
            }
        }
    }

    /** @return array<int,array{name:string,line:int}> */
    private function globalFunctions(string $source, array $classes): array
    {
        $ranges = array_map(fn (array $class) => [(int) ($class['start_line'] ?? 0), (int) ($class['end_line'] ?? 0)], $classes);
        $items = [];
        if (preg_match_all('/\bfunction\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $line = substr_count(substr($source, 0, (int) ($match[0][1] ?? 0)), "\n") + 1;
                $insideClass = false;
                foreach ($ranges as [$start, $end]) {
                    if ($start > 0 && $line >= $start && $line <= $end) {
                        $insideClass = true;
                        break;
                    }
                }
                if (! $insideClass) {
                    $items[] = ['name' => (string) ($match[1][0] ?? ''), 'line' => $line];
                }
            }
        }
        return $items;
    }

    /** @return array<int,array{local:string,line:int}> */
    private function frontendImports(string $source): array
    {
        $items = [];
        if (preg_match_all('/\bimport\s+([A-Za-z_$][A-Za-z0-9_$]*)\s+from\s+[\'\"][^\'\"]+[\'\"]/', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $items[] = ['local' => (string) $match[1][0], 'line' => substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1];
            }
        }
        if (preg_match_all('/\bimport\s*\{([^}]+)\}\s*from\s*[\'\"][^\'\"]+[\'\"]/', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $line = substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1;
                foreach (explode(',', (string) $match[1][0]) as $part) {
                    $bits = preg_split('/\s+as\s+/i', trim($part)) ?: [];
                    $local = trim((string) end($bits));
                    if ($local !== '') {
                        $items[] = ['local' => $local, 'line' => $line];
                    }
                }
            }
        }
        return $items;
    }

    /** @param array<int,array<string,mixed>> $findings */
    private function routeDuplicateFindings(array &$findings, array $routes): void
    {
        $seen = [];
        $names = [];
        foreach ($routes as $route) {
            foreach ((array) ($route['methods'] ?? []) as $method) {
                $key = strtoupper((string) $method).' '.trim((string) ($route['uri'] ?? ''), '/');
                if (isset($seen[$key])) {
                    $findings[] = $this->finding('medium', 'high', 'routing', 'Duplicate route signature', $key.' appears more than once in scanned route source.', (string) ($route['source_path'] ?? ''), isset($route['source_line']) ? (int) $route['source_line'] : null);
                }
                $seen[$key] = true;
            }
            $name = (string) ($route['name'] ?? '');
            if ($name !== '') {
                if (isset($names[$name])) {
                    $findings[] = $this->finding('medium', 'high', 'routing', 'Duplicate route name', 'Route name '.$name.' appears more than once.', (string) ($route['source_path'] ?? ''), isset($route['source_line']) ? (int) $route['source_line'] : null);
                }
                $names[$name] = true;
            }
        }
    }

    /** @param array<int,array<string,mixed>> $findings */
    private function routeSafetyFindings(array &$findings, array $routes, array $classIndex, array $phpFiles, QualityScope $scope): void
    {
        foreach ($routes as $route) {
            $methods = array_map('strtoupper', (array) ($route['methods'] ?? []));
            $action = (string) ($route['action'] ?? '');
            if ($action === '' || $action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }
            [$rawClass, $methodName] = array_pad(explode('@', $action, 2), 2, '');
            $fqcn = $this->resolveClassName($rawClass, $classIndex);
            if ($fqcn === null) {
                continue;
            }
            $entry = $classIndex[$fqcn] ?? null;
            if (! is_array($entry) || ! $scope->includes((string) ($entry['path'] ?? ''))) {
                continue;
            }
            $method = $this->findMethod((array) ($entry['class']['methods'] ?? []), $methodName);
            if ($method === null) {
                continue;
            }
            $source = $this->sourceForPath($phpFiles, (string) ($entry['path'] ?? ''));
            $methodSource = $this->slice($source, (int) ($method['start_line'] ?? 1), (int) ($method['end_line'] ?? 1));

            if (array_intersect($methods, ['POST', 'PUT', 'PATCH'])) {
                $hasFormRequest = false;
                foreach ((array) ($method['parameters'] ?? []) as $param) {
                    $type = (string) ($param['type'] ?? '');
                    if ($type !== '' && str_ends_with($type, 'Request') && ! in_array($type, ['Request', 'Illuminate\\Http\\Request'], true)) {
                        $hasFormRequest = true;
                    }
                }
                $hasValidationCall = preg_match('/(?:->validate\s*\(|->validated\s*\(|Validator::make\s*\(|validateWithBag\s*\()/i', $methodSource) === 1;
                $usesAuthenticationContext = $this->usesAuthenticationContext($methodSource);

                if (! $hasFormRequest && ! $hasValidationCall && ! $usesAuthenticationContext) {
                    $findings[] = $this->finding('medium', 'medium', 'validation', 'No obvious request validation detected',
                        implode('|', $methods).' '.($route['uri'] ?? '').' maps to a write action with no obvious Form Request or validation call.',
                        (string) ($entry['path'] ?? ''), (int) ($method['start_line'] ?? 1), $fqcn, $methodName);
                }
            }

        }
    }

    private function usesAuthenticationContext(string $methodSource): bool
    {
        // Auth:: also covers \Auth:: and fully-qualified Illuminate\Support\Facades\Auth::.
        if (str_contains($methodSource, 'Auth::')) {
            return true;
        }

        // Treat Laravel's auth() helper as the same authenticated-context signal.
        return preg_match('/(?<![A-Za-z0-9_])auth\s*\(/i', $methodSource) === 1;
    }

    /** @param array<int,array<string,mixed>> $findings */
    private function databaseFindings(array &$findings, array $models, array $tables, QualityScope $scope): void
    {
        $known = [];
        foreach ($tables as $table) {
            $known[(string) ($table['name'] ?? '')] = true;
        }
        foreach ($models as $model) {
            if (! $scope->includes((string) ($model['path'] ?? ''))) {
                continue;
            }
            $table = (string) ($model['table_effective'] ?? $model['table'] ?? '');
            if ($table !== '' && ! isset($known[$table])) {
                $findings[] = $this->finding('low', 'medium', 'database', 'Model table not found in scanned migrations',
                    'The model expects table '.$table.', but no matching table was found in the scanned migration history. It may be legacy/external/runtime-managed.',
                    (string) ($model['path'] ?? ''), 1, (string) ($model['class'] ?? ''));
            }
        }
    }

    /** @return array<string,array{class:array<string,mixed>,path:string,line:int}> */
    private function classIndex(array $phpFiles): array
    {
        $index = [];
        foreach ($phpFiles as $file) {
            foreach ((array) ($file['classes'] ?? []) as $class) {
                $fqcn = ltrim((string) ($class['fqcn'] ?? ''), '\\');
                if ($fqcn !== '') {
                    $index[$fqcn] = ['class' => $class, 'path' => (string) ($file['path'] ?? ''), 'line' => (int) ($class['start_line'] ?? 1)];
                }
            }
        }
        return $index;
    }

    /** @return array<string,true> */
    private function calledMethods(array $calls, array $routes, array $classIndex, array $phpFiles): array
    {
        $used = [];
        foreach ($routes as $route) {
            $action = (string) ($route['action'] ?? '');
            if (! str_contains($action, '@')) {
                continue;
            }
            [$raw, $method] = explode('@', $action, 2);
            $class = $this->resolveClassName($raw, $classIndex);
            if ($class !== null) {
                $used[$class.'::'.$method] = true;
            }
        }
        foreach ($calls as $call) {
            $target = ltrim((string) ($call['target_class'] ?? ''), '\\');
            $method = (string) ($call['target_method'] ?? '');
            if ($target !== '' && $method !== '') {
                $used[$target.'::'.$method] = true;
            }
        }
        foreach ($phpFiles as $file) {
            $source = is_string($file['source'] ?? null) ? (string) $file['source'] : '';
            foreach ((array) ($file['classes'] ?? []) as $class) {
                $fqcn = ltrim((string) ($class['fqcn'] ?? ''), '\\');
                if ($fqcn === '') {
                    continue;
                }
                if (preg_match_all('/(?:\$this->|self::|static::)([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches)) {
                    foreach ($matches[1] as $method) {
                        $used[$fqcn.'::'.$method] = true;
                    }
                }
            }
        }
        return $used;
    }

    private function shouldCheckUnusedMethod(
        string $method,
        string $category,
        string $visibility,
        array $classMeta,
        array $classIndex,
    ): bool {
        if ($method === '' || str_starts_with($method, '__')) {
            return false;
        }

        $framework = [
            'boot', 'booted', 'register', 'handle', 'rules', 'authorize', 'messages', 'attributes',
            'prepareForValidation', 'passedValidation', 'failedValidation', 'failedAuthorization',
            'toMail', 'toArray', 'toDatabase', 'via', 'databaseType', 'broadcastType',
            'failed', 'render', 'report', 'schedule', 'commands', 'casts', 'envelope', 'content',
            'attachments', 'build', 'middleware', 'retryUntil', 'backoff', 'tags', 'viaConnections',
            'viaQueues', 'shouldSend', 'getRouteKeyName', 'getAuthIdentifierName', 'getAuthIdentifier',
            'getAuthPassword', 'getRememberToken', 'setRememberToken', 'getRememberTokenName',
            'resolveRouteBinding', 'resolveChildRouteBinding', 'scopeBindings',
        ];
        if (in_array($method, $framework, true)) {
            return false;
        }

        if ($category === 'policy'
            && in_array($method, ['before', 'viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete'], true)) {
            return false;
        }

        if ($category === 'model') {
            /*
             * Public/protected Eloquent methods can be relationship methods,
             * local scopes, accessors/mutators or hooks that Laravel resolves
             * dynamically via __get/__call and therefore have no direct static
             * call site. Treat those as framework-managed, not unused.
             */
            if ($visibility !== 'private') {
                return false;
            }
            if (str_starts_with($method, 'scope') || str_starts_with($method, 'get') || str_starts_with($method, 'set')) {
                return false;
            }
        }

        // A method that overrides a scanned parent method is part of the
        // inheritance contract even when no direct child-level call exists.
        if ($this->overridesScannedParentMethod($classMeta, $method, $classIndex)) {
            return false;
        }

        // Public/protected parent methods are inherited API for scanned child
        // classes, so the lack of a direct static call is not evidence that the
        // parent declaration is unused.
        $fqcn = ltrim((string) ($classMeta['fqcn'] ?? ''), '\\');
        if ($visibility !== 'private' && $fqcn !== '' && $this->hasScannedSubclass($fqcn, $classIndex)) {
            return false;
        }

        /*
         * For classes extending Laravel/Symfony/PHP framework bases (or another
         * external parent not present in the scanned application), public and
         * protected methods may be callbacks/hooks invoked by the parent or
         * framework. Only private methods are reliable candidates for unused
         * method analysis in that situation.
         */
        if ($visibility !== 'private' && $this->isFrameworkManagedClass($classMeta, $classIndex)) {
            return false;
        }

        return true;
    }

    private function frameworkMayOwnMethodSignature(string $category, string $method): bool
    {
        $hooks = [
            'request' => [
                'rules', 'authorize', 'messages', 'attributes', 'prepareForValidation',
                'passedValidation', 'failedValidation', 'failedAuthorization',
            ],
            'job' => ['handle', 'middleware', 'retryUntil', 'backoff', 'tags', 'failed'],
            'listener' => ['handle', 'shouldQueue', 'withDelay'],
            'mail' => ['envelope', 'content', 'attachments', 'build'],
            'notification' => ['via', 'toMail', 'toArray', 'toDatabase', 'databaseType', 'broadcastType', 'shouldSend'],
            'policy' => ['before', 'viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete'],
            'command' => ['handle'],
            'middleware' => ['handle', 'terminate'],
            'provider' => ['register', 'boot'],
            'observer' => ['retrieved', 'creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting', 'deleted', 'trashed', 'forceDeleting', 'forceDeleted', 'restoring', 'restored', 'replicating'],
            'model' => ['boot', 'booted', 'casts', 'resolveRouteBinding', 'resolveChildRouteBinding', 'getRouteKeyName'],
        ];

        return in_array($method, $hooks[$category] ?? [], true);
    }

    private function isFrameworkManagedClass(array $classMeta, array $classIndex): bool
    {
        $category = (string) ($classMeta['category'] ?? '');
        if (in_array($category, [
            'model', 'request', 'controller', 'job', 'event', 'listener', 'mail',
            'notification', 'policy', 'command', 'middleware', 'provider', 'observer',
        ], true)) {
            return true;
        }

        $extends = ltrim((string) ($classMeta['extends'] ?? ''), '\\');
        if ($extends === '') {
            return false;
        }

        // Application-owned scanned parents can be checked precisely for an
        // overridden method, so merely extending one does not disable analysis.
        if ($this->resolveClassName($extends, $classIndex) !== null) {
            return false;
        }

        $lower = strtolower($extends);
        foreach ([
            'illuminate\\',
            'laravel\\',
            'symfony\\',
            'psr\\',
            'monolog\\',
            'guzzlehttp\\',
        ] as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        // Short framework base names are common when NameResolver cannot fully
        // resolve a fallback-parsed file.
        return in_array(strtolower(basename(str_replace('\\', '/', $extends))), [
            'model', 'authenticatable', 'controller', 'formrequest', 'command',
            'mailable', 'notification', 'serviceprovider', 'exceptionhandler',
            'pivot', 'morphpivot',
        ], true);
    }

    private function hasScannedSubclass(string $fqcn, array $classIndex): bool
    {
        $fqcn = ltrim($fqcn, '\\');
        if ($fqcn === '') {
            return false;
        }

        foreach ($classIndex as $childFqcn => $entry) {
            if ($childFqcn === $fqcn || ! is_array($entry)) {
                continue;
            }

            $extends = ltrim((string) ($entry['class']['extends'] ?? ''), '\\');
            if ($extends === '') {
                continue;
            }

            $resolved = $this->resolveClassName($extends, $classIndex);
            $visited = [];
            while ($resolved !== null && ! isset($visited[$resolved])) {
                if ($resolved === $fqcn) {
                    return true;
                }
                $visited[$resolved] = true;
                $parentEntry = $classIndex[$resolved] ?? null;
                if (! is_array($parentEntry)) {
                    break;
                }
                $next = ltrim((string) ($parentEntry['class']['extends'] ?? ''), '\\');
                $resolved = $next !== '' ? $this->resolveClassName($next, $classIndex) : null;
            }
        }

        return false;
    }

    private function overridesScannedParentMethod(array $classMeta, string $method, array $classIndex): bool
    {
        $extends = ltrim((string) ($classMeta['extends'] ?? ''), '\\');
        if ($extends === '') {
            return false;
        }

        $parent = $this->resolveClassName($extends, $classIndex);
        $visited = [];

        while ($parent !== null && ! isset($visited[$parent])) {
            $visited[$parent] = true;
            $entry = $classIndex[$parent] ?? null;
            if (! is_array($entry)) {
                return false;
            }

            foreach ((array) ($entry['class']['methods'] ?? []) as $parentMethod) {
                if ((string) ($parentMethod['name'] ?? '') === $method) {
                    return true;
                }
            }

            $next = ltrim((string) ($entry['class']['extends'] ?? ''), '\\');
            $parent = $next !== '' ? $this->resolveClassName($next, $classIndex) : null;
        }

        return false;
    }

    private function isFrameworkConfigurationProperty(string $category, string $name): bool
    {
        $properties = [
            'model' => [
                'fillable', 'guarded', 'hidden', 'visible', 'casts', 'appends', 'touches',
                'with', 'withCount', 'dates', 'dispatchesEvents', 'observables', 'table',
                'connection', 'primaryKey', 'keyType', 'incrementing', 'timestamps',
                'dateFormat', 'perPage', 'snakeAttributes',
            ],
            'request' => [
                'stopOnFirstFailure', 'redirect', 'redirectRoute', 'redirectAction', 'errorBag',
            ],
            'command' => [
                'signature', 'name', 'description', 'aliases', 'hidden',
            ],
            'mail' => [
                'theme', 'mailer', 'locale',
            ],
            'notification' => [
                'id', 'locale',
            ],
            'job' => [
                'connection', 'queue', 'delay', 'afterCommit', 'middleware', 'chained',
                'chainConnection', 'chainQueue', 'chainCatchCallbacks',
            ],
        ];

        return in_array($name, $properties[$category] ?? [], true);
    }

    /** @return array{counts:array<string,int>,writes:array<string,int>} */
    private function variableUsage(string $source): array
    {
        $counts = [];
        try {
            foreach (token_get_all("<?php\n".$source) as $token) {
                if (is_array($token) && $token[0] === T_VARIABLE) {
                    $counts[$token[1]] = ($counts[$token[1]] ?? 0) + 1;
                }
            }
        } catch (\Throwable) {
        }
        $writes = [];
        if (preg_match_all('/(\$[A-Za-z_][A-Za-z0-9_]*)\s*(?:=|\+=|-=|\*=|\/=|\.=|\?\?=)(?!=|>)/', $source, $matches)) {
            foreach ($matches[1] as $var) {
                $writes[$var] = ($writes[$var] ?? 0) + 1;
            }
        }
        if (preg_match_all('/\bas\s+(?:(\$[A-Za-z_][A-Za-z0-9_]*)\s*=>\s*)?(\$[A-Za-z_][A-Za-z0-9_]*)/', $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                foreach ([$match[1] ?? '', $match[2] ?? ''] as $var) {
                    if ($var !== '') { $writes[$var] = ($writes[$var] ?? 0) + 1; }
                }
            }
        }
        return ['counts' => $counts, 'writes' => $writes];
    }


    /** @return array<int,string> */
    private function nestedFunctionParameters(string $source): array
    {
        $variables = [];

        // Matches both normal/anonymous functions and arrow functions. The
        // optional function name means the surrounding method signature may be
        // seen too; de-duplication makes that harmless while nested fn/function
        // parameters are correctly treated as declarations in their own scope.
        $pattern = '/\b(?:fn|function)(?:\s+&?\s*[A-Za-z_][A-Za-z0-9_]*)?\s*\(([^)]*)\)/is';
        if (preg_match_all($pattern, $source, $matches)) {
            foreach ($matches[1] as $parameterList) {
                if (preg_match_all('/\$[A-Za-z_][A-Za-z0-9_]*/', (string) $parameterList, $params)) {
                    foreach ($params[0] as $var) {
                        $variables[$var] = true;
                    }
                }
            }
        }

        return array_keys($variables);
    }

    private function ignoreVariable(string $var): bool
    {
        return in_array($var, ['$this', '$_GET', '$_POST', '$_SERVER', '$_SESSION', '$_COOKIE', '$_FILES', '$_ENV', '$GLOBALS'], true)
            || preg_match('/^\$_+$/', $var) === 1;
    }

    /** @return array<int,array<int,string>> */
    private function dependencyCycles(array $phpFiles, array $classIndex): array
    {
        $graph = [];
        foreach ($phpFiles as $file) {
            foreach ((array) ($file['classes'] ?? []) as $class) {
                $from = ltrim((string) ($class['fqcn'] ?? ''), '\\');
                if ($from === '') {
                    continue;
                }
                $graph[$from] ??= [];
                foreach ((array) ($class['dependencies'] ?? []) as $dep) {
                    $to = $this->resolveClassName((string) ($dep['type'] ?? ''), $classIndex);
                    if ($to !== null && $to !== $from) {
                        $graph[$from][] = $to;
                    }
                }
                $graph[$from] = array_values(array_unique($graph[$from]));
            }
        }

        $cycles = [];
        foreach (array_keys($graph) as $start) {
            $this->walkCycle($start, $start, $graph, [], $cycles);
        }
        $unique = [];
        foreach ($cycles as $cycle) {
            $keyParts = $cycle;
            sort($keyParts);
            $unique[implode('|', $keyParts)] = $cycle;
        }
        return array_values($unique);
    }

    private function walkCycle(string $start, string $node, array $graph, array $path, array &$cycles): void
    {
        if (count($path) > 8) {
            return;
        }
        $path[] = $node;
        foreach ((array) ($graph[$node] ?? []) as $next) {
            if ($next === $start && count($path) > 1) {
                $cycles[] = $path;
                continue;
            }
            if (! in_array($next, $path, true)) {
                $this->walkCycle($start, $next, $graph, $path, $cycles);
            }
        }
    }

    private function resolveClassName(string $raw, array $classIndex): ?string
    {
        $raw = ltrim(trim($raw, '?\\'), '\\');
        if ($raw === '') {
            return null;
        }
        if (isset($classIndex[$raw])) {
            return $raw;
        }
        $short = basename(str_replace('\\', '/', $raw));
        $matches = [];
        foreach (array_keys($classIndex) as $fqcn) {
            if (basename(str_replace('\\', '/', $fqcn)) === $short) {
                $matches[] = $fqcn;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function findMethod(array $methods, string $name): ?array
    {
        foreach ($methods as $method) {
            if ((string) ($method['name'] ?? '') === $name) {
                return $method;
            }
        }
        return null;
    }

    private function sourceForPath(array $phpFiles, string $path): string
    {
        foreach ($phpFiles as $file) {
            if ((string) ($file['path'] ?? '') === $path) {
                return is_string($file['source'] ?? null) ? (string) $file['source'] : '';
            }
        }
        return '';
    }

    private function slice(string $source, int $start, int $end): string
    {
        if ($source === '') {
            return '';
        }
        $lines = preg_split('/\R/', $source) ?: [];
        return implode("\n", array_slice($lines, max(0, $start - 1), max(1, $end - $start + 1)));
    }

    private function normaliseMethodBody(string $source): string
    {
        $source = preg_replace('~/\*.*?\*/~s', '', $source) ?? $source;
        $source = preg_replace('~//.*$|#.*$~m', '', $source) ?? $source;
        $open = strpos($source, '{');
        $close = strrpos($source, '}');
        if ($open !== false && $close !== false && $close > $open) {
            $source = substr($source, $open + 1, $close - $open - 1);
        }
        return preg_replace('/\s+/', '', $source) ?? $source;
    }

    /** @return array<int,int> */
    private function lineMatches(string $source, string $pattern): array
    {
        $lines = [];
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $lines[] = substr_count(substr($source, 0, (int) $match[1]), "\n") + 1;
            }
        }
        return array_values(array_unique($lines));
    }

    private function firstLineMatch(string $source, string $pattern): ?int
    {
        if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        return substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1;
    }

    /** @return array<string,mixed> */
    private function finding(string $severity, string $confidence, string $category, string $title, string $message, string $path, ?int $line = null, ?string $class = null, ?string $method = null, array $meta = []): array
    {
        return [
            'severity' => $severity,
            'confidence' => $confidence,
            'category' => $category,
            'title' => $title,
            'message' => $message,
            'path' => $path,
            'line' => $line,
            'class' => $class,
            'method' => $method,
            'meta' => $meta,
        ];
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }
}
