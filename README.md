# Laravel Project Docs

**Release candidate: v0.9.4**

Laravel Project Docs is a static, read-only documentation and code-review package for Laravel applications. It scans an existing project and generates navigable **HTML, PDF and JSON** documentation with architecture, routes, models, database structure, frontend/backend links, quality findings and source code.

The package does **not** run PHPUnit/Pest or intentionally execute your controllers, services, models, jobs, events, mail, migrations or application business methods during analysis.

## What it generates

A normal build can include:

- project overview and detected Laravel/PHP/frontend stack;
- clickable contents/navigation;
- routes and route-to-code links;
- controllers, services, models, requests, jobs, events, listeners, mail, notifications, policies, commands, middleware, providers and observers;
- Eloquent metadata and relationships;
- migrations, tables, columns, indexes and foreign keys;
- ERD information;
- route → request → controller → service → model → database workflows;
- frontend → backend mappings for common Axios/fetch/Inertia patterns;
- Vue, React, JavaScript, TypeScript, Blade and Svelte source discovery;
- static code-quality/risk findings;
- exact highlighted problem lines with two-way finding/source navigation;
- complete IDE-style source appendix;
- clickable **Back to top / Navigation** links throughout the PDF.

A compact `--quality` mode is also available when you only want the code-review report.

## Supported versions

- PHP: `8.2`, `8.3`, `8.4`, `8.5`
- Laravel components: `11`, `12`, `13`
- PHP Parser: `^5.7`
- Dompdf: `^3.1`

## Package name vs project/report name

These are two different things.

### Composer package name

The Composer package is always:

```text
tuckbloor/laravel-project-docs
```

You **do not rename the package for each Laravel application**.

Every project installs the same package:

```bash
composer require tuckbloor/laravel-project-docs
```

### Project/report display name

The generated report automatically tries to use the name of the Laravel application it is installed into.

The name is resolved in this order:

1. `project_name` in `config/project-docs.php` when explicitly set;
2. Laravel's configured `app.name` (normally your application's `APP_NAME`);
3. `package.json` project name;
4. the host application's `composer.json` name;
5. a safe non-generic project directory name;
6. `Laravel Project` as the final fallback.

Generic Docker/framework names such as `/var/www/html`, `html`, `www`, `app`, `Laravel` and `laravel/laravel` are ignored as report names.

For most projects, simply set the normal Laravel application name:

```dotenv
APP_NAME="Factory Maintenance"
```

If you specifically want a different documentation title, publish the package config and set:

```php
'project_name' => 'Factory Maintenance',
```

That setting belongs to the **host Laravel project**, not this reusable package repository.

## Installation

Once the package is available through Packagist:

```bash
composer require tuckbloor/laravel-project-docs
```

Laravel package discovery registers the service provider and Artisan command automatically.

Confirm the command is available:

```bash
php artisan project-docs:generate --help
```

### Docker projects

Run Composer and Artisan inside the PHP/application container when that is where the Laravel application's supported PHP version and extensions live.

For a Docker Compose service called `app`:

```powershell
docker compose exec app composer require tuckbloor/laravel-project-docs
docker compose exec app php artisan project-docs:generate --help
```

## Quick command reference

| Goal | Command |
|---|---|
| Full HTML/PDF/JSON documentation | `php artisan project-docs:generate` |
| Quality-only HTML/PDF/JSON report | `php artisan project-docs:generate --quality` |
| Full PDF only | `php artisan project-docs:generate --format=pdf` |
| Quality PDF only | `php artisan project-docs:generate --quality --format=pdf` |
| Full HTML only | `php artisan project-docs:generate --format=html` |
| Full JSON only | `php artisan project-docs:generate --format=json` |
| Several selected formats | `php artisan project-docs:generate --format=html --format=json` |
| Full documentation without normal source appendix | `php artisan project-docs:generate --no-source` |
| Deliberately include `.env` in the full report | `php artisan project-docs:generate --include-env` |
| Quality report with environment-file checks enabled | `php artisan project-docs:generate --quality --include-env` |
| Custom output directory | `php artisan project-docs:generate --path=storage/my-docs` |

All options can be inspected with:

```bash
php artisan project-docs:generate --help
```

## Generate the full developer documentation

Run:

```bash
php artisan project-docs:generate
```

Docker example:

```powershell
docker compose exec app php artisan project-docs:generate
```

Default output:

```text
storage/project-docs/
├── project-documentation.html
├── project-documentation.pdf
└── project-documentation.json
```

The normal full report contains the architecture/manual sections and complete source appendix unless `--no-source` is supplied.

### Generate one format only

PDF:

```bash
php artisan project-docs:generate --format=pdf
```

HTML:

```bash
php artisan project-docs:generate --format=html
```

JSON:

```bash
php artisan project-docs:generate --format=json
```

Multiple selected formats:

```bash
php artisan project-docs:generate --format=html --format=json
```

### Generate without the normal source appendix

```bash
php artisan project-docs:generate --no-source
```

This is useful when you want the architecture/documentation sections but do not want the normal complete application source appendix.

`--no-source` does **not** cancel an explicit `--include-env` request. If you explicitly request `.env`, treat the generated output as sensitive.

## Generate only the quality report

For a compact static review without the full architecture manual/source appendix:

```bash
php artisan project-docs:generate --quality
```

Docker:

```powershell
docker compose exec app php artisan project-docs:generate --quality
```

Default quality-only output:

```text
storage/project-docs/
├── project-quality-report.html
├── project-quality-report.pdf
└── project-quality-report.json
```

The focused report contains:

- review score;
- Critical / High / Medium / Low totals;
- application-owned-code quality scope;
- static findings and explanations;
- exact file and line locations;
- prominent source links;
- small syntax-highlighted code excerpts;
- highlighted problem lines;
- scanner warnings;
- explicit confirmation that tests executed = `0`.

It intentionally omits the full architecture handbook, class index, route manual and complete source appendix.

The quality HTML uses a wide review layout and the quality PDF uses **A4 landscape** so code excerpts have room to breathe.

### Quality PDF only

```bash
php artisan project-docs:generate --quality --format=pdf
```

### Quality HTML only

```bash
php artisan project-docs:generate --quality --format=html
```

## `.env` handling and `--include-env`

### Default safe behaviour

Normal generation does **not** read or embed the host application's `.env` values.

```bash
php artisan project-docs:generate
```

The root `.env` is blocked from normal file discovery and cannot be enabled permanently through package config.

Normal safe generation also does not penalise the project simply because `.env` or `.env.example` is not included in the documentation.

### Deliberately include `.env` in the full documentation

Only do this when you intentionally need the complete environment file in a private/internal report:

```bash
php artisan project-docs:generate --include-env
```

Docker:

```powershell
docker compose exec app php artisan project-docs:generate --include-env
```

The generated full report is prominently marked:

```text
SENSITIVE DOCUMENT — .env INCLUDED
```

The output may contain database passwords, API keys, tokens, mail credentials and other secrets.

**Do not commit, publish, email broadly or place an `--include-env` report in Laravel's `public/` directory.**

### Quality mode + environment checks

You can enable environment-file quality checks in the focused quality report:

```bash
php artisan project-docs:generate --quality --include-env
```

In quality-only mode, `.env` **values are not embedded into the quality report**. The flag enables environment-file presence/coverage review without turning the focused report into a secret-bearing source appendix.

## Output location

Default:

```text
storage/project-docs
```

Override it for one run:

```bash
php artisan project-docs:generate --path=storage/internal/developer-docs
```

Keep generated documentation outside Laravel's `public/` directory, especially when using `--include-env`.

## Configuration

The package works without publishing its config.

Publish it only when you need project-specific paths, output settings, a report-name override or quality exclusions:

```bash
php artisan vendor:publish --tag=project-docs-config
```

This creates:

```text
config/project-docs.php
```

Important options include:

```php
return [
    // null = automatically detect the host Laravel application's name.
    'project_name' => null,

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

    'output_path' => 'storage/project-docs',

    'formats' => ['html', 'pdf', 'json'],

    'include_source' => true,

    'max_source_bytes' => 0,

    'quality' => [
        'exclude_paths' => [
            // Document these files, but do not score them as application-owned code.
        ],
    ],
];
```

### Quality exclusions

Documentation discovery and quality analysis deliberately have different scopes.

A file can remain visible in the documentation/source appendix while being excluded from the quality score:

```php
'quality' => [
    'exclude_paths' => [
        'app/Generated/**',
        'app/Legacy/ThirdParty/**',
    ],
],
```

The default configuration already excludes common Laravel/framework/starter scaffolding from quality findings where appropriate.

## Static/read-only analysis

The package analyses source files. It does not intentionally:

- call controller/service/model methods;
- execute project tests;
- issue database queries through application code;
- dispatch jobs/events;
- send mail/notifications;
- call external HTTP APIs;
- run migrations.

Artisan itself still boots Laravel normally in order to register/run the command.

Generated reports explicitly state **STATIC / READ-ONLY ANALYSIS** and **NO TESTS RUN**.

## Quality analysis philosophy

The quality report is intended to identify useful review signals without drowning developers in framework false positives.

It understands or deliberately accounts for common Laravel behaviour including:

- framework/starter files excluded from application-owned quality scope;
- Eloquent/framework inheritance;
- framework-managed public/protected methods and configuration properties;
- closure/arrow-function parameters and local variable scope;
- Auth-driven actions for generic validation heuristics;
- Laravel's dynamic behaviour where a static unused-code claim would be unreliable.

Quality findings can include parser errors, genuine local variable issues, unused-code candidates, query-in-loop/N+1 patterns, risky request mass assignment, raw SQL review signals, direct `env()` outside config, superglobals, possible hard-coded credentials, process execution, error-handling issues, complexity, duplicates, dependency cycles and debug/TODO markers.

A quality score is a **static review aid**, not proof that an application is correct or broken.

## Frontend discovery

Common frontend files are discovered when present, including:

```text
.blade.php
.vue
.svelte
.js
.jsx
.mjs
.cjs
.ts
.tsx
```

Common locations include:

```text
resources/views
resources/js
resources/ts
resources/react
resources/vue
resources/frontend
frontend/src
src
```

Frontend/tooling detection can identify packages such as React, Vue, Inertia, TypeScript, Vite, Alpine.js, Svelte, Tailwind CSS and Livewire.

## PDF behaviour on large projects

The manual/intelligence pages are laid out through Dompdf, while the complete source appendix is appended file-by-file to the PDF canvas.

This avoids asking Dompdf's HTML layout engine to hold an entire large application's syntax-highlighted source tree in memory at once.

The result remains one final PDF with line numbers, syntax colouring, source links, problem-line highlighting and per-page navigation.

## Updating

Update through Composer:

```bash
composer update tuckbloor/laravel-project-docs
```

Docker:

```powershell
docker compose exec app composer update tuckbloor/laravel-project-docs
```

After an update, if your application caches configuration/services, it is safe to clear Laravel caches:

```bash
php artisan optimize:clear
```

Docker:

```powershell
docker compose exec app php artisan optimize:clear
```

## Local package development

For development before/without Packagist, place the package inside a Laravel project, for example:

```text
my-laravel-app/
├── app/
├── packages/
│   └── laravel-project-docs/
└── composer.json
```

Add a path repository to the host application's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "packages/laravel-project-docs",
        "options": {
            "symlink": true
        }
    }
]
```

Then install the local package:

```bash
composer require tuckbloor/laravel-project-docs:@dev -W
```

Docker:

```powershell
docker compose exec app composer require tuckbloor/laravel-project-docs:@dev -W
```

After replacing a local build:

```powershell
docker compose exec app composer dump-autoload
docker compose exec app php artisan optimize:clear
```

## Troubleshooting

### The report has the wrong project name

First check the host Laravel project's normal application name:

```dotenv
APP_NAME="My Application"
```

Then clear cached config if required:

```bash
php artisan optimize:clear
```

If you deliberately want a different documentation title, publish `config/project-docs.php` and set `project_name`.

### Artisan works in Docker but not Windows/local PHP

Run the command in the same environment that normally runs your Laravel app:

```powershell
docker compose exec app php artisan project-docs:generate
```

This avoids host-PHP version/extension differences.

### A file should be documented but not quality-scored

Add it to:

```php
'quality.exclude_paths'
```

Do not add it to the global `exclude` list unless you also want it omitted from documentation discovery.

## Security

Generated documentation can expose application structure and source code. Treat it as internal engineering material unless you deliberately intend to publish it.

Reports generated with `--include-env` must be treated as secrets.

See [SECURITY.md](SECURITY.md) for reporting package security issues.

## Contributing

Issues and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Maintainer release notes

Repository/Packagist publishing steps are kept out of the user instructions above and documented separately in [PUBLISHING.md](PUBLISHING.md).

## License

MIT. See [LICENSE](LICENSE).
