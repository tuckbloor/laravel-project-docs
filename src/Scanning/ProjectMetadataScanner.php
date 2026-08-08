<?php

namespace DevDocs\LaravelProjectDocs\Scanning;

use DevDocs\LaravelProjectDocs\Contracts\Scanner;
use DevDocs\LaravelProjectDocs\Data\ProjectContext;

class ProjectMetadataScanner implements Scanner
{
    public function key(): string
    {
        return 'project';
    }

    public function scan(ProjectContext $context): array
    {
        $composer = $this->json($context->path('composer.json'));
        $package = $this->json($context->path('package.json'));
        $lock = $this->json($context->path('composer.lock'));

        return [
            'composer' => [
                'name' => $composer['name'] ?? null,
                'require' => $composer['require'] ?? [],
                'require_dev' => $composer['require-dev'] ?? [],
            ],
            'laravel' => [
                'version' => $this->lockedPackageVersion($lock, 'laravel/framework') ?? (($composer['require']['laravel/framework'] ?? null)),
            ],
            'npm' => [
                'name' => $package['name'] ?? null,
                'dependencies' => $package['dependencies'] ?? [],
                'dev_dependencies' => $package['devDependencies'] ?? [],
            ],
            'frontend_stack' => $this->frontendStack($composer, $package),
            'environment' => $this->environmentKeys($context),
            'environment_example' => $this->environmentExample($context),
            'env_file' => $this->environmentFile($context),
            'quality_scope' => [
                'mode' => 'application-owned',
                'exclude_paths' => array_values((array) ($context->config['quality']['exclude_paths'] ?? [])),
            ],
            'git' => $this->git($context),
        ];
    }


    /**
     * Detect the frontend/tooling stack from package metadata without executing
     * Node, Vite or application code.
     *
     * @return array{detected:array<int,string>,items:array<int,array{name:string,package:string,version:string,source:string}>}
     */
    private function frontendStack(array $composer, array $package): array
    {
        $npm = array_merge(
            (array) ($package['dependencies'] ?? []),
            (array) ($package['devDependencies'] ?? []),
        );
        $php = array_merge(
            (array) ($composer['require'] ?? []),
            (array) ($composer['require-dev'] ?? []),
        );

        $definitions = [
            ['package' => 'react', 'name' => 'React', 'source' => 'npm'],
            ['package' => 'vue', 'name' => 'Vue', 'source' => 'npm'],
            ['package' => '@inertiajs/react', 'name' => 'Inertia + React', 'source' => 'npm'],
            ['package' => '@inertiajs/vue3', 'name' => 'Inertia + Vue', 'source' => 'npm'],
            ['package' => '@inertiajs/core', 'name' => 'Inertia', 'source' => 'npm'],
            ['package' => 'typescript', 'name' => 'TypeScript', 'source' => 'npm'],
            ['package' => 'vite', 'name' => 'Vite', 'source' => 'npm'],
            ['package' => 'alpinejs', 'name' => 'Alpine.js', 'source' => 'npm'],
            ['package' => 'svelte', 'name' => 'Svelte', 'source' => 'npm'],
            ['package' => 'tailwindcss', 'name' => 'Tailwind CSS', 'source' => 'npm'],
            ['package' => 'livewire/livewire', 'name' => 'Livewire', 'source' => 'composer'],
        ];

        $items = [];
        foreach ($definitions as $definition) {
            $packages = $definition['source'] === 'composer' ? $php : $npm;
            $packageName = $definition['package'];
            if (! array_key_exists($packageName, $packages)) {
                continue;
            }
            $items[] = [
                'name' => $definition['name'],
                'package' => $packageName,
                'version' => (string) $packages[$packageName],
                'source' => $definition['source'],
            ];
        }

        // Prefer the more descriptive Inertia + framework label over a duplicate
        // plain framework-only presentation, while retaining both package facts.
        $detected = [];
        foreach ($items as $item) {
            $detected[] = (string) $item['name'];
        }

        return [
            'detected' => array_values(array_unique($detected)),
            'items' => $items,
        ];
    }


    private function lockedPackageVersion(array $lock, string $package): ?string
    {
        foreach (array_merge((array) ($lock['packages'] ?? []), (array) ($lock['packages-dev'] ?? [])) as $item) {
            if ((string) ($item['name'] ?? '') === $package) {
                return (string) ($item['version'] ?? '');
            }
        }
        return null;
    }

    private function json(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }
        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<int, array{key:string,files:array<int,string>}> */
    private function environmentKeys(ProjectContext $context): array
    {
        $files = glob($context->path('config').'/*.php') ?: [];
        $keys = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }
            preg_match_all('/\benv\(\s*[\'\"]([A-Z0-9_]+)[\'\"]/i', $source, $matches);
            foreach (array_unique($matches[1] ?? []) as $key) {
                $keys[$key] ??= [];
                $keys[$key][] = 'config/'.basename($file);
            }
        }

        ksort($keys);
        $result = [];
        foreach ($keys as $key => $foundIn) {
            $result[] = ['key' => $key, 'files' => array_values(array_unique($foundIn))];
        }
        return $result;
    }



    /** @return array<string,mixed> */
    private function environmentExample(ProjectContext $context): array
    {
        $path = $context->path('.env.example');
        $keys = [];
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                    continue;
                }
                [$key] = explode('=', $line, 2);
                $key = trim($key);
                if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                    $keys[] = $key;
                }
            }
        }

        $required = array_map(static fn (array $item): string => (string) ($item['key'] ?? ''), $this->environmentKeys($context));
        $missing = array_values(array_diff(array_filter($required), $keys));
        sort($keys);
        sort($missing);

        return [
            'exists' => is_file($path),
            'path' => '.env.example',
            'keys' => array_values(array_unique($keys)),
            'missing_required_keys' => $missing,
        ];
    }

    /**
     * Inspect the presence of .env without reading it in normal mode. In a full
     * documentation build, explicit --include-env stores the contents verbatim for
     * the requested environment appendix. In focused --quality mode, --include-env
     * enables environment checks but deliberately does not read/embed raw values.
     *
     * @return array{requested:bool,exists:bool,included:bool,path:string,lines:int,bytes:int,source:?string}
     */
    private function environmentFile(ProjectContext $context): array
    {
        $requested = (bool) ($context->config['include_env'] ?? false);
        $path = $context->path('.env');
        $exists = is_file($path);
        $source = null;

        $qualityOnly = (($context->config['report_mode'] ?? 'full') === 'quality');

        if ($requested && $exists && ! $qualityOnly) {
            $contents = file_get_contents($path);
            if ($contents !== false) {
                $source = $contents;
            }
        }

        $normalised = $source !== null
            ? str_replace(["\r\n", "\r"], "\n", $source)
            : '';

        return [
            'requested' => $requested,
            'exists' => $exists,
            'included' => $requested && $exists && $source !== null,
            'path' => '.env',
            'lines' => $source !== null ? substr_count($normalised, "\n") + 1 : 0,
            'bytes' => $source !== null ? strlen($source) : 0,
            'source' => $source,
        ];
    }

    /** @return array{branch:?string,commit:?string} */
    private function git(ProjectContext $context): array
    {
        $git = $context->path('.git');
        $head = $git.DIRECTORY_SEPARATOR.'HEAD';
        if (! is_file($head)) {
            return ['branch' => null, 'commit' => null];
        }

        $headValue = trim((string) file_get_contents($head));
        if (str_starts_with($headValue, 'ref: ')) {
            $ref = substr($headValue, 5);
            $branch = basename($ref);
            $refPath = $git.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $ref);
            $commit = is_file($refPath) ? trim((string) file_get_contents($refPath)) : $this->packedRef($git, $ref);
            return ['branch' => $branch, 'commit' => $commit !== '' ? substr($commit, 0, 12) : null];
        }

        return ['branch' => null, 'commit' => $headValue !== '' ? substr($headValue, 0, 12) : null];
    }

    private function packedRef(string $git, string $ref): string
    {
        $packed = $git.DIRECTORY_SEPARATOR.'packed-refs';
        if (! is_file($packed)) {
            return '';
        }
        foreach (file($packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with($line, '#') || str_starts_with($line, '^')) {
                continue;
            }
            [$hash, $name] = array_pad(explode(' ', trim($line), 2), 2, '');
            if ($name === $ref) {
                return $hash;
            }
        }
        return '';
    }
}
