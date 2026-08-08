# Contributing

Thanks for helping improve Laravel Project Docs.

## Principles

The package is intended to be useful on real Laravel applications without turning the report into a wall of speculative warnings.

Changes should preserve these principles:

- analysis is static/read-only;
- `project-docs:generate` never runs the host project's PHPUnit/Pest suite;
- the analyser should not intentionally invoke application business methods;
- Laravel/framework behaviour and inheritance should not be reported as defects without strong evidence;
- quality rules should prefer high-value signals over volume;
- full documentation should remain navigable on large projects;
- `.env` remains excluded by default.

## Pull requests

Before opening a pull request:

```bash
composer validate --strict
```

Lint package PHP files and ensure the package can generate documentation in a representative Laravel project.

For quality-rule changes, include a small example of both:

1. code that should create a finding; and
2. code that must not create a false positive.

## Bug reports

Useful bug reports include:

- Laravel version;
- PHP version;
- frontend stack if relevant;
- exact generation command;
- the incorrect finding/error;
- a minimal code example when possible.

Never post `.env` values, passwords, tokens, API keys or other credentials in an issue.
