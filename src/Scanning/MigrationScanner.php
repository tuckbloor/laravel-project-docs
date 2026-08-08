<?php

namespace DevDocs\LaravelProjectDocs\Scanning;

use DevDocs\LaravelProjectDocs\Contracts\Scanner;
use DevDocs\LaravelProjectDocs\Data\ProjectContext;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Throwable;

class MigrationScanner implements Scanner
{
    private readonly Parser $parser;
    private readonly NodeFinder $finder;
    private readonly Standard $printer;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
        $this->printer = new Standard();
    }

    public function key(): string
    {
        return 'database';
    }

    public function scan(ProjectContext $context): array
    {
        $directory = $context->path('database/migrations');
        $tables = [];
        $migrations = [];
        $errors = [];

        if (! is_dir($directory)) {
            return ['count' => 0, 'tables' => [], 'migrations' => [], 'errors' => []];
        }

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            $relative = 'database/migrations/'.basename($file);
            try {
                $source = file_get_contents($file);
                if ($source === false) {
                    continue;
                }
                $ast = $this->parser->parse($source) ?? [];
                $changes = $this->schemaChanges($ast);
                $migrations[] = ['path' => $relative, 'changes' => $changes];

                foreach ($changes as $change) {
                    $table = (string) ($change['table'] ?? '');
                    if ($table === '') {
                        continue;
                    }
                    $tables[$table] ??= [
                        'name' => $table,
                        'created_by' => null,
                        'columns' => [],
                        'foreign_keys' => [],
                        'indexes' => [],
                        'changes' => [],
                    ];
                    if (($change['operation'] ?? '') === 'create' && $tables[$table]['created_by'] === null) {
                        $tables[$table]['created_by'] = $relative;
                    }
                    $tables[$table]['changes'][] = ['migration' => $relative, 'operation' => $change['operation'] ?? 'table'];
                    foreach (($change['columns'] ?? []) as $column) {
                        $tables[$table]['columns'][(string) $column['name']] = $column;
                    }
                    foreach (($change['foreign_keys'] ?? []) as $foreign) {
                        $tables[$table]['foreign_keys'][] = $foreign;
                    }
                    foreach (($change['indexes'] ?? []) as $index) {
                        $tables[$table]['indexes'][] = $index;
                    }
                }
            } catch (Throwable $exception) {
                $errors[] = ['path' => $relative, 'message' => $exception->getMessage()];
            }
        }

        foreach ($tables as &$table) {
            $table['columns'] = array_values($table['columns']);
            $table['foreign_keys'] = array_values(array_unique($table['foreign_keys'], SORT_REGULAR));
            $table['indexes'] = array_values(array_unique($table['indexes'], SORT_REGULAR));
        }
        unset($table);
        ksort($tables);

        return [
            'count' => count($tables),
            'tables' => array_values($tables),
            'migrations' => $migrations,
            'errors' => $errors,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function schemaChanges(array $ast): array
    {
        $changes = [];

        foreach ($this->finder->findInstanceOf($ast, Node\Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || ! str_ends_with($call->class->toString(), 'Schema')) {
                continue;
            }
            if (! $call->name instanceof Node\Identifier || ! in_array($call->name->toString(), ['create', 'table'], true)) {
                continue;
            }
            $tableArg = $call->args[0]->value ?? null;
            $callback = $call->args[1]->value ?? null;
            if (! $tableArg instanceof Node\Scalar\String_ || ! $callback instanceof Node\Expr\Closure) {
                continue;
            }

            $change = [
                'operation' => $call->name->toString(),
                'table' => $tableArg->value,
                'columns' => [],
                'foreign_keys' => [],
                'indexes' => [],
                'line' => $call->getStartLine(),
            ];

            foreach ($callback->stmts as $statement) {
                if (! $statement instanceof Node\Stmt\Expression || ! $statement->expr instanceof Node\Expr\MethodCall) {
                    continue;
                }
                $chain = $this->methodChain($statement->expr);
                if ($chain === []) {
                    continue;
                }
                $base = $chain[0];
                $method = (string) ($base['method'] ?? '');
                $args = $base['args'] ?? [];

                if (in_array($method, ['timestamps', 'timestampsTz'], true)) {
                    $change['columns'][] = ['name' => 'created_at', 'type' => $method, 'modifiers' => []];
                    $change['columns'][] = ['name' => 'updated_at', 'type' => $method, 'modifiers' => []];
                    continue;
                }
                if (in_array($method, ['softDeletes', 'softDeletesTz'], true)) {
                    $change['columns'][] = ['name' => 'deleted_at', 'type' => $method, 'modifiers' => $this->modifiers($chain)];
                    continue;
                }

                $columnName = $this->stringArg($args[0] ?? null);
                if ($columnName === null && $method === 'id') { $columnName = 'id'; }
                if ($columnName === null && $method === 'rememberToken') { $columnName = 'remember_token'; }
                if ($columnName !== null && $this->isColumnMethod($method)) {
                    $column = [
                        'name' => $columnName,
                        'type' => $method,
                        'arguments' => array_map(fn (Node\Arg $arg) => $this->printer->prettyPrintExpr($arg->value), array_slice($args, 1)),
                        'modifiers' => $this->modifiers($chain),
                    ];
                    $change['columns'][] = $column;

                    if (in_array($method, ['foreignId', 'foreignUuid', 'foreignUlid'], true)) {
                        $constrained = $this->chainMethod($chain, 'constrained');
                        $references = $this->chainMethod($chain, 'references');
                        $on = $this->chainMethod($chain, 'on');
                        $change['foreign_keys'][] = [
                            'column' => $columnName,
                            'references' => $this->stringArg($references['args'][0] ?? null) ?? 'id',
                            'table' => $this->stringArg($constrained['args'][0] ?? null)
                                ?? $this->stringArg($on['args'][0] ?? null)
                                ?? $this->guessTableFromForeignColumn($columnName),
                        ];
                    }
                    continue;
                }

                if (in_array($method, ['foreign', 'primary', 'unique', 'index', 'fullText'], true)) {
                    $first = $args[0]->value ?? null;
                    $value = $first ? $this->printer->prettyPrintExpr($first) : '';
                    if ($method === 'foreign') {
                        $references = $this->chainMethod($chain, 'references');
                        $on = $this->chainMethod($chain, 'on');
                        $change['foreign_keys'][] = [
                            'column' => trim($value, "'\"[]"),
                            'references' => $this->stringArg($references['args'][0] ?? null),
                            'table' => $this->stringArg($on['args'][0] ?? null),
                        ];
                    } else {
                        $change['indexes'][] = ['type' => $method, 'columns' => $value];
                    }
                }
            }

            $changes[] = $change;
        }

        return $changes;
    }

    /** @return array<int, array{method:string,args:array<int, Node\Arg>}> */
    private function methodChain(Node\Expr\MethodCall $call): array
    {
        $chain = [];
        $cursor = $call;
        while ($cursor instanceof Node\Expr\MethodCall) {
            if (! $cursor->name instanceof Node\Identifier) {
                break;
            }
            array_unshift($chain, ['method' => $cursor->name->toString(), 'args' => $cursor->args]);
            $cursor = $cursor->var;
        }

        if (! $cursor instanceof Node\Expr\Variable || $cursor->name !== 'table') {
            return [];
        }

        return $chain;
    }

    private function isColumnMethod(string $method): bool
    {
        return in_array($method, [
            'id', 'increments', 'bigIncrements', 'integer', 'bigInteger', 'tinyInteger', 'smallInteger', 'mediumInteger',
            'unsignedInteger', 'unsignedBigInteger', 'boolean', 'string', 'char', 'text', 'mediumText', 'longText',
            'decimal', 'double', 'float', 'date', 'dateTime', 'dateTimeTz', 'time', 'timestamp', 'timestampTz',
            'json', 'jsonb', 'uuid', 'ulid', 'binary', 'enum', 'set', 'ipAddress', 'macAddress', 'year',
            'foreignId', 'foreignUuid', 'foreignUlid', 'rememberToken',
        ], true);
    }

    /** @param array<int, array{method:string,args:array<int, Node\Arg>}> $chain */
    private function modifiers(array $chain): array
    {
        $skip = array_shift($chain);
        $items = [];
        foreach ($chain as $link) {
            $items[] = $link['method'].($link['args'] ? '('.implode(', ', array_map(fn (Node\Arg $arg) => $this->printer->prettyPrintExpr($arg->value), $link['args'])).')' : '');
        }
        return $items;
    }

    /** @param array<int, array{method:string,args:array<int, Node\Arg>}> $chain */
    private function chainMethod(array $chain, string $method): ?array
    {
        foreach ($chain as $link) {
            if ($link['method'] === $method) {
                return $link;
            }
        }
        return null;
    }

    private function stringArg(Node\Arg|Node\Expr|null $arg): ?string
    {
        $expr = $arg instanceof Node\Arg ? $arg->value : $arg;
        return $expr instanceof Node\Scalar\String_ ? $expr->value : null;
    }

    private function guessTableFromForeignColumn(string $column): string
    {
        $base = preg_replace('/_id$/', '', $column) ?: $column;
        return str_ends_with($base, 's') ? $base : $base.'s';
    }
}
