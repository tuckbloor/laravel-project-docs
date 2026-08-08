<?php

return [
    /*
     * Optional display name override for the generated report. When null, the
     * package prefers Laravel's configured app.name and then package metadata.
     */
    'project_name' => null,

    /*
    |--------------------------------------------------------------------------
    | Project paths
    |--------------------------------------------------------------------------
    |
    | Paths are relative to the host Laravel application's base path.
    |
    */
    'include' => [
        'app',
        'routes',
        'resources/views',
        'resources/js',
        'resources/ts',
        'resources/react',
        'resources/vue',
        'resources/frontend',
        'frontend/src',
        'src',
        'database/migrations',
        'config',
    ],

    'exclude' => [
        'vendor',
        'node_modules',
        'storage',
        'bootstrap/cache',
        '.git',
    ],

    /*
     * Never scan secrets through normal discovery, even when an include path is
     * broadened. The root .env file can only be read by the explicit --include-env CLI
     * option; there is intentionally no persistent include_env config switch.
     */
    'blocked_files' => [
        '.env',
        '.env.*',
        'auth.json',
    ],

    'output_path' => 'storage/project-docs',

    'formats' => ['html', 'pdf', 'json'],

    'include_source' => true,

    /*
     * 0 means unlimited. The documentation package is intended to include the
     * complete application source. Set a positive byte limit only when you
     * explicitly want oversized files omitted. Omitted files are reported.
     */
    'max_source_bytes' => 0,

    /*
     * Quality analysis is deliberately narrower than documentation discovery.
     * These files remain visible in the generated documentation/source appendix,
     * but they do not create quality findings or affect the review score.
     *
     * The defaults exclude Laravel's project skeleton and common official starter
     * kit scaffolding. Add project-specific generated/vendor-like paths here when
     * they should be documented but not reviewed as code your team owns.
     */
    'quality' => [
        'exclude_paths' => [
            'vendor/**',
            'node_modules/**',
            'storage/**',
            'bootstrap/cache/**',
            'public/build/**',

            // Laravel application skeleton.
            'app/Http/Controllers/Controller.php',
            'app/Providers/AppServiceProvider.php',
            'app/Models/User.php',
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/0001_01_01_000001_create_cache_table.php',
            'database/migrations/0001_01_01_000002_create_jobs_table.php',

            // Common Laravel Breeze / official starter-kit scaffolding.
            'app/Http/Controllers/Auth/**',
            'app/Http/Controllers/ProfileController.php',
            'app/Http/Requests/ProfileUpdateRequest.php',
            'routes/auth.php',
            'resources/views/auth/**',
            'resources/views/profile/**',
            'resources/js/Pages/Auth/**',
            'resources/js/Pages/Profile/**',
        ],
    ],

    'frontend' => [
        'extensions' => ['blade.php', 'vue', 'svelte', 'js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx'],
    ],
];
