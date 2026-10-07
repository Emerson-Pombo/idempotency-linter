# Contributing

Thanks for wanting to help. Issues and pull requests are welcome.

## Setting up

Requires PHP 8.1 or higher, the `dom`, `mbstring`, `tokenizer` and `xml` extensions, and Composer.

```bash
git clone https://github.com/Emerson-Pombo/idempotency-linter.git
cd idempotency-linter
composer install
composer check
```

`composer check` runs the code style check (Pint), static analysis (PHPStan, level 6) and the tests. CI runs the same, plus the PHP 8.1 to 8.3 × Laravel 10 to 13 matrix.

| Command | What it does |
|---|---|
| `composer test` | PHPUnit |
| `composer lint` | checks the code style without changing files |
| `composer format` | applies the code style (Pint, `laravel` preset) |
| `composer analyse` | PHPStan |

## How we work

- **Test first.** Every behavior change starts with a failing test. The analysis tests use PHP code fixtures created on the fly (see `tests/TestCase.php`); supporting classes live in `tests/Fixtures/`.
- **Sinks and guards** are data, not code: they live in `config/idempotency-linter.php`. To support a new gateway, a new facade or a new call chain, start with the catalog.
- **A silent false negative is the worst kind of defect.** If a new limitation shows up, document it in the README and pin it in `tests/Unit/Analysis/LimitationsTest.php`.
- **Never run the analyzed code.** The analysis is static. The only exception is the autoloading used to resolve class hierarchy (`ClassMatcher`, `ClassLocator`).
- **Everything is written in English:** user-facing messages, code comments, documentation, commit messages and pull request descriptions.

## Pull requests

- One topic per PR, with small, descriptive commits (imperative mood).
- Describe what changes, the points that need attention and how to test it.
- Update `CHANGELOG.md` under the next version and the README when visible behavior changes.
- CI must be green.

## Releasing a version (maintainers)

1. Make sure `main` is green and `CHANGELOG.md` is complete.
2. Replace "Unreleased" with the version and date in `CHANGELOG.md`.
3. Create and push the tag: `git tag -a v0.2.0 -m "v0.2.0" && git push origin v0.2.0`.
4. Create the GitHub release from the tag, using the changelog section as its text.
5. Packagist picks up the new tag through the GitHub webhook. For the very first version, submit the repository at <https://packagist.org/packages/submit> and enable auto-updating.
