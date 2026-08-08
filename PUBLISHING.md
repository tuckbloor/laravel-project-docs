# Publishing Laravel Project Docs

Maintainer guide for publishing `tuckbloor/laravel-project-docs` from GitHub to Packagist.

## 1. Create the GitHub repository

Create a public repository under the `tuckbloor` GitHub account named:

```text
laravel-project-docs
```

Repository URL:

```text
https://github.com/tuckbloor/laravel-project-docs
```

Do not upload the release ZIP as the repository contents. Extract/copy the files so `composer.json`, `README.md`, `src/`, `config/`, etc. are at the repository root.

## 2. Validate the package before the first push

From the package root:

```bash
composer validate --strict
```

Lint the package PHP files:

```bash
find src config -name '*.php' -print0 | xargs -0 -n1 php -l
```

On Windows/PowerShell, use the PHP environment/container that normally supports the package.

## 3. First Git push

From the extracted package root:

```bash
git init
git add .
git commit -m "Initial public release candidate"
git branch -M main
git remote add origin https://github.com/tuckbloor/laravel-project-docs.git
git push -u origin main
```

## 4. Create the release tag

For this release candidate:

```bash
git tag -a v0.9.4 -m "Laravel Project Docs v0.9.4"
git push origin v0.9.4
```

Do not add a `version` property to `composer.json`. Composer/Packagist should obtain release versions from Git tags.

## 5. Submit to Packagist

Sign in to Packagist and submit:

```text
https://github.com/tuckbloor/laravel-project-docs
```

The Composer package name declared by this repository is:

```text
tuckbloor/laravel-project-docs
```

After Packagist has indexed the tag, a consumer should be able to run:

```bash
composer require tuckbloor/laravel-project-docs
```

## 6. Smoke-test the public package

Use a separate Laravel project rather than the package repository itself.

Install from Packagist:

```bash
composer require tuckbloor/laravel-project-docs
```

Confirm the command:

```bash
php artisan project-docs:generate --help
```

Generate the normal report:

```bash
php artisan project-docs:generate
```

Generate the focused report:

```bash
php artisan project-docs:generate --quality
```

Check the files under:

```text
storage/project-docs
```

## 7. Before v1.0.0

Run the same release candidate against at least:

- one Laravel + React/Inertia application;
- one Laravel + Vue application;
- one Blade-focused Laravel application.

Prioritise fixing genuine failures and false positives rather than adding speculative new rules.

When those smoke runs are satisfactory:

```bash
git tag -a v1.0.0 -m "Laravel Project Docs v1.0.0"
git push origin v1.0.0
```

## Future releases

Typical flow:

```bash
git checkout main
git pull
# make changes
git add .
git commit -m "Describe the change"
git push origin main
git tag -a v0.9.5 -m "Laravel Project Docs v0.9.5"
git push origin v0.9.5
```

Packagist should then expose the tagged release to Composer users.
