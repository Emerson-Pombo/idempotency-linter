# idempotency-linter

A Composer package for Laravel that statically analyzes queue job classes (`ShouldQueue`) and finds jobs that perform side effects sensitive to duplication — charges, emails, database inserts — with no protection against re-execution.

> ⚠️ **Status: pre-release (0.x).** The analysis engine, the Artisan command and the JSON output already work, but the format of the detection catalogs may still change between 0.x versions. See the [CHANGELOG](CHANGELOG.md). Use it as a development and CI tool, not as a production dependency.

## The problem

Queue systems (Laravel Queues, BullMQ, Sidekiq, Celery) run under an **at-least-once** guarantee: network failures, timeouts and deploy restarts can make the same job run more than once. Making each job idempotent is left to the developer, by hand — and that process is prone to human error. In codebases with dozens of jobs, it is common for some to be left unprotected, and the problem only shows up in production as a visible incident (a duplicate charge, a repeated email, a duplicate record).

Today, existing tools (idempotency libraries, deduplication middleware) only work at the **manual prevention** layer: the developer explicitly decorates the code with an idempotency key. No public tool automatically audits existing code to flag unprotected jobs before they become an incident.

## The idea

A static linter (built on [nikic/php-parser](https://github.com/nikic/PHP-Parser)) that walks the `handle()` method of every Laravel job, finds calls with dangerous side effects ("sinks") and checks whether a recognized idempotency guard ("guards") protects each one. Unprotected jobs are reported with a risk level and the exact location in the code.

```bash
composer require --dev emerson-pombo/idempotency-linter

php artisan idempotency:scan app/Jobs
```

```
🔴 HIGH RISK — app/Jobs/ProcessPaymentJob.php:16
   Call to a payment gateway without an idempotency check.
   Job: App\Jobs\ProcessPaymentJob

❌ 2 jobs analyzed, 1 at risk, 1 protected.
```

## Scope

- A Composer package that installs in any Laravel project.
- The `idempotency:scan` Artisan command.
- Static analysis of `ShouldQueue` classes, starting at `handle()` and following the project's own code.
- Customizable sink and guard catalogs through a publishable config.
- A risk report (high/medium/low) with the exact file and line.

Out of scope for now: dynamic/runtime analysis, distributed idempotency across microservices, automatic fixes and other queue ecosystems (BullMQ, Sidekiq, Celery). They are possible future work.

## How the analysis works

For each `ShouldQueue` job, the linter reads the body of `handle()` and:

1. **Looks for sinks:** starting at `handle()`, and in the project methods it calls, it looks for calls from the `sinks` catalog (payment, email, notification, HTTP, database insert). Each call found produces a finding with the exact line.
2. **Looks for guards:** calls from the `guards` catalog (`Cache::lock`/`Cache::add`, `firstOrCreate`/`updateOrCreate`/`upsert`, an idempotency key in arrays such as `['idempotency_key' => ...]`). A guard only protects what comes **after it in execution order** (the body of a followed method counts at the point where it is called); a guard placed after the sink does not count. An idempotency key also protects the call that receives it as an argument.
3. **Accounts for partial protection:** if the job implements `ShouldBeUnique`, the risk of each finding drops one level (high → medium → low) and low-risk findings are no longer reported. `ShouldBeUnique` prevents concurrent jobs, but it does not cover retries or redelivery.

### What is followed

The linter follows the flow into the project's code, up to 5 levels deep, without repeating a method that is already running:

- methods of the class itself: `$this->method()`, `self::method()` and `static::method()`;
- injected services with a **declared concrete type**: `$this->service->method()` (a property, including one promoted in the constructor) and `$service->method()` (a parameter of `handle()`), plus static calls to project classes (`Helper::method()`);
- inherited methods: defined on the parent class, `parent::method()`, and `handle()` itself when the child job does not define it;
- methods of project traits, and typed properties coming from the parent or from traits.

A finding points to the real file and line of the sink (for example, inside the service) and to the job that reaches it. If several jobs use the same service, each one produces its own finding. Classes from `vendor/`, interfaces, and calls that are already catalog sinks or guards are not followed inside.

Methods are recognized when the type of the object is known: static calls (`Mail::send()`), functions, and methods on properties (including ones promoted in the constructor) or `handle()` parameters with a **declared type** (`$this->stripe->create()` with `PaymentIntentService $stripe`). Subclasses, interfaces and traits from the catalog also match (`Invoice::create()` with `Invoice extends Model`).

Chained calls are followed when they are declared in the `chains` catalog, which says what type each method returns. By default it covers `Mail::to($u)->cc($c)->send($m)`, `Http::withToken($t)->acceptJson()->post($url)` and `Notification::route('mail', $to)->notify($n)`. The reported line is the one where the chain starts.

### Limitations of v1

- Dependencies typed by **interface** are not followed (the implementation is only known to the container at runtime), nor are services obtained through `app(Service::class)`, `new` in a local variable, or method injection outside `handle()`.
- Classes from `vendor/` (including framework traits such as `Queueable`) are not followed inside.
- No type inference: local variables, untyped properties and chains that are not in the `chains` catalog (for example `app(Foo::class)->send()`) are ignored, without an error.
- A guard only counts by its position in the code; a guard inside an unrelated `if` still protects the sink.
- Jobs that inherit the interface from a base class (`extends BaseJob`) or use an interface that extends `ShouldQueue` are detected, as long as the base class can be resolved: in the same file, or loadable through the autoloader. A parent that fails to load is ignored.

### Heads-up: autoloading

To resolve subclasses and traits, the linter loads your project's classes through Composer's autoloader (`class_exists`, `is_a`, `class_uses`). It **does not instantiate** or run classes, but autoloading a class with file-level code runs that code. Classes that fail to load are ignored. Run the command in the project's environment, as you would any Artisan command.

## Installation

Requires PHP 8.1 or higher and Laravel 10, 11, 12 or 13.

```bash
composer require --dev emerson-pombo/idempotency-linter
```

The ServiceProvider is registered automatically (Laravel package auto-discovery).

## Usage

```bash
# analyze the paths defined in the config (default: app/Jobs)
php artisan idempotency:scan

# one or more files/directories
php artisan idempotency:scan app/Jobs app/Domain/Billing/Jobs

# control the exit code (useful in CI): high | medium | low (default) | none
php artisan idempotency:scan --fail-on=high
```

The command exits with code `1` if there is any finding at the `--fail-on` level or above.

### JSON output

With `--format=json` (default: `text`) the command prints only a JSON document to stdout, with no icons or warnings, and keeps the same exit code:

```bash
php artisan idempotency:scan app/Jobs --format=json --fail-on=none > idempotency.json
```

```json
{
    "version": 1,
    "summary": {
        "jobs": 2,
        "risky_jobs": 1,
        "protected_jobs": 1,
        "findings": 1,
        "errors": 0
    },
    "findings": [
        {
            "file": "app/Jobs/ProcessPaymentJob.php",
            "line": 16,
            "job": "App\\Jobs\\ProcessPaymentJob",
            "sink": "payment",
            "risk": "high",
            "message": "Call to a payment gateway without an idempotency check."
        }
    ],
    "errors": []
}
```

- `findings` is sorted from highest to lowest risk. `risk` is `high`, `medium` or `low`.
- `errors` lists paths that do not exist and files with syntax errors, which do not interrupt the scan.
- `version` changes when the format changes in an incompatible way.

A GitHub Actions example that fails only on high risk and keeps the report:

```yaml
- run: php artisan idempotency:scan --format=json --fail-on=high | tee idempotency.json
```

### Configuration

```bash
php artisan vendor:publish --tag=idempotency-linter-config
```

This creates `config/idempotency-linter.php` with the default paths and the catalogs of **sinks** (side effects: payment, email, notification, HTTP, database insert), **guards** (`Cache::lock`/`Cache::add`, `firstOrCreate`/`upsert`, idempotency key, `ShouldBeUnique`) and **chains** (known call chains). The format of these catalogs may still change. In `function` rules, the function names go in the `functions` key (or `methods`).

## Roadmap

- [x] Base structure: ServiceProvider, publishable config, `idempotency:scan` command, `ShouldQueue` job discovery
- [x] Analysis engine: detect sinks inside `handle()`
- [x] Detect guards and decide whether they protect each sink
- [x] Resolve chained calls declared in the catalog (`Mail::to()->send()`, `Http::withToken()->post()`)
- [x] Follow the class's own methods from `handle()`
- [x] Follow injected services, inherited methods and project traits from `handle()`
- [x] Recognize jobs that inherit `ShouldQueue` from a base class
- [x] JSON output for CI (`--format=json`)
- [ ] Resolve interfaces to their concrete implementation
- [ ] SARIF output

## Development

```bash
composer install
composer check     # code style (Pint) + static analysis (PHPStan level 6) + tests
composer format    # applies the code style automatically
```

CI runs the tests on PHP 8.1 to 8.3 with Laravel 10, 11, 12 and 13 (compatible combinations), plus Pint and PHPStan.

## Contributing

Issues and discussions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE)
