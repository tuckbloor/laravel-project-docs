# Changelog

## 0.9.4 - Public package preparation

### Changed

- Composer package identity changed from the pre-release `devdocs/laravel-project-docs` name to `tuckbloor/laravel-project-docs` for the first public GitHub/Packagist release.
- Added public Composer author, homepage, source and issue-tracker metadata for `github.com/tuckbloor/laravel-project-docs`.
- Reworked the README around installation and day-to-day usage rather than development history.
- Documented the difference between the fixed Composer package name and the host Laravel application's automatically detected report display name.
- Added a complete command reference for full docs, quality-only mode, format selection, `--no-source`, `--path` and Docker usage.
- Expanded `.env` safety/inclusion instructions.
- In `--quality --include-env` mode, raw `.env` values are no longer read/embedded; the flag enables environment-file quality checks only. Full `--include-env` reports retain deliberate verbatim `.env` inclusion.
- Removed project-specific local-development naming from the public README.

### Added

- `PUBLISHING.md` with the maintainer's GitHub → tag → Packagist release workflow.
- `CONTRIBUTING.md`.
- `SECURITY.md`.
- GitHub bug-report and feature-request issue forms.

## 0.9.3 - Wider focused quality report

- Expanded the `--quality` HTML layout from a narrow 1080px column to a wide 1560px review surface.
- Quality finding cards and code excerpts now use the full available width.
- Browser code excerpts preserve long code lines with horizontal scrolling.
- Quality-only PDF output now uses A4 landscape with tighter margins for significantly wider code review.
- Full documentation PDF output remains A4 portrait.
- Preserved quality-card page-break protection, source links and highlighted problem lines.

## 0.9.2 - Focused quality-only report

### Added

- New `project-docs:generate --quality` flag for a compact quality-only HTML/PDF/JSON report.
- Quality-only output uses dedicated filenames: `project-quality-report.html`, `.pdf` and `.json`, so it does not overwrite the full developer documentation.
- Focused HTML/PDF reports include score, severity summary, application-owned quality scope, findings and short syntax-highlighted problem-code excerpts.
- Problem lines remain severity-highlighted; long ranges are deliberately truncated in the excerpt to avoid bloating the focused report.
- Quality-only JSON contains metadata, the canonical quality report, quality coverage and scanner warnings rather than the full project documentation tree.
- Full report behaviour remains unchanged when `--quality` is not supplied.


## 0.9.1 - Docker project-name fix

### Fixed

- Containerised Laravel projects mounted at paths such as `/var/www/html` no longer show `html` as the report title.
- Project-name resolution now prefers an explicit `project-docs.project_name`, then Laravel's configured `app.name`, then `package.json` / `composer.json` metadata, and only then a non-generic directory name.
- Generic container/framework names such as `html`, `www`, `app`, `Laravel` and `laravel/laravel` are ignored as fallbacks.
- The corrected project name is shared by both HTML and PDF output.
- No `.env` file is read directly for this feature; Artisan passes the already-loaded Laravel `app.name` configuration value to the static analyser.

## 0.9.0 - First release candidate

### Added

- Compact project overview with frontend-stack auto-detection.
- Detected frontend/tooling packages include React, Vue, Inertia, TypeScript, Vite, Alpine.js, Svelte, Tailwind CSS and Livewire where present.
- Broader common frontend source discovery for JSX/TSX/MJS/CJS/Svelte and common Laravel frontend directories.
- Analysis coverage data in HTML/PDF/JSON: PHP structural parse coverage, source inclusion, frontend read coverage, route errors, migration errors, quality scope and scanner warnings.
- Compact `Needs attention` summary capped at ten findings and linked to the canonical full Quality entries.
- Dedicated clickable Contents & Navigation page.
- Error / Warning / Observation review grouping while retaining original severity and confidence.
- Frontend read errors are now recorded rather than silently skipped.
- Console output includes detected frontend stack and coverage counts.

### Release policy

- No new speculative quality rules were added in this release candidate.
- Existing PHP, route and migration parse failures remain recoverable; raw/source documentation continues where possible.
- Analysis remains static/read-only and does not run host-project tests or application business methods.


## 0.8.8 - Clearer source links and improved PDF pagination

### Improved

- All direct source-code jumps are visually stronger in HTML and PDF with explicit SOURCE / VIEW SOURCE CODE labels.
- Class index locations, class source ranges, method source jumps, relationship/file source links and quality finding source links are easier to identify at a glance.
- The PDF Code Quality & Risk Analysis section starts on a fresh page.
- Quality finding cards use `page-break-inside: avoid` / `break-inside: avoid` so normal-sized warnings stay together on one page instead of being split across page boundaries.
- PDF quality cards now have clearer severity styling and spacing.
- Oversized findings remain allowed to flow if a single card cannot physically fit on one A4 page.

### Unchanged

- Quality findings still link to exact highlighted source lines and source severity badges link back to findings.
- Source code remains fully included and static analysis remains read-only.
- Back-to-top and Navigation links remain on every PDF page.

## 0.8.7 - Quality findings highlighted in source

### Added

- Quality findings now visually highlight the exact affected source line in the HTML source viewer.
- Severity-aware source highlighting: Critical/High use red-orange emphasis, Medium uses amber, and Low uses blue.
- Problem lines include an `!` gutter marker and a compact severity badge.
- Quality findings receive stable IDs such as `Q001`, making findings easier to reference during code review.
- Clicking `View source` on a quality finding jumps to the highlighted source line.
- Clicking the severity badge on a highlighted HTML source line jumps back to the corresponding quality finding.
- Multiple findings on the same source line are combined into a single highest-severity marker with an additional finding count.
- The streamed PDF source appendix now receives the same severity-aware line highlighting without returning source rendering to Dompdf's memory-heavy HTML layout engine.
- PDF source severity badges link back to their quality finding in the documentation section.
- Finding line ranges are supported when a future/static rule supplies `start_line` / `end_line` metadata; all affected lines are highlighted.

### Unchanged

- Source code remains 100% included.
- Static analysis remains read-only and does not execute application business logic or tests.
- Back-to-top and Navigation links remain available on every PDF page.

## 0.8.6 - Framework inheritance-aware quality analysis

### Fixed

- Eloquent relationship methods are no longer reported as possibly unused merely because Laravel resolves them dynamically through model relationships/properties.
- Public/protected methods on Laravel/framework-managed classes are no longer treated as unused when their caller may be a parent class or framework callback. Private methods remain eligible for unused-method analysis.
- Methods that override a method declared by a scanned application parent class are recognised as inheritance-contract methods and are not reported as unused.
- Public/protected methods declared on a scanned parent class are also treated as inherited API when child classes extend that parent; they are not reported as unused merely because the call happens through inheritance.
- Protected parent properties are not reported as unused when scanned child classes may consume them.
- Protected properties on framework-managed subclasses are no longer reported as unused when they can be consumed by the parent/framework. This covers Eloquent configuration such as `$fillable`, `$casts`, `$table`, command `$signature`, FormRequest redirect settings, queue/job configuration and similar framework state.
- Known framework lifecycle/hook method signatures do not produce unused-parameter noise solely because a parameter is required by the framework contract.
- Local method-body analysis remains active: genuine undefined locals, genuinely unused local variables, debug code, complexity and other source-level problems can still be reported.

### Quality principle

- The analyser now distinguishes application-owned local code from behaviour supplied or consumed through inheritance.
- Static analysis remains read-only. Parent/framework classes are not loaded or executed to make this decision.

## 0.8.5 - Scope-aware variable analysis

### Fixed

- Arrow-function parameters such as `fn ($video) => ...` are recognised as declared variables and are no longer reported as undefined.
- Anonymous/nested function parameters are also recognised as local declarations.
- Genuine undefined-variable findings now point at the first detected variable read line.
- Multiple `global` and `static` declarations are recognised when building the declared-variable set.
- Method parameters retain the existing unused-parameter check: declared + never read is reportable; declared + used is not.
- No application code or tests are executed; this remains token/source-based static analysis.

## 0.8.4 - Auth-aware validation quality rule

### Changed

- The generic `No obvious request validation detected` finding is no longer emitted for POST/PUT/PATCH controller actions that use Laravel authentication context via `Auth::`, `\Auth::`, the fully-qualified Auth facade, or the `auth()` helper.
- This prevents authentication-driven actions such as subscription toggles from being incorrectly treated as unvalidated request-payload handlers.
- Concrete input-risk checks remain active. For example, direct `$request->all()` mass assignment is still reported even when Auth is also used.
- No application code or tests are executed by this rule; it is source-text/static analysis only.

## 0.8.2 - Less speculative quality noise

### Changed

- Removed the default `No obvious authorization detected for DELETE route` quality finding.
- DELETE actions are no longer penalised simply because the static scanner cannot see a `can` middleware, policy call or Gate check.
- Authorization may legitimately be enforced by route groups, custom middleware, services or application conventions, so absence of an obvious local check is not treated as a quality defect.
- Stronger evidence-based findings such as debug code, parser errors, unused symbols, risky mass assignment, raw SQL, possible N+1 patterns, exception-handling issues and hard-coded credential signals remain unchanged.

## 0.8.1 - Environment quality checks are opt-in

### Fixed

- Normal safe generation no longer adds `.env` / `.env.example` findings to the Code Quality & Risk Analysis section.
- Environment-file quality checks now run only when `--include-env` is explicitly supplied.
- A deliberately excluded `.env` file can no longer lower the review score or appear as an error/warning.
- If `--include-env` is supplied and `.env` is missing, the quality report can explicitly flag that requested file as missing.
- `.env.example` coverage findings are also limited to explicit environment-inclusion mode.

### Unchanged

- Static checks for `env()` calls outside Laravel config remain code-quality findings because they analyse application source usage rather than the presence or contents of `.env`.
- Normal generation still does not read `.env` values.

## 0.8.0 - Static Code Quality & Risk Analysis

### Added

- Dedicated static/read-only analysis banner in HTML and PDF.
- Explicit `NO TESTS RUN` statement in generated documentation.
- Static source-based route scanner; project-docs no longer asks the live Router to resolve controllers or gather middleware.
- Rich quality report with severity, confidence, category, location and clickable source link.
- PHP/migration/route parser-error findings.
- Possibly-unused local variables, parameters, properties, imports, methods, classes and global functions.
- Low-confidence frontend unused variable/import detection.
- Possible undefined-variable signals.
- Possible query-in-loop / N+1 signals.
- Missing-obvious-validation checks for POST/PUT/PATCH controller actions.
- Missing-obvious-authorization checks for DELETE controller actions.
- Direct request-to-mass-assignment detection.
- Raw SQL review signals.
- Direct `env()` outside config and superglobal-access signals.
- Possible hard-coded credential detection without copying the secret value into output.
- Process/dynamic execution API detection (`eval`, `exec`, `shell_exec`, `system`, etc.).
- `unserialize()` review signal.
- Empty/broad/swallowed exception handling signals.
- TODO/FIXME/HACK/XXX and debug statement detection.
- Large class/method, dependency count and complexity signals.
- Duplicate normalised method-body candidates.
- Circular dependency candidates.
- Duplicate route signature/name detection.
- `.env.example` key coverage checks.
- Model-table vs scanned migration mismatch signals.
- Review score plus critical/high/medium/low counts.
- Quality findings added to HTML global search.
- Project Summary now includes a clickable Findings card.

### Safety

- Removed runtime `class_exists()` / `is_subclass_of()` model probing from application intelligence.
- Model detection is source/inheritance/path based only.
- No controller/service/model methods are invoked by quality analysis.
- No tests, database queries, external HTTP calls, jobs, events, mail or migrations are intentionally run by the analyser.

### Navigation

- PDF footer navigation is now added after the entire source appendix is complete, so the same clickable **Back to top / Navigation** links are applied to every PDF page.
- Retained the HTML sidebar, global search, section-end navigation and floating Top button.

### Changed

- Generator metadata reports version 0.8.0 and `static-read-only` analysis mode.
- Console generation output reports static finding count, high/critical count and `Tests executed: 0`.

## 0.7.0 - Project Intelligence and Feature Tracing

- Added deeper route workflows, frontend → backend tracing, ERD data and global call graph.
- Added persistent HTML developer sidebar and global search with Ctrl/Cmd+K.
- Improved route placeholder matching for frontend calls.
- Retained scalable streamed PDF source generation and per-page navigation.

## 0.6.2

- Renamed the package-specific environment inclusion switch from `--env` to `--include-env`.
- Laravel / Symfony already reserves the global `--env` option, so defining another `--env` prevented the Artisan command from registering.
- All HTML/PDF warnings, help text and documentation now reference `--include-env`.
- The environment file remains opt-in only and is never read during a normal documentation build.

## v0.6.1 - Clickable summary and explicit environment-file mode

### Added

- Every Project Summary card is now an internal link to its matching documentation section.
- Added `php artisan project-docs:generate --include-env` to explicitly include the host application's complete `.env` file.
- `.env` is still excluded by default and is only read when `--include-env` is supplied.
- Added a prominent document-level environment status banner:
  - **SENSITIVE DOCUMENT — .env INCLUDED** when secret values are embedded;
  - **.env NOT INCLUDED — SECRET VALUES EXCLUDED** during normal safe generation;
  - a clear warning when `--include-env` was requested but `.env` does not exist.
- Added an Environment-file subsection under Dependencies and Environment.
- HTML, PDF and JSON all report the environment inclusion state.
- PDF source appendix now includes `.env` when `--include-env` is used.
- Added basic `.env` syntax highlighting.

### Security

The `.env` switch is intentionally CLI-only rather than a persistent package-config option. This prevents a committed configuration file from accidentally causing future documentation builds to expose secrets.


## v0.6.0 - Scalable PDF generation

### Changed

- Reworked PDF generation for large Laravel projects.
- Dompdf now lays out the developer manual **without the full source code HTML**.
- Complete source code is appended afterwards directly to Dompdf's PDF canvas.
- Source files are yielded through a PHP `Generator` and processed one file at a time.
- The final result remains one `project-documentation.pdf` rather than a folder of PDF fragments.
- Source code keeps the dark IDE-style presentation, line numbers and syntax colours.
- Long source lines wrap safely within the page width.
- Source pages retain clickable `Back to top` and `Navigation` links.
- Class and method source links are backed by named PDF destinations on the relevant source page.
- The HTML output is unchanged and retains its richer line-by-line browser navigation.

### Why

Previous releases still sent thousands of syntax-highlighted source lines through Dompdf's HTML layout engine. On larger projects this could exhaust a 1 GB PHP memory limit in `Dompdf\\Cpdf::addContent()`.

v0.6 separates the two jobs:

1. Dompdf renders the structured manual and intelligence sections.
2. The source appendix is drawn directly onto new PDF pages, one source file at a time.

This dramatically reduces the number of Dompdf frame/layout objects while preserving the complete source in the final PDF.

### Retained from v0.5.7

- PHP-Parser 5 FQCN resolution fixes.
- Workflow fallbacks.
- Model section fallbacks and model counting fixes.
- Full source inclusion.
- Class/method navigation.
- Application intelligence sections.

## v0.5.7

See previous release for workflow and FQCN resolution fixes.
