# Laravel Project Docs - v0.9.4 Release Checklist

v0.9.4 is the public release candidate. The goal is to validate the package on different real Laravel applications before tagging v1.0.0.

## Required project smoke runs

Run `php artisan project-docs:generate` (inside the host project's normal Docker/container environment where applicable) against:

- Laravel + React/Inertia application
- Laravel + Vue application
- Laravel + Blade-focused application

Generation itself must not run PHPUnit/Pest or application business methods.

## Check each generated report

- HTML, PDF and JSON are all created successfully.
- Project Overview shows the correct Laravel/PHP/frontend stack.
- Analysis Coverage matches the discovered files and exposes parser/read failures rather than hiding them.
- Needs Attention contains no more than 10 linked findings and does not duplicate the full Quality section.
- Contents links work in HTML and PDF.
- Quality findings jump to the exact highlighted source line.
- Highlighted source badges navigate back to the matching finding.
- PDF quality cards do not split awkwardly across pages.
- Back to top / Navigation remains clickable on every PDF page, including source appendix pages.
- Complete source is present unless `--no-source` was deliberately supplied.
- Normal generation does not read/embed `.env` and does not score missing `.env`/`.env.example`.
- `--include-env` output is visibly marked sensitive.
- Laravel/framework/starter scaffolding exclusions remain out of the quality score.
- Laravel/framework inheritance does not create false unused-member warnings.

## Package checks

- `composer.json` is valid.
- All package PHP files pass `php -l`.
- README install/generate/quality/env/config/safety instructions match the package.
- Composer identity is `tuckbloor/laravel-project-docs` and GitHub support links point to `tuckbloor/laravel-project-docs`.
- `composer validate --strict` succeeds.
- `--quality --include-env` enables env quality checks without embedding raw `.env` values.
- CHANGELOG has the release entry.
- ZIP/archive contains no generated project documentation, `.env`, credentials, vendor directory or node_modules.

## Promotion to v1.0.0

Promote to v1.0.0 after the three project smoke runs generate cleanly and any remaining false-positive patterns are understood and intentionally handled.
