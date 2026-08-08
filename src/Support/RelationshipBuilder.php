<?php

namespace DevDocs\LaravelProjectDocs\Support;

class RelationshipBuilder
{
    /**
     * @param array<string, array<string, mixed>> $sections
     * @return array<string, mixed>
     */
    public function build(array $sections): array
    {
        $relations = [];
        $phpByClass = [];
        $frontendPaths = [];
        $routesByName = [];
        $routesByUri = [];

        foreach (($sections['php']['items'] ?? []) as $file) {
            foreach (($file['classes'] ?? []) as $class) {
                if (! empty($class['fqcn'])) {
                    $phpByClass[ltrim((string) $class['fqcn'], '\\')] = (string) $file['path'];
                }
            }
        }

        foreach (($sections['frontend']['items'] ?? []) as $file) {
            $frontendPaths[str_replace('\\', '/', (string) $file['path'])] = true;
        }

        foreach (($sections['routes']['items'] ?? []) as $route) {
            $routeName = (string) ($route['name'] ?? '');
            $routeUri = trim((string) ($route['uri'] ?? ''), '/');
            if ($routeName !== '') { $routesByName[$routeName] = $route; }
            if ($routeUri !== '') { $routesByUri[$routeUri] = $route; }
        }

        foreach (($sections['routes']['items'] ?? []) as $route) {
            $action = (string) ($route['action'] ?? '');
            if ($action === '' || $action === 'Closure') {
                continue;
            }

            [$class, $method] = array_pad(explode('@', $action, 2), 2, null);
            $class = ltrim((string) $class, '\\');

            $relations[] = [
                'type' => 'route-controller',
                'from' => implode('|', $route['methods'] ?? []).' '.($route['uri'] ?? ''),
                'to' => $action,
                'target_path' => $phpByClass[$class] ?? null,
                'method' => $method,
            ];
        }

        foreach (($sections['php']['items'] ?? []) as $file) {
            $path = (string) ($file['path'] ?? '');

            foreach (($file['uses'] ?? []) as $use) {
                if (($use['type'] ?? 'class') !== 'class') {
                    continue;
                }

                $name = ltrim((string) ($use['name'] ?? ''), '\\');
                if (isset($phpByClass[$name])) {
                    $relations[] = [
                        'type' => 'php-import',
                        'from' => $path,
                        'to' => $name,
                        'target_path' => $phpByClass[$name],
                    ];
                }
            }

            foreach (($file['classes'] ?? []) as $class) {
                if (($class['category'] ?? '') !== 'model') { continue; }
                $fromClass = (string) ($class['fqcn'] ?? $class['name'] ?? $path);
                foreach (($class['model']['relationships'] ?? []) as $relation) {
                    $target = (string) ($relation['target'] ?? '');
                    $targetKey = ltrim($target, '\\');
                    $relations[] = [
                        'type' => 'model-relation',
                        'from' => $fromClass.'::'.($relation['method'] ?? '').'()',
                        'to' => ($relation['type'] ?? 'relation').' → '.$target,
                        'target_path' => $phpByClass[$targetKey] ?? null,
                    ];
                }
            }

            foreach (($file['references']['dispatches'] ?? []) as $dispatch) {
                $target = ltrim((string) $dispatch, '\\');
                $relations[] = [
                    'type' => 'job-dispatch',
                    'from' => $path,
                    'to' => $target,
                    'target_path' => $phpByClass[$target] ?? null,
                ];
            }

            foreach (($file['references']['views'] ?? []) as $view) {
                $target = 'resources/views/'.str_replace('.', '/', (string) $view).'.blade.php';
                $relations[] = [
                    'type' => 'blade-view',
                    'from' => $path,
                    'to' => $view,
                    'target_path' => isset($frontendPaths[$target]) ? $target : null,
                ];
            }

            foreach (($file['references']['inertia_pages'] ?? []) as $page) {
                $target = $this->firstExistingFrontend([
                    'resources/js/Pages/'.$page.'.vue',
                    'resources/js/pages/'.$page.'.vue',
                    'resources/js/Pages/'.$page.'.js',
                    'resources/js/Pages/'.$page.'.ts',
                    'resources/js/Pages/'.$page.'.tsx',
                    'resources/js/Pages/'.$page.'.jsx',
                ], $frontendPaths);

                $relations[] = [
                    'type' => 'inertia-page',
                    'from' => $path,
                    'to' => $page,
                    'target_path' => $target,
                ];
            }
        }

        foreach (($sections['frontend']['items'] ?? []) as $file) {
            $path = str_replace('\\', '/', (string) ($file['path'] ?? ''));
            $kind = (string) ($file['kind'] ?? '');

            if ($kind === 'blade') {
                foreach (($file['references']['includes'] ?? []) as $include) {
                    $target = 'resources/views/'.str_replace('.', '/', (string) $include).'.blade.php';
                    $relations[] = [
                        'type' => 'blade-include',
                        'from' => $path,
                        'to' => $include,
                        'target_path' => isset($frontendPaths[$target]) ? $target : null,
                    ];
                }
                foreach (($file['references']['routes'] ?? []) as $routeName) {
                    $route = $routesByName[(string) $routeName] ?? null;
                    if ($route !== null) {
                        $action = (string) ($route['action'] ?? '');
                        $class = ltrim(explode('@', $action, 2)[0] ?? '', '\\');
                        $relations[] = [
                            'type' => 'blade-route',
                            'from' => $path,
                            'to' => (string) $routeName.' → '.$action,
                            'target_path' => $phpByClass[$class] ?? null,
                            'route_name' => (string) $routeName,
                            'route_uri' => $route['uri'] ?? null,
                            'route_action' => $action,
                            'http_method' => isset($route['methods'][0]) ? (string) $route['methods'][0] : null,
                            'source_line' => null,
                        ];
                    }
                }

                continue;
            }

            foreach ((array) ($file['references']['routes'] ?? []) as $routeName) {
                $route = $routesByName[(string) $routeName] ?? null;
                if ($route === null) {
                    continue;
                }
                $action = (string) ($route['action'] ?? '');
                $class = ltrim(explode('@', $action, 2)[0] ?? '', '\\');
                $relations[] = [
                    'type' => 'frontend-route',
                    'from' => $path,
                    'to' => (string) $routeName.' → '.$action,
                    'target_path' => $phpByClass[$class] ?? null,
                    'route_name' => (string) $routeName,
                    'route_uri' => $route['uri'] ?? null,
                    'route_action' => $action,
                    'http_method' => isset($route['methods'][0]) ? (string) $route['methods'][0] : null,
                    'source_line' => null,
                ];
            }

            $httpCalls = (array) ($file['references']['http_calls'] ?? []);
            if ($httpCalls === []) {
                foreach ((array) ($file['references']['http'] ?? []) as $http) {
                    $httpCalls[] = ['method' => null, 'url' => (string) $http, 'line' => null];
                }
            }

            foreach ($httpCalls as $httpCall) {
                $url = (string) ($httpCall['url'] ?? '');
                $method = strtoupper((string) ($httpCall['method'] ?? ''));
                $uri = trim(parse_url($url, PHP_URL_PATH) ?: $url, '/');
                $route = $this->matchRoute($uri, $method !== '' ? $method : null, $sections['routes']['items'] ?? []);
                if ($route !== null) {
                    $action = (string) ($route['action'] ?? '');
                    $class = ltrim(explode('@', $action, 2)[0] ?? '', '\\');
                    $relations[] = [
                        'type' => 'frontend-http',
                        'from' => $path,
                        'to' => $url.' → '.$action,
                        'target_path' => $phpByClass[$class] ?? null,
                        'http_method' => $method !== '' ? $method : null,
                        'route_uri' => $route['uri'] ?? null,
                        'route_name' => $route['name'] ?? null,
                        'route_action' => $action,
                        'source_line' => $httpCall['line'] ?? null,
                    ];
                } else {
                    $relations[] = [
                        'type' => 'frontend-http-unresolved',
                        'from' => $path,
                        'to' => ($method !== '' ? $method.' ' : '').$url,
                        'target_path' => null,
                        'http_method' => $method !== '' ? $method : null,
                        'route_uri' => null,
                        'route_name' => null,
                        'route_action' => null,
                        'source_line' => $httpCall['line'] ?? null,
                    ];
                }
            }

            foreach (($file['references']['imports'] ?? []) as $import) {
                if (! str_starts_with((string) $import, '.')) {
                    continue;
                }

                $relations[] = [
                    'type' => 'frontend-import',
                    'from' => $path,
                    'to' => $import,
                    'target_path' => $this->resolveRelativeImport($path, (string) $import, $frontendPaths),
                ];
            }
        }

        return [
            'count' => count($relations),
            'items' => $relations,
        ];
    }


    /** @param array<int, array<string,mixed>> $routes */
    private function matchRoute(string $uri, ?string $method, array $routes): ?array
    {
        $uri = trim($uri, '/');
        $method = $method !== null ? strtoupper($method) : null;

        foreach ($routes as $route) {
            $routeUri = trim((string) ($route['uri'] ?? ''), '/');
            $methods = array_map('strtoupper', (array) ($route['methods'] ?? []));
            if ($method !== null && $method !== '' && $methods !== [] && ! in_array($method, $methods, true)) {
                continue;
            }

            if ($routeUri === $uri) {
                return $route;
            }

            // Match Laravel placeholders such as videos/{video} or optional
            // segments against static/template URLs found in frontend code.
            $pattern = preg_quote($routeUri, '~');
            $pattern = preg_replace('~\\\\\{[^}]+\\\\\?\\\\\}~', '[^/]*', $pattern) ?? $pattern;
            $pattern = preg_replace('~\\\\\{[^}]+\\\\\}~', '[^/]+', $pattern) ?? $pattern;
            if (preg_match('~^'.$pattern.'$~', $uri) === 1) {
                return $route;
            }

            // Template literal placeholders (${id}) are also common in Vue/JS.
            $normalisedFrontend = preg_replace('/\\$\\{[^}]+\\}/', '{value}', $uri) ?? $uri;
            if ($normalisedFrontend !== $uri) {
                $frontPattern = preg_quote($routeUri, '~');
                $frontPattern = preg_replace('~\\\\\{[^}]+\\\\\?\\\\\}~', '(?:\\\\\{value\\\\\})?', $frontPattern) ?? $frontPattern;
                $frontPattern = preg_replace('~\\\\\{[^}]+\\\\\}~', '\\\\{value\\\\\}', $frontPattern) ?? $frontPattern;
                if (preg_match('~^'.$frontPattern.'$~', $normalisedFrontend) === 1) {
                    return $route;
                }
            }
        }

        return null;
    }

    /** @param array<string, bool> $paths */
    private function resolveRelativeImport(string $sourcePath, string $import, array $paths): ?string
    {
        $base = $this->normalise(dirname($sourcePath).'/'.$import);
        $candidates = [
            $base,
            $base.'.vue',
            $base.'.js',
            $base.'.jsx',
            $base.'.ts',
            $base.'.tsx',
            $base.'/index.vue',
            $base.'/index.js',
            $base.'/index.ts',
        ];

        return $this->firstExistingFrontend($candidates, $paths);
    }

    /**
     * @param array<int, string> $candidates
     * @param array<string, bool> $paths
     */
    private function firstExistingFrontend(array $candidates, array $paths): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = $this->normalise($candidate);
            if (isset($paths[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalise(string $path): string
    {
        $parts = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }
}
