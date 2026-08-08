<?php

namespace DevDocs\LaravelProjectDocs\Scanning;

use DevDocs\LaravelProjectDocs\Contracts\Scanner;
use DevDocs\LaravelProjectDocs\Data\ProjectContext;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Reads Laravel route files without invoking route/controller callbacks.
 *
 * The Artisan command necessarily boots Laravel itself, but this scanner never
 * resolves controllers, gathers runtime middleware, invokes route closures, or
 * executes application business methods. Route information is inferred from
 * source syntax only.
 */
class RouteScanner implements Scanner
{
    private readonly Parser $parser;
    private readonly NodeFinder $finder;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
    }

    public function key(): string
    {
        return 'routes';
    }

    public function scan(ProjectContext $context): array
    {
        $directory = $context->path('routes');
        $routes = [];
        $errors = [];

        if (! is_dir($directory)) {
            return ['count' => 0, 'items' => [], 'errors' => []];
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (! $entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
                continue;
            }

            $file = $entry->getPathname();
            $relative = 'routes/'.str_replace('\\', '/', substr($file, strlen($directory) + 1));
            try {
                foreach ($this->scanFile($file, $relative) as $route) {
                    $routes[] = $route;
                }
            } catch (Throwable $exception) {
                $errors[] = ['path' => $relative, 'message' => $exception->getMessage()];
            }
        }

        $routes = array_values(array_unique($routes, SORT_REGULAR));
        usort($routes, fn (array $a, array $b) => [$a['uri'], implode(',', $a['methods'])] <=> [$b['uri'], implode(',', $b['methods'])]);

        return [
            'count' => count($routes),
            'items' => $routes,
            'errors' => $errors,
            'mode' => 'static-source',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function scanFile(string $file, string $relative): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new \RuntimeException('Unable to read route file.');
        }

        $ast = $this->parser->parse($source) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $ast = $traverser->traverse($ast);

        $items = [];
        $routeMethods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'any', 'match', 'view', 'redirect'];

        foreach ($this->finder->findInstanceOf($ast, Node\Expr\StaticCall::class) as $call) {
            if (! $this->isRouteFacadeCall($call) || ! $call->name instanceof Node\Identifier) {
                continue;
            }

            $method = strtolower($call->name->toString());
            if (in_array($method, ['resource', 'apiresource'], true)) {
                foreach ($this->resourceRoutes($call, $method === 'apiresource', $relative) as $resource) {
                    $items[] = $resource;
                }
                continue;
            }
            if (! in_array($method, $routeMethods, true)) {
                continue;
            }

            $context = $this->groupContext($call);
            $uriArgIndex = $method === 'match' ? 1 : 0;
            $uri = $this->stringValue($call->args[$uriArgIndex]->value ?? null) ?? '';
            if ($uri === '' && ! in_array($method, ['redirect', 'view'], true)) {
                continue;
            }
            $uri = $this->joinUri((string) ($context['prefix'] ?? ''), $uri);

            $methods = match ($method) {
                'any' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
                'match' => array_map('strtoupper', $this->stringArray($call->args[0]->value ?? null)),
                'view', 'redirect' => ['GET'],
                default => [strtoupper($method)],
            };

            $actionArgIndex = $method === 'match' ? 2 : 1;
            if (in_array($method, ['view', 'redirect'], true)) {
                $action = ucfirst($method);
            } else {
                $action = $this->actionValue($call->args[$actionArgIndex]->value ?? null, $context['controller'] ?? null);
            }

            $chain = $this->outerChain($call);
            $name = $this->chainString($chain, 'name');
            if ($name !== null && ($context['name_prefix'] ?? '') !== '') {
                $name = $context['name_prefix'].$name;
            }
            $middleware = array_values(array_unique(array_merge(
                (array) ($context['middleware'] ?? []),
                $this->chainMiddleware($chain),
            )));

            $items[] = [
                'methods' => array_values(array_filter($methods, fn (string $m) => strtoupper($m) !== 'HEAD')),
                'uri' => $uri,
                'name' => $name,
                'action' => $action,
                'middleware' => $middleware,
                'domain' => $this->chainString($chain, 'domain') ?? ($context['domain'] ?? null),
                'source_path' => $relative,
                'source_line' => $call->getStartLine(),
                'static' => true,
            ];
        }

        return $items;
    }

    private function isRouteFacadeCall(Node\Expr\StaticCall $call): bool
    {
        if (! $call->class instanceof Node\Name) {
            return false;
        }
        $name = ltrim($call->class->toString(), '\\');
        return $name === 'Route' || str_ends_with($name, '\\Route') || str_ends_with($name, '\\Facades\\Route');
    }

    /** @return array<string,mixed> */
    private function groupContext(Node $node): array
    {
        $context = ['prefix' => '', 'name_prefix' => '', 'middleware' => [], 'domain' => null, 'controller' => null];
        $cursor = $node;

        while (($parent = $cursor->getAttribute('parent')) instanceof Node) {
            if ($parent instanceof Node\Expr\Closure || $parent instanceof Node\Expr\ArrowFunction) {
                $arg = $parent->getAttribute('parent');
                $groupCall = $arg instanceof Node\Arg ? $arg->getAttribute('parent') : null;
                if ($groupCall instanceof Node\Expr\MethodCall && $groupCall->name instanceof Node\Identifier && strtolower($groupCall->name->toString()) === 'group') {
                    $chain = $this->methodChainFromExpression($groupCall->var);
                    $prefix = $this->chainString($chain, 'prefix');
                    if ($prefix !== null) {
                        $context['prefix'] = $this->joinUri($prefix, (string) $context['prefix']);
                    }
                    $namePrefix = $this->chainString($chain, 'name') ?? $this->chainString($chain, 'as');
                    if ($namePrefix !== null) {
                        $context['name_prefix'] = $namePrefix.$context['name_prefix'];
                    }
                    $context['middleware'] = array_values(array_unique(array_merge($this->chainMiddleware($chain), $context['middleware'])));
                    $context['domain'] = $this->chainString($chain, 'domain') ?? $context['domain'];
                    $controller = $this->chainClass($chain, 'controller');
                    if ($controller !== null) {
                        $context['controller'] = $controller;
                    }
                }
            }
            $cursor = $parent;
        }

        return $context;
    }

    /** @return array<int,array{name:string,args:array<int,Node\Arg>}> */
    private function outerChain(Node\Expr\StaticCall $base): array
    {
        $chain = [];
        $cursor = $base;
        while (($parent = $cursor->getAttribute('parent')) instanceof Node\Expr\MethodCall && $parent->var === $cursor) {
            if ($parent->name instanceof Node\Identifier) {
                $chain[] = ['name' => strtolower($parent->name->toString()), 'args' => $parent->args];
            }
            $cursor = $parent;
        }
        return $chain;
    }

    /** @return array<int,array{name:string,args:array<int,Node\Arg>}> */
    private function methodChainFromExpression(Node\Expr $expression): array
    {
        $chain = [];
        $cursor = $expression;
        while ($cursor instanceof Node\Expr\MethodCall) {
            if ($cursor->name instanceof Node\Identifier) {
                array_unshift($chain, ['name' => strtolower($cursor->name->toString()), 'args' => $cursor->args]);
            }
            $cursor = $cursor->var;
        }
        if ($cursor instanceof Node\Expr\StaticCall && $this->isRouteFacadeCall($cursor) && $cursor->name instanceof Node\Identifier) {
            array_unshift($chain, ['name' => strtolower($cursor->name->toString()), 'args' => $cursor->args]);
        }
        return $chain;
    }

    private function chainString(array $chain, string $name): ?string
    {
        foreach ($chain as $item) {
            if (($item['name'] ?? '') === strtolower($name)) {
                return $this->stringValue($item['args'][0]->value ?? null);
            }
        }
        return null;
    }

    private function chainClass(array $chain, string $name): ?string
    {
        foreach ($chain as $item) {
            if (($item['name'] ?? '') === strtolower($name)) {
                return $this->classValue($item['args'][0]->value ?? null);
            }
        }
        return null;
    }

    /** @return array<int,string> */
    private function chainMiddleware(array $chain): array
    {
        $items = [];
        foreach ($chain as $item) {
            if (($item['name'] ?? '') !== 'middleware') {
                continue;
            }
            foreach ($item['args'] ?? [] as $arg) {
                $value = $arg->value;
                if ($value instanceof Node\Expr\Array_) {
                    $items = array_merge($items, $this->stringArray($value));
                } else {
                    $string = $this->stringValue($value) ?? $this->classValue($value);
                    if ($string !== null) {
                        $items[] = $string;
                    }
                }
            }
        }
        return array_values(array_unique($items));
    }

    private function actionValue(?Node\Expr $expr, ?string $groupController = null): string
    {
        if ($expr instanceof Node\Expr\Closure || $expr instanceof Node\Expr\ArrowFunction) {
            return 'Closure';
        }
        if ($expr instanceof Node\Scalar\String_) {
            $value = $expr->value;
            if ($groupController !== null && ! str_contains($value, '@') && ! str_contains($value, '\\')) {
                return $groupController.'@'.$value;
            }
            return $value;
        }
        if ($expr instanceof Node\Expr\Array_ && count($expr->items) >= 2) {
            $class = $this->classValue($expr->items[0]?->value);
            $method = $this->stringValue($expr->items[1]?->value);
            if ($class !== null && $method !== null) {
                return $class.'@'.$method;
            }
        }
        $class = $this->classValue($expr);
        if ($class !== null) {
            return $class.'@__invoke';
        }
        return $groupController !== null ? $groupController.'@dynamic' : 'dynamic';
    }

    private function classValue(?Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Expr\ClassConstFetch && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'class' && $expr->class instanceof Node\Name) {
            return ltrim($expr->class->toString(), '\\');
        }
        return null;
    }

    private function stringValue(?Node\Expr $expr): ?string
    {
        return $expr instanceof Node\Scalar\String_ ? $expr->value : null;
    }

    /** @return array<int,string> */
    private function stringArray(?Node\Expr $expr): array
    {
        if (! $expr instanceof Node\Expr\Array_) {
            return [];
        }
        $values = [];
        foreach ($expr->items as $item) {
            $value = $this->stringValue($item?->value);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        return $values;
    }

    private function joinUri(string $prefix, string $uri): string
    {
        return trim(trim($prefix, '/').'/'.trim($uri, '/'), '/');
    }

    /** @return array<int,array<string,mixed>> */
    private function resourceRoutes(Node\Expr\StaticCall $call, bool $api, string $relative): array
    {
        $uri = $this->stringValue($call->args[0]->value ?? null);
        $controller = $this->classValue($call->args[1]->value ?? null);
        if ($uri === null || $controller === null) {
            return [];
        }
        $context = $this->groupContext($call);
        $uri = $this->joinUri((string) ($context['prefix'] ?? ''), $uri);
        $nameBase = str_replace('/', '.', trim($uri, '/'));
        $parameter = trim((string) preg_replace('/ies$/', 'y', preg_replace('/s$/', '', basename($uri))), '{}');
        $defs = [
            ['GET', $uri, 'index'],
            ['POST', $uri, 'store'],
            ['GET', $uri.'/{'.$parameter.'}', 'show'],
            ['PUT|PATCH', $uri.'/{'.$parameter.'}', 'update'],
            ['DELETE', $uri.'/{'.$parameter.'}', 'destroy'],
        ];
        if (! $api) {
            array_splice($defs, 1, 0, [['GET', $uri.'/create', 'create']]);
            array_splice($defs, -2, 0, [['GET', $uri.'/{'.$parameter.'}/edit', 'edit']]);
        }
        $items = [];
        foreach ($defs as [$method, $path, $action]) {
            $items[] = [
                'methods' => explode('|', $method),
                'uri' => $path,
                'name' => (($context['name_prefix'] ?? '').$nameBase.'.'.$action),
                'action' => $controller.'@'.$action,
                'middleware' => (array) ($context['middleware'] ?? []),
                'domain' => $context['domain'] ?? null,
                'source_path' => $relative,
                'source_line' => $call->getStartLine(),
                'static' => true,
            ];
        }
        return $items;
    }
}
