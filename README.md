# budget-app

A small personal budget app, built as a **learning project** for the Laravel/Livewire
stack rather than as a product. The scope is chosen for concept coverage, not feature
completeness — see [`TUTORIAL.md`](TUTORIAL.md) for the step-by-step build plan, what
each step is meant to teach, and how far the build has got.

## What it does

- **Cash flow** is the primary feature: one row per month with income and spending.
  Savings is *derived* (`income − spending`), never stored, so a row cannot contradict
  itself.
- **Balance sheet** is the secondary layer: assets *and* liabilities, so net worth
  (assets − liabilities) is a meaningful number. Valuation is manual — one typed value
  per asset per month, no price feeds.
- Single user. A month is identified by a loose `(year, month)` integer pair.

## Stack

| | |
|---|---|
| Framework | Laravel 13 (PHP 8.3+) |
| UI | Livewire 4 single-file components + Flux UI v2 + Tailwind 4 |
| Auth | Laravel Fortify (incl. two-factor and passkeys) |
| Database | MySQL |
| Tests | Pest 5 |
| Static analysis | Larastan level 7 |
| Formatting | Laravel Pint |
| Assets | Vite 8 |

Livewire 4 single-file components live at `resources/views/pages/**/⚡name.blade.php` —
the `⚡` prefix is literal — and are addressed as `pages::path.name` in routes,
`<livewire:…>` tags and `Livewire::test()`.

## Requirements

- PHP 8.3 or newer with the usual Laravel extensions (CI runs 8.5)
- Composer 2
- Node 22
- MySQL 8

## Getting started

**1. Create the database.** The app expects a MySQL schema named `personal_finance`:

```bash
mysql -u root -e 'CREATE DATABASE personal_finance'
```

**2. Configure the environment.** Note that `.env.example` ships with the starter kit's
`DB_CONNECTION=sqlite`, so the MySQL settings have to be filled in by hand after the
file is copied:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=personal_finance
DB_USERNAME=root
DB_PASSWORD=
```

**3. Install and build.** `composer setup` does the whole first-run sequence — install
dependencies, copy `.env`, generate the app key, run migrations, then `npm install` and
`npm run build`:

```bash
composer setup
```

If you configure `.env` *after* running setup, apply the schema with
`php artisan migrate`.

**4. Run it.**

```bash
composer dev
```

That starts four processes together: `php artisan serve` on
[localhost:8000](http://localhost:8000), Vite in watch mode, a queue listener, and
`php artisan pail` for live logs. Run `php artisan dev:list` to see them.

If you serve the app through Laravel Herd or Valet on a `.test` hostname instead, keep
`APP_URL` in `.env` pointing at that host — Vite generates asset URLs from it, and a
mismatch shows up as assets that silently fail to load.

**5. Get a login.** Either register through the UI, or seed the starter kit's test user:

```bash
php artisan db:seed
```

That creates `test@example.com` with the password `password`.

## Development

```bash
composer test          # the full gate: Pint (check) → Larastan → Pest
composer lint          # Pint, fixing in place
composer types:check   # Larastan only
php artisan test --compact --filter=someTest      # a single test
php artisan test --compact tests/Feature/X.php    # a single file
php artisan migrate:fresh                         # rebuild the schema from scratch
```

`composer test` is what CI runs (via `composer ci:check`), so it is the gate to satisfy
before pushing. Pint and Larastan level 7 are part of it — a missing type hint fails the
build, not just a failing assertion.

**Two things worth knowing about the local setup:**

- **PHPStan may run out of memory** at PHP's default 128 MB `memory_limit`, which
  surfaces as a fatal error that looks like a code problem. Either raise `memory_limit`
  in your `php.ini`, or run `vendor/bin/phpstan analyse --memory-limit=1G` directly. CI
  is unaffected, since `setup-php` runs with no limit.
- **Tests do not run against MySQL.** `phpunit.xml` pins the suite to in-memory SQLite,
  while the app runs on MySQL. That divergence is a real source of "passes in CI, breaks
  locally" behaviour, so verify schema-sensitive work against MySQL as well.
  `database/database.sqlite` is an unused leftover from the starter kit.

## Frontend changes not showing up?

Run `npm run build`, or make sure Vite is running via `composer dev`.
