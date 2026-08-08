<?php

namespace DevDocs\LaravelProjectDocs\Scanning;

use DevDocs\LaravelProjectDocs\Contracts\Scanner;
use DevDocs\LaravelProjectDocs\Data\ProjectContext;
use DevDocs\LaravelProjectDocs\Support\FileDiscovery;

class FrontendScanner implements Scanner
{
    public function __construct(private readonly FileDiscovery $files)
    {
    }

    public function key(): string
    {
        return 'frontend';
    }

    public function scan(ProjectContext $context): array
    {
        $extensions = (array) ($context->config['frontend']['extensions'] ?? ['blade.php', 'vue', 'js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs', 'svelte']);
        $suffixes = array_map(fn (string $extension) => '.'.ltrim($extension, '.'), $extensions);
        $items = [];
        $errors = [];

        foreach ($this->files->files($context, $suffixes) as $file) {
            if (str_ends_with(strtolower($file), '.php') && ! str_ends_with(strtolower($file), '.blade.php')) {
                continue;
            }

            $relative = $this->files->relativePath($context->rootPath, $file);
            $source = file_get_contents($file);
            if ($source === false) {
                $errors[] = ['path' => $relative, 'message' => 'Unable to read frontend file.'];
                continue;
            }
            $kind = $this->kind($file);

            $embeddedSource = $this->source($context, $source);
            $items[] = [
                'path' => $relative,
                'kind' => $kind,
                'references' => $this->references($kind, $source),
                'source' => $embeddedSource,
                'source_meta' => $this->sourceMeta($source, $embeddedSource),
            ];
        }

        return [
            'count' => count($items),
            'items' => $items,
            'errors' => $errors,
        ];
    }

    private function kind(string $file): string
    {
        $lower = strtolower($file);

        return match (true) {
            str_ends_with($lower, '.blade.php') => 'blade',
            str_ends_with($lower, '.vue') => 'vue',
            str_ends_with($lower, '.svelte') => 'svelte',
            str_ends_with($lower, '.tsx') => 'react-typescript',
            str_ends_with($lower, '.jsx') => 'react-javascript',
            str_ends_with($lower, '.ts') => 'typescript',
            default => 'javascript',
        };
    }

    /** @return array<string, array<int, mixed>> */
    private function references(string $kind, string $source): array
    {
        return match ($kind) {
            'blade' => [
                'extends' => $this->matches($source, '/@extends\\([\'\"]([^\'\"]+)[\'\"]\\)/'),
                'includes' => $this->matches($source, '/@include(?:If|When|Unless)?\\([\'\"]([^\'\"]+)[\'\"]\\)/'),
                'components' => $this->matches($source, '/<x-([A-Za-z0-9_.:-]+)/'),
                'routes' => $this->matches($source, '/route\\([\'\"]([^\'\"]+)[\'\"]\\)/'),
            ],
            'vue', 'svelte', 'javascript', 'react-javascript', 'typescript', 'react-typescript' => [
                'imports' => array_values(array_unique(array_merge(
                    $this->matches($source, '/\\bimport\\s+(?:[^;\\n]*?\\s+from\\s+)?[\'\"]([^\'\"]+)[\'\"]/'),
                    $this->matches($source, '/\\brequire\\(\\s*[\'\"]([^\'\"]+)[\'\"]\\s*\\)/'),
                    $this->matches($source, '/\\bimport\\(\\s*[\'\"]([^\'\"]+)[\'\"]\\s*\\)/')
                ))),
                'http' => array_values(array_unique(array_merge(
                    $this->matches($source, '/axios\\.(?:get|post|put|patch|delete)\\(\\s*[\\x27"]([^\\x27"]+)[\\x27"]/i'),
                    $this->matches($source, '/fetch\\(\\s*[\\x27"]([^\\x27"]+)[\\x27"]/i'),
                    $this->matches($source, '/(?:router|Inertia|form)\\.(?:get|post|put|patch|delete|visit)\\(\\s*[\\x27"]([^\\x27"]+)[\\x27"]/i')
                ))),
                'http_calls' => $this->httpCalls($source),
                'routes' => $this->matches($source, '/\\broute\\(\\s*[\\x27"]([^\\x27"]+)[\\x27"]/i'),
            ],
            default => [],
        };
    }


    /**
     * Capture frontend HTTP calls with their method and source line while
     * retaining the older flat `http` reference list for compatibility.
     *
     * @return array<int, array{method:string,url:string,line:int}>
     */
    private function httpCalls(string $source): array
    {
        $calls = [];

        if (preg_match_all('/axios\\.(get|post|put|patch|delete)\\(\\s*[\'\"]([^\'\"]+)[\'\"]/i', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $offset = (int) ($match[0][1] ?? 0);
                $calls[] = [
                    'method' => strtoupper((string) ($match[1][0] ?? 'GET')),
                    'url' => (string) ($match[2][0] ?? ''),
                    'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                ];
            }
        }

        if (preg_match_all('/(?:router|Inertia|form)\\.(get|post|put|patch|delete|visit)\\(\\s*[\\x27"]([^\\x27"]+)[\\x27"]/i', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $offset = (int) ($match[0][1] ?? 0);
                $rawMethod = strtoupper((string) ($match[1][0] ?? 'GET'));
                $calls[] = [
                    'method' => $rawMethod === 'VISIT' ? 'GET' : $rawMethod,
                    'url' => (string) ($match[2][0] ?? ''),
                    'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                ];
            }
        }

        if (preg_match_all('/fetch\\(\\s*[\'\"]([^\'\"]+)[\'\"]\\s*(?:,\\s*\\{(.*?)\\})?/is', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $offset = (int) ($match[0][1] ?? 0);
                $options = (string) ($match[2][0] ?? '');
                $method = 'GET';
                if ($options !== '' && preg_match('/\\bmethod\\s*:\\s*[\'\"]([A-Z]+)[\'\"]/i', $options, $methodMatch)) {
                    $method = strtoupper((string) $methodMatch[1]);
                }
                $calls[] = [
                    'method' => $method,
                    'url' => (string) ($match[1][0] ?? ''),
                    'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                ];
            }
        }

        return array_values(array_unique($calls, SORT_REGULAR));
    }

    /** @return array<int, string> */
    private function matches(string $source, string $pattern): array
    {
        preg_match_all($pattern, $source, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    private function source(ProjectContext $context, string $source): ?string
    {
        if (! $context->includeSource || ! ($context->config['include_source'] ?? true)) {
            return null;
        }

        $max = (int) ($context->config['max_source_bytes'] ?? 0);
        if ($max > 0 && strlen($source) > $max) {
            return null;
        }

        return $source;
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
