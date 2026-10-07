# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [semantic versioning](https://semver.org/). While the version is `0.x`, the detection catalogs and the output may change between minor versions.

## [Unreleased]

## [0.2.0] - 2026-10-07

### Changed

- **Breaking (output):** the whole project is now in English, for international use. This affects what users see:
  - risk labels: `HIGH RISK`, `MEDIUM RISK` and `LOW RISK`;
  - the text summary: `2 jobs analyzed, 1 at risk, 1 protected.`;
  - the other command messages (invalid options, `Path not found`, `No jobs (ShouldQueue) found.`);
  - the `message` of each finding, in text and JSON, which comes from the sink messages in the config.
- If you published the config (`vendor:publish`), your copy keeps its old sink messages. Update them, or republish the config, to get the English ones.
- Messages from the catalog validation exceptions are in English.

### Added

- The analysis follows the project's code beyond the job class: injected services with a declared concrete type (`$this->service->method()`, `handle()` parameters and static calls to project classes), methods inherited from the parent class (`$this->method()`, `parent::method()` and an inherited `handle()`) and methods of project traits, plus typed properties coming from the parent or from traits.
- A finding points to the real file and line of the sink, and the text output now shows the job that reaches it (`Job: ...`).

### Known limitations

- Dependencies typed by interface, services obtained from the container (`app(...)`) and classes from `vendor/` are not followed.

## [0.1.0] - 2026-10-07

First public release.

### Added

- The `idempotency:scan` Artisan command, with `--fail-on=high|medium|low|none` for CI use and `--format=text|json`.
- Discovery of `ShouldQueue` jobs, including those that inherit the interface from a base class (in the same file or loadable through the autoloader) and those that use an interface extending `ShouldQueue`.
- Static analysis engine (via `nikic/php-parser`) starting at `handle()`, following the class's own methods (`$this->`, `self::` and `static::`, up to 5 levels).
- Sink catalog: payment, email, notification, HTTP with side effects and database insert.
- Guard catalog: `Cache::lock`/`Cache::add`, `firstOrCreate`/`updateOrCreate`/`upsert`, idempotency key in literal arrays and `ShouldBeUnique` (partial protection, which lowers the risk by one level).
- Resolution of declared types (properties, promoted properties and parameters), of subclasses, interfaces and traits from the catalog (through the autoloader) and of chains declared in `chains` (`Mail::to()->send()`, `Http::withToken()->post()`, `Notification::route()->notify()`).
- Risk report (high, medium, low) with the exact file and line, as text or as versioned JSON.
- Publishable configuration (`php artisan vendor:publish --tag=idempotency-linter-config`).
- Compatible with Laravel 10, 11, 12 and 13 (PHP 8.1 or higher; Laravel 13 requires PHP 8.3). CI runs the tests on PHP 8.1 to 8.3, plus Pint and PHPStan (level 6).

### Known limitations

- It does not follow injected services, methods inherited from a parent class in another file, or traits.
- No type inference: local variables, untyped properties and chains outside the `chains` catalog are ignored.
- A guard counts by execution order, not by control flow (a guard inside an unrelated `if` still protects the sink).
- The analyzed project's autoloader is used to resolve class hierarchy.

[Unreleased]: https://github.com/Emerson-Pombo/idempotency-linter/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/Emerson-Pombo/idempotency-linter/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Emerson-Pombo/idempotency-linter/releases/tag/v0.1.0
