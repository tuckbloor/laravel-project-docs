# Security Policy

## Generated documentation

Laravel Project Docs can intentionally include application source code. Generated reports should normally be treated as internal engineering material.

The `.env` file is excluded by default. If `--include-env` is deliberately used for a full report, the generated documentation may contain live credentials and must be handled as a secret.

Do not commit generated secret-bearing reports to a public repository.

## Reporting a package vulnerability

Please avoid publishing exploit details, credentials or sensitive application information in a public issue.

Use GitHub's private vulnerability reporting for `tuckbloor/laravel-project-docs` when available. If private reporting is not available, open a non-sensitive issue asking the maintainer for a private contact channel without including exploit details.
