# Contributing

Thank you for helping improve Laravel User Presence. Focus contributions on a clear bug fix or a well-scoped feature.

## Prerequisites

- PHP 8.2 or later for Laravel 12; PHP 8.3 or later for Laravel 13
- Composer
- PHP SQLite support for the in-memory test database

## Local setup

```bash
composer install
```

Run the package tests and formatter with the repository scripts:

```bash
composer test
composer format
```

## Changes and pull requests

- Keep changes consistent with the existing package structure and Laravel conventions.
- Add or update tests for behavior changes.
- Update the README when a public option, event, or setup step changes.
- Use a descriptive branch name and commit message.
- Open a pull request against the default branch, explain the problem and solution, and include the test results.
- Call out breaking changes and any migration or configuration steps required by the change.

For questions or proposals, open a [GitHub issue](https://github.com/EvanSchleret/laravel-user-presence/issues) before starting a large change.
