<?php

namespace DevDocs\LaravelProjectDocs\Scanning;

use DevDocs\LaravelProjectDocs\Contracts\Scanner;
use DevDocs\LaravelProjectDocs\Data\ProjectContext;
use DevDocs\LaravelProjectDocs\Support\DescriptionGuesser;
use DevDocs\LaravelProjectDocs\Support\FileDiscovery;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Throwable;

class PhpScanner implements Scanner
{
    private readonly Parser $parser;
    private readonly NodeFinder $finder;
    private readonly Standard $printer;

    public function __construct(
        private readonly FileDiscovery $files,
        private readonly DescriptionGuesser $descriptions,
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
        $this->printer = new Standard();
    }

    public function key(): string
    {
        return 'php';
    }

    public function scan(ProjectContext $context): array
    {
        $items = [];
        $errors = [];

        foreach ($this->files->files($context, ['.php']) as $file) {
            $relative = $this->files->relativePath($context->rootPath, $file);

            try {
                $items[] = $this->scanFile($context, $file, $relative);
            } catch (Throwable $exception) {
                $errors[] = [
                    'path' => $relative,
                    'message' => $exception->getMessage(),
                ];

                // Never hide source code just because structural parsing failed.
                // This is particularly important when a host project uses PHP
                // syntax newer than the installed parser understands.
                $items[] = $this->fallbackFile($context, $file, $relative, $exception);
            }
        }

        return [
            'count' => count($items),
            'items' => $items,
            'errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    private function scanFile(ProjectContext $context, string $file, string $relative): array
    {
        $code = file_get_contents($file);
        if ($code === false) {
            throw new \RuntimeException('Unable to read file.');
        }

        $ast = $this->parser->parse($code) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $namespaceNode = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Namespace_::class);
        $namespace = $namespaceNode?->name?->toString();

        $uses = [];
        foreach ($this->finder->findInstanceOf($ast, Node\Stmt\Use_::class) as $useStatement) {
            foreach ($useStatement->uses as $use) {
                $uses[] = [
                    'name' => $use->name->toString(),
                    'alias' => $use->alias?->toString(),
                    'type' => $this->useType($useStatement->type),
                ];
            }
        }

        $classes = [];
        foreach ($this->finder->find($ast, fn (Node $node) => $node instanceof Node\Stmt\ClassLike) as $classLike) {
            /** @var Node\Stmt\ClassLike $classLike */
            $name = $classLike->name?->toString() ?? 'anonymous';

            // PHP-Parser 5's NameResolver exposes the resolved class-like name
            // on the node property (`namespacedName`). Older releases/examples
            // commonly used an attribute. Support both, then synthesize from
            // namespace + short name as a final fallback. Without this FQCN the
            // intelligence layer cannot join routes to controllers or models.
            $resolvedName = null;
            if (property_exists($classLike, 'namespacedName') && $classLike->namespacedName instanceof Node\Name) {
                $resolvedName = $classLike->namespacedName;
            } else {
                $attributeName = $classLike->getAttribute('namespacedName');
                if ($attributeName instanceof Node\Name) {
                    $resolvedName = $attributeName;
                }
            }
            $fqcn = $resolvedName?->toString();
            if (($fqcn === null || $fqcn === '') && $name !== 'anonymous') {
                $fqcn = $namespace ? trim($namespace, '\\').'\\'.$name : $name;
            }
            $extends = $classLike instanceof Node\Stmt\Class_ ? $classLike->extends?->toString() : null;
            $implements = $classLike instanceof Node\Stmt\Class_
                ? array_map(fn (Node\Name $n) => $n->toString(), $classLike->implements)
                : [];
            $traits = $this->traits($classLike);
            $properties = $this->properties($classLike);
            $dependencies = $this->dependencies($classLike, $properties);

            $methods = [];
            foreach ($classLike->getMethods() as $method) {
                $complexity = $this->methodComplexity($method);
                $methods[] = [
                    'name' => $method->name->toString(),
                    'description' => $this->descriptions->forMethod($method->name->toString()),
                    'visibility' => $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private'),
                    'static' => $method->isStatic(),
                    'abstract' => $method->isAbstract(),
                    'final' => $method->isFinal(),
                    'parameters' => array_map(fn (Node\Param $param) => $this->parameter($param), $method->params),
                    'return_type' => $this->typeToString($method->returnType),
                    'start_line' => $method->getStartLine(),
                    'end_line' => $method->getEndLine(),
                    'lines' => max(1, $method->getEndLine() - $method->getStartLine() + 1),
                    'complexity' => $complexity,
                    'calls' => $this->methodCalls($method),
                ];
            }

            $category = $this->classCategory($relative, $name, $fqcn, $extends, $implements);
            $model = $category === 'model' ? $this->modelMetadata($classLike, $properties, $traits) : null;
            $validation = $category === 'request' ? $this->validationRules($classLike) : null;

            $classes[] = [
                'name' => $name,
                'fqcn' => $fqcn,
                'kind' => $this->classKind($classLike),
                'category' => $category,
                'description' => $this->descriptions->forClass($name, $extends),
                'extends' => $extends,
                'implements' => $implements,
                'traits' => $traits,
                'properties' => $properties,
                'dependencies' => $dependencies,
                'methods' => $methods,
                'model' => $model,
                'validation' => $validation,
                'metrics' => [
                    'lines' => max(1, $classLike->getEndLine() - $classLike->getStartLine() + 1),
                    'methods' => count($methods),
                    'dependencies' => count($dependencies),
                    'max_method_lines' => $methods ? max(array_column($methods, 'lines')) : 0,
                    'max_method_complexity' => $methods ? max(array_column($methods, 'complexity')) : 0,
                ],
                'start_line' => $classLike->getStartLine(),
                'end_line' => $classLike->getEndLine(),
            ];
        }

        $source = $this->source($context, $code);

        return [
            'path' => $relative,
            'namespace' => $namespace,
            'uses' => $uses,
            'classes' => $classes,
            'references' => $this->frameworkReferences($ast),
            'source' => $source,
            'source_meta' => $this->sourceMeta($code, $source),
            'parse_error' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function fallbackFile(ProjectContext $context, string $file, string $relative, Throwable $exception): array
    {
        $code = file_get_contents($file);
        if ($code === false) {
            $code = '';
        }

        $source = $this->source($context, $code);
        $namespace = null;
        if (preg_match('/\bnamespace\s+([^;{]+)[;{]/', $code, $matches)) {
            $namespace = trim($matches[1]);
        }

        /*
         * Keep a lightweight class record even when PHP-Parser cannot parse a
         * newer language feature. This preserves navigation and, importantly,
         * still recognises conventional Eloquent models such as the default
         * App\\Models\\User class which extends Laravel's Authenticatable base.
         */
        $classes = [];
        $pattern = '/\b(?:(abstract|final|readonly)\s+)?(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s+extends\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*))?(?:\s+implements\s+([^\{]+))?/m';
        if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $name = (string) ($match[3][0] ?? '');
                if ($name === '') {
                    continue;
                }

                $kind = (string) ($match[2][0] ?? 'class');
                $extends = trim((string) ($match[4][0] ?? ''));
                $implementsRaw = trim((string) ($match[5][0] ?? ''));
                $implements = $implementsRaw === ''
                    ? []
                    : array_values(array_filter(array_map('trim', explode(',', $implementsRaw))));
                $offset = (int) ($match[0][1] ?? 0);
                $startLine = substr_count(substr($code, 0, $offset), "\n") + 1;
                $fqcn = $namespace ? trim($namespace, '\\').'\\'.$name : $name;
                $category = $this->classCategory($relative, $name, $fqcn, $extends ?: null, $implements);

                $classes[] = [
                    'name' => $name,
                    'fqcn' => $fqcn,
                    'kind' => $kind,
                    'category' => $category,
                    'description' => $this->descriptions->forClass($name, $extends ?: null),
                    'extends' => $extends ?: null,
                    'implements' => $implements,
                    'traits' => [],
                    'properties' => [],
                    'dependencies' => [],
                    'methods' => [],
                    'model' => $category === 'model' ? [
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
                    ] : null,
                    'validation' => null,
                    'metrics' => [
                        'lines' => 0,
                        'methods' => 0,
                        'dependencies' => 0,
                        'max_method_lines' => 0,
                        'max_method_complexity' => 0,
                    ],
                    'start_line' => $startLine,
                    'end_line' => $startLine,
                    'analysis_fallback' => true,
                ];
            }
        }

        return [
            'path' => $relative,
            'namespace' => $namespace,
            'uses' => [],
            'classes' => $classes,
            'references' => [],
            'source' => $source,
            'source_meta' => $this->sourceMeta($code, $source),
            'parse_error' => $exception->getMessage(),
        ];
    }

    /** @return array<string, mixed> */
    private function parameter(Node\Param $param): array
    {
        return [
            'name' => is_string($param->var->name) ? $param->var->name : 'unknown',
            'type' => $this->typeToString($param->type),
            'by_reference' => $param->byRef,
            'variadic' => $param->variadic,
            'has_default' => $param->default !== null,
            'promoted' => $param->flags !== 0,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function properties(Node\Stmt\ClassLike $classLike): array
    {
        $items = [];

        foreach ($classLike->stmts as $statement) {
            if (! $statement instanceof Node\Stmt\Property) {
                continue;
            }

            foreach ($statement->props as $property) {
                $items[] = [
                    'name' => $property->name->toString(),
                    'type' => $this->typeToString($statement->type),
                    'visibility' => $statement->isPublic() ? 'public' : ($statement->isProtected() ? 'protected' : 'private'),
                    'static' => $statement->isStatic(),
                    'default' => $property->default ? $this->simpleValue($property->default) : null,
                ];
            }
        }

        return $items;
    }

    /** @param array<int, array<string, mixed>> $properties @return array<int, array<string, string|null>> */
    private function dependencies(Node\Stmt\ClassLike $classLike, array $properties): array
    {
        $dependencies = [];

        foreach ($properties as $property) {
            $type = (string) ($property['type'] ?? '');
            if ($this->looksLikeClassType($type)) {
                $dependencies[] = ['variable' => '$this->'.(string) $property['name'], 'type' => $type, 'source' => 'property'];
            }
        }

        foreach ($classLike->getMethods() as $method) {
            if ($method->name->toString() !== '__construct') {
                continue;
            }

            foreach ($method->params as $param) {
                $type = (string) ($this->typeToString($param->type) ?? '');
                if (! $this->looksLikeClassType($type)) {
                    continue;
                }
                $name = is_string($param->var->name) ? $param->var->name : 'dependency';
                $dependencies[] = [
                    'variable' => $param->flags !== 0 ? '$this->'.$name : '$'.$name,
                    'type' => $type,
                    'source' => $param->flags !== 0 ? 'promoted-constructor' : 'constructor',
                ];
            }
        }

        $unique = [];
        foreach ($dependencies as $dependency) {
            $unique[$dependency['variable'].'|'.$dependency['type']] = $dependency;
        }

        return array_values($unique);
    }

    private function looksLikeClassType(string $type): bool
    {
        if ($type === '' || str_contains($type, '|') || str_contains($type, '&')) {
            return $type !== '' && ! preg_match('/^(?:null|bool|boolean|int|integer|float|string|array|object|callable|iterable|mixed|void|never|self|static)$/i', $type);
        }

        return ! preg_match('/^(?:null|bool|boolean|int|integer|float|string|array|object|callable|iterable|mixed|void|never|self|static)$/i', ltrim($type, '?'));
    }

    /** @return array<int, array<string, mixed>> */
    private function methodCalls(Node\Stmt\ClassMethod $method): array
    {
        if ($method->stmts === null) {
            return [];
        }

        $calls = [];
        $nodes = $this->finder->find($method->stmts, fn (Node $node) =>
            $node instanceof Node\Expr\StaticCall
            || $node instanceof Node\Expr\MethodCall
            || $node instanceof Node\Expr\FuncCall
            || $node instanceof Node\Expr\New_
        );

        foreach ($nodes as $node) {
            if ($node instanceof Node\Expr\StaticCall) {
                $calls[] = [
                    'type' => 'static',
                    'target' => $node->class instanceof Node\Name ? $node->class->toString() : 'dynamic',
                    'method' => $node->name instanceof Node\Identifier ? $node->name->toString() : 'dynamic',
                    'line' => $node->getStartLine(),
                ];
            } elseif ($node instanceof Node\Expr\MethodCall) {
                $calls[] = [
                    'type' => 'method',
                    'target' => $this->methodCallTarget($node->var),
                    'method' => $node->name instanceof Node\Identifier ? $node->name->toString() : 'dynamic',
                    'line' => $node->getStartLine(),
                ];
            } elseif ($node instanceof Node\Expr\FuncCall) {
                $calls[] = [
                    'type' => 'function',
                    'target' => $node->name instanceof Node\Name ? $node->name->toString() : 'dynamic',
                    'method' => null,
                    'line' => $node->getStartLine(),
                ];
            } elseif ($node instanceof Node\Expr\New_) {
                $calls[] = [
                    'type' => 'new',
                    'target' => $node->class instanceof Node\Name ? $node->class->toString() : 'dynamic',
                    'method' => '__construct',
                    'line' => $node->getStartLine(),
                ];
            }
        }

        return array_values(array_unique($calls, SORT_REGULAR));
    }

    private function methodCallTarget(Node\Expr $expr): string
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return '$'.$expr->name;
        }

        if ($expr instanceof Node\Expr\PropertyFetch
            && $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier) {
            return '$this->'.$expr->name->toString();
        }

        if ($expr instanceof Node\Expr\StaticPropertyFetch && $expr->class instanceof Node\Name) {
            return $expr->class->toString().'::$'.($expr->name instanceof Node\VarLikeIdentifier ? $expr->name->toString() : 'dynamic');
        }

        return 'expression';
    }

    private function methodComplexity(Node\Stmt\ClassMethod $method): int
    {
        if ($method->stmts === null) {
            return 1;
        }

        $decisionNodes = $this->finder->find($method->stmts, function (Node $node): bool {
            return $node instanceof Node\Stmt\If_
                || $node instanceof Node\Stmt\ElseIf_
                || $node instanceof Node\Stmt\For_
                || $node instanceof Node\Stmt\Foreach_
                || $node instanceof Node\Stmt\While_
                || $node instanceof Node\Stmt\Do_
                || $node instanceof Node\Stmt\Case_
                || $node instanceof Node\Stmt\Catch_
                || $node instanceof Node\Expr\Ternary
                || $node instanceof Node\Expr\BinaryOp\BooleanAnd
                || $node instanceof Node\Expr\BinaryOp\BooleanOr
                || $node instanceof Node\Expr\BinaryOp\LogicalAnd
                || $node instanceof Node\Expr\BinaryOp\LogicalOr;
        });

        return 1 + count($decisionNodes);
    }

    /** @return array<string, mixed> */
    private function modelMetadata(Node\Stmt\ClassLike $classLike, array $properties, array $traits): array
    {
        $propertyMap = [];
        foreach ($properties as $property) {
            $propertyMap[(string) $property['name']] = $property['default'] ?? null;
        }

        $relationships = [];
        $relationshipMethods = [
            'hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'morphOne', 'morphMany',
            'morphTo', 'morphToMany', 'morphedByMany', 'hasOneThrough', 'hasManyThrough',
        ];

        foreach ($classLike->getMethods() as $method) {
            if ($method->stmts === null) {
                continue;
            }

            foreach ($this->finder->findInstanceOf($method->stmts, Node\Stmt\Return_::class) as $return) {
                $call = $return->expr;
                while ($call instanceof Node\Expr\MethodCall && $call->var instanceof Node\Expr\MethodCall) {
                    $call = $call->var;
                }
                if (! $call instanceof Node\Expr\MethodCall || ! $call->name instanceof Node\Identifier) {
                    continue;
                }
                $relation = $call->name->toString();
                if (! in_array($relation, $relationshipMethods, true)) {
                    continue;
                }
                $target = $call->args[0]->value ?? null;
                $relationships[] = [
                    'method' => $method->name->toString(),
                    'type' => $relation,
                    'target' => $target ? $this->relationshipTarget($target) : null,
                    'line' => $method->getStartLine(),
                ];
                break;
            }
        }

        $casts = is_array($propertyMap['casts'] ?? null) ? $propertyMap['casts'] : $this->arrayReturnFromMethod($classLike, 'casts');

        return [
            'table' => is_string($propertyMap['table'] ?? null) ? $propertyMap['table'] : null,
            'connection' => is_string($propertyMap['connection'] ?? null) ? $propertyMap['connection'] : null,
            'primary_key' => is_string($propertyMap['primaryKey'] ?? null) ? $propertyMap['primaryKey'] : 'id',
            'fillable' => is_array($propertyMap['fillable'] ?? null) ? $propertyMap['fillable'] : [],
            'guarded' => is_array($propertyMap['guarded'] ?? null) ? $propertyMap['guarded'] : [],
            'casts' => $casts,
            'hidden' => is_array($propertyMap['hidden'] ?? null) ? $propertyMap['hidden'] : [],
            'timestamps' => is_bool($propertyMap['timestamps'] ?? null) ? $propertyMap['timestamps'] : true,
            'soft_deletes' => (bool) array_filter($traits, fn (string $trait) => str_ends_with($trait, 'SoftDeletes')),
            'relationships' => $relationships,
        ];
    }

    private function relationshipTarget(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === 'class') {
            return $expr->class->toString();
        }

        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        return $this->printer->prettyPrintExpr($expr);
    }

    private function arrayReturnFromMethod(Node\Stmt\ClassLike $classLike, string $methodName): array
    {
        foreach ($classLike->getMethods() as $method) {
            if ($method->name->toString() !== $methodName || $method->stmts === null) {
                continue;
            }
            foreach ($this->finder->findInstanceOf($method->stmts, Node\Stmt\Return_::class) as $return) {
                if ($return->expr instanceof Node\Expr\Array_) {
                    return $this->arrayValue($return->expr);
                }
            }
        }
        return [];
    }

    /** @return array<string, mixed>|null */
    private function validationRules(Node\Stmt\ClassLike $classLike): ?array
    {
        foreach ($classLike->getMethods() as $method) {
            if ($method->name->toString() !== 'rules' || $method->stmts === null) {
                continue;
            }

            foreach ($this->finder->findInstanceOf($method->stmts, Node\Stmt\Return_::class) as $return) {
                if (! $return->expr instanceof Node\Expr\Array_) {
                    continue;
                }

                $rules = [];
                foreach ($return->expr->items as $item) {
                    if ($item === null || $item->key === null) {
                        continue;
                    }
                    $key = $this->simpleValue($item->key);
                    if (! is_string($key) && ! is_int($key)) {
                        continue;
                    }
                    $rules[(string) $key] = $this->ruleValue($item->value);
                }

                return [
                    'rules' => $rules,
                    'start_line' => $method->getStartLine(),
                ];
            }
        }

        return null;
    }

    private function ruleValue(Node\Expr $expr): string|array|null
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Node\Expr\Array_) {
            $items = [];
            foreach ($expr->items as $item) {
                if ($item === null) {
                    continue;
                }
                $value = $this->simpleValue($item->value);
                $items[] = is_scalar($value) || $value === null ? (string) $value : $this->printer->prettyPrintExpr($item->value);
            }
            return $items;
        }

        return $this->printer->prettyPrintExpr($expr);
    }

    private function classCategory(string $path, string $name, ?string $fqcn, ?string $extends, array $implements): string
    {
        $normal = str_replace('\\', '/', $path);
        $normalLower = strtolower($normal);
        $extends = ltrim((string) $extends, '\\');
        $extendsLower = strtolower($extends);
        $interfaces = array_map(fn (string $v) => ltrim($v, '\\'), $implements);

        $knownEloquentBases = [
            'illuminate\\database\\eloquent\\model',
            'illuminate\\foundation\\auth\\user',
            'illuminate\\database\\eloquent\\relations\\pivot',
            'illuminate\\database\\eloquent\\relations\\morphpivot',
            'model',
            'authenticatable',
            'pivot',
            'morphpivot',
        ];

        return match (true) {
            in_array($extendsLower, $knownEloquentBases, true)
                || str_ends_with($extendsLower, '\\model')
                || str_ends_with($extendsLower, 'basemodel')
                || str_contains($normalLower, '/models/')
                || str_starts_with($normalLower, 'app/models/') => 'model',
            str_ends_with($extends, 'FormRequest') || str_contains($normal, '/Requests/') => 'request',
            str_ends_with($extends, 'Controller') || str_contains($normal, '/Controllers/') => 'controller',
            in_array('Illuminate\\Contracts\\Queue\\ShouldQueue', $interfaces, true) || str_contains($normal, '/Jobs/') => 'job',
            str_contains($normal, '/Events/') => 'event',
            str_contains($normal, '/Listeners/') => 'listener',
            str_ends_with($extends, 'Mailable') || str_contains($normal, '/Mail/') => 'mail',
            str_contains($normal, '/Notifications/') => 'notification',
            str_contains($normal, '/Policies/') || str_ends_with($name, 'Policy') => 'policy',
            str_ends_with($extends, 'Command') || str_contains($normal, '/Console/Commands/') => 'command',
            str_contains($normal, '/Middleware/') => 'middleware',
            str_contains($normal, '/Providers/') => 'provider',
            str_contains($normal, '/Observers/') => 'observer',
            str_contains($normal, '/Services/') || str_ends_with($name, 'Service') => 'service',
            default => 'class',
        };
    }

    /** @return array<string, array<int, string>> */
    private function frameworkReferences(array $ast): array
    {
        $views = [];
        $inertia = [];
        $dispatches = [];
        $events = [];

        foreach ($this->finder->findInstanceOf($ast, Node\Expr\FuncCall::class) as $call) {
            if ($call->name instanceof Node\Name && $call->name->toString() === 'view') {
                $value = $this->firstStringArgument($call->args);
                if ($value !== null) {
                    $views[] = $value;
                }
            }
            if ($call->name instanceof Node\Name && in_array($call->name->toString(), ['event', 'dispatch'], true)) {
                $arg = $call->args[0]->value ?? null;
                if ($arg instanceof Node\Expr\New_ && $arg->class instanceof Node\Name) {
                    $events[] = $arg->class->toString();
                }
            }
        }

        foreach ($this->finder->findInstanceOf($ast, Node\Expr\StaticCall::class) as $call) {
            $method = $call->name instanceof Node\Identifier ? $call->name->toString() : null;
            $class = $call->class instanceof Node\Name ? $call->class->toString() : null;
            if ($method === 'render' && $class !== null && str_ends_with($class, 'Inertia')) {
                $value = $this->firstStringArgument($call->args);
                if ($value !== null) {
                    $inertia[] = $value;
                }
            }
            if ($method === 'dispatch' && $class !== null) {
                $dispatches[] = $class;
            }
        }

        return [
            'views' => array_values(array_unique($views)),
            'inertia_pages' => array_values(array_unique($inertia)),
            'dispatches' => array_values(array_unique($dispatches)),
            'events' => array_values(array_unique($events)),
        ];
    }

    /** @param array<int, Node\Arg> $args */
    private function firstStringArgument(array $args): ?string
    {
        $first = $args[0]->value ?? null;
        return $first instanceof Node\Scalar\String_ ? $first->value : null;
    }

    /** @return array<int, string> */
    private function traits(Node\Stmt\ClassLike $classLike): array
    {
        $traits = [];
        foreach ($classLike->stmts as $statement) {
            if ($statement instanceof Node\Stmt\TraitUse) {
                foreach ($statement->traits as $trait) {
                    $traits[] = $trait->toString();
                }
            }
        }
        return array_values(array_unique($traits));
    }

    private function classKind(Node\Stmt\ClassLike $node): string
    {
        return match (true) {
            $node instanceof Node\Stmt\Interface_ => 'interface',
            $node instanceof Node\Stmt\Trait_ => 'trait',
            $node instanceof Node\Stmt\Enum_ => 'enum',
            default => 'class',
        };
    }

    private function useType(int $type): string
    {
        return match ($type) {
            Node\Stmt\Use_::TYPE_FUNCTION => 'function',
            Node\Stmt\Use_::TYPE_CONSTANT => 'constant',
            default => 'class',
        };
    }

    private function typeToString(Node\Identifier|Node\Name|Node\ComplexType|null $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\Identifier || $type instanceof Node\Name) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?'.$this->typeToString($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn ($item) => $this->typeToString($item), $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(fn ($item) => $this->typeToString($item), $type->types));
        }
        return null;
    }

    private function simpleValue(Node\Expr $expr): mixed
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_ => $expr->value,
            $expr instanceof Node\Scalar\LNumber => $expr->value,
            $expr instanceof Node\Scalar\DNumber => $expr->value,
            $expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'true' => true,
            $expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'false' => false,
            $expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'null' => null,
            $expr instanceof Node\Expr\Array_ => $this->arrayValue($expr),
            $expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name => $expr->class->toString().'::'.($expr->name instanceof Node\Identifier ? $expr->name->toString() : 'class'),
            default => $this->printer->prettyPrintExpr($expr),
        };
    }

    private function arrayValue(Node\Expr\Array_ $array): array
    {
        $result = [];
        foreach ($array->items as $index => $item) {
            if ($item === null) {
                continue;
            }
            $value = $this->simpleValue($item->value);
            if ($item->key !== null) {
                $key = $this->simpleValue($item->key);
                $result[(string) $key] = $value;
            } else {
                $result[] = $value;
            }
        }
        return $result;
    }

    private function source(ProjectContext $context, string $code): ?string
    {
        if (! $context->includeSource || ! ($context->config['include_source'] ?? true)) {
            return null;
        }

        $max = (int) ($context->config['max_source_bytes'] ?? 0);
        if ($max > 0 && strlen($code) > $max) {
            return null;
        }

        return $code;
    }

    /** @return array<string, int|bool|string|null> */
    private function sourceMeta(string $code, ?string $source): array
    {
        $normal = str_replace(["\r\n", "\r"], "\n", $code);

        return [
            'bytes' => strlen($code),
            'lines' => $code === '' ? 0 : substr_count($normal, "\n") + 1,
            'included' => $source !== null,
            'reason' => $source === null ? 'Source embedding disabled or file exceeds configured max_source_bytes.' : null,
        ];
    }
}
