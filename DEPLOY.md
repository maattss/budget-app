# Deploying Saldo

## Where to host it

**Recommended: [Laravel Cloud](https://cloud.laravel.com).** First-party, git-push
deploy, managed MySQL, and built assuming this exact stack (Livewire 4, Fortify, Vite).
As of August 2026 it starts at **$5/month with $5 of monthly credits**, and its "flex"
MySQL compute scales to zero — you are billed per second the database is *awake*, so a
learning app that nobody visits costs close to nothing. Storage and backups bill
continuously at ~$0.10/GB-month in US regions.

**Worth doing once instead: [Forge](https://forge.laravel.com) + a €5 VPS** (Hetzner,
DigitalOcean). Not because it is better here, but because it is what most Laravel shops
actually run. The vocabulary of those teams — deploy scripts, zero-downtime releases,
Supervisor-managed queue workers, Nginx — comes from this world and not from a PaaS.

**Not Vercel**, despite the familiar DX:

- No first-party PHP runtime; the community `vercel-php` runtime lags releases, and this
  app needs PHP 8.3+.
- Livewire is the worst possible fit for serverless. Every `wire:model` update and every
  action is an HTTP round trip that rehydrates the component **on the server** — pairing
  the chattiest frontend model with the hosting model that punishes chattiness most.
- Serverless opens one MySQL connection per invocation, so you need a pooler in front to
  avoid connection exhaustion.
- No persistent filesystem for compiled views and logs.

Fly.io, Railway and Render are all fine if you would rather stay container-shaped.

### Why MySQL and not serverless Postgres

Decided deliberately in August 2026, having checked the pricing rather than assumed it.

**Serverless Postgres is not cheaper here.** Laravel Cloud's MySQL **Flex** tier also
scales to zero — no compute is billed while the database is asleep — so for an app that
is idle almost all the time both options cost effectively nothing in *compute*.

Storage is not free, though — an earlier version of this note wrongly called it a
rounding error. Laravel MySQL has a **5 GB minimum**, billed continuously even while the
database sleeps, plus backups (7-day retention by default) at the same
`$0.10/GB-month`. So the idle floor is roughly **$0.50–1.00/month**, not zero. Serverless
Postgres bills storage by actual usage instead, which for a 352 KB database really is
nothing — the one place Postgres is genuinely cheaper here. Both are still dwarfed by the
$5/month Starter base fee and covered by the $5 usage credit. Postgres's genuine price edge is that it has *no
minimum size* (billed per fractional compute unit, where MySQL's floor is
`mysql-flex-512mb`), which only pays off for a database that is awake a lot at low load.
That is not this app. Note MySQL **Pro** is always-on and *does* bill when idle — Flex is
the one that hibernates.

Postgres would have brought real non-price benefits: Neon-style database branching per
pull request, and point-in-time recovery. It would also have removed an existing
divergence, since Postgres is case-sensitive like the test suite's SQLite, where MySQL's
`utf8mb4_unicode_ci` is not — so `Test@Example.com` currently logs in and would not on
Postgres.

**MySQL wins on the only criterion that matters for this project: it is what the team
runs.** This app is a comprehension vehicle for their stack, so learning Postgres's
quirks would be off-mission for a saving of roughly zero.

If that ever changes, the migration is small — the audit found only four things:
`unsignedSmallInteger`/`unsignedTinyInteger` on `year`/`month` lose their
database-level range constraint (Postgres has no unsigned integers and Laravel silently
maps them to `smallint`); email lookups need normalisation or `citext` for the case
change; `whereRaw('(year * 100 + month) >= ?')` and `json('credential')` both port
as-is.

---

## Before the first deploy

Three things specific to this app. The first is the one that matters.

### 1. Registration is off by default — keep it that way

`config/fortify.php` gates `Features::registration()` behind `FORTIFY_REGISTRATION`,
which **defaults to false**. A fresh production environment is therefore closed without
anyone having to remember a variable. `phpunit.xml` sets it to true so
`tests/Feature/Auth/RegistrationTest.php` still runs, and local `.env` sets it to true
for convenience.

If you do enable it, `/register` is public and anyone can create an account. Nothing
leaks between accounts — every query is scoped through `Auth::user()` and there are tests
proving a forged id cannot cross the boundary — but it is an open sign-up on a
single-user app.

**Create your production account with the bundled command**, not by opening
registration:

```bash
php artisan app:create-user --name="Your Name" --email="you@example.com"
```

Every option is a flag so it runs without a TTY — a remote command runner has nothing to
prompt against. Omit `--password` and it reads `INITIAL_USER_PASSWORD` from the
environment, which is preferable in production: a value passed on a command line may be
recorded in the host's command log, an environment variable is not.

The command marks the email verified. Note it must use `forceFill` to do so, because
`User` declares `#[Fillable(['name', 'email', 'password'])]` and passing
`email_verified_at` to `create()` is **silently dropped** —
`Model::preventSilentlyDiscardingAttributes()` (the second flag in `shouldBeStrict()`)
is what turns that silence into an exception.

Worth knowing while you are here: the route groups are wrapped in `['auth', 'verified']`,
but `User` does *not* implement `MustVerifyEmail` — the import is commented out at
`app/Models/User.php:5` — so that middleware is currently **inert**. If you ever
uncomment it, every existing user without a verification timestamp is locked out, and
with `MAIL_MAILER=log` no verification mail would arrive to fix it.

**Disabling a Fortify feature removes its routes.** This caused a real 500 on the first
deploy: with registration off, `route('register')` no longer resolves, and the starter
kit's `pages/auth/login.blade.php` linked to it unconditionally — so *the login page
itself* threw `Route [register] not defined`. The view is now guarded with
`@if (Route::has('register'))`, matching how the same file already guarded
`password.request`.

The suite could not have caught it: `phpunit.xml` sets `FORTIFY_REGISTRATION=true`, so
tests exercised the opposite configuration from production.
`tests/Feature/Auth/RegistrationDisabledTest.php` now boots a fresh application with the
flag off and renders the login page, so the production path is actually covered. If you
ever disable another Fortify feature, grep the views for `route('<name>')` first.

### 2. Do not carry `test@example.com` / `password` into production

That account was created from a throwaway Tinker script rather than a seeder, so nothing
will run it automatically. Be deliberate about it anyway: `TUTORIAL.md` publishes those
credentials and this repository is public.

### 3. Passkeys are bound to the hostname

WebAuthn derives its relying-party ID from the domain, so a passkey registered on
`localhost` will not verify against your production host. Expect to re-register there,
and make sure `APP_URL` is the real HTTPS origin.

---

## What this app does *not* need

Checked, and it keeps the deployment simple:

- **No queue worker.** Nothing implements `ShouldQueue`; `QUEUE_CONNECTION=database` has
  no pending work.
- **No scheduler / cron.** No scheduled tasks are registered.
- **No persistent disk.** No uploads, no `Storage::` writes.

Sessions, cache and queue all live in the database, so it is a pure web deploy: one
process, one database.

---

## Release steps

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # Vite assets must be built; they are not committed

php artisan migrate --force      # --force is required outside local

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run the caches **after** `config:cache`-relevant environment variables are set — a cached
config ignores later `env()` reads.

To roll back a config cache during debugging: `php artisan optimize:clear`.

---

## Two guards that are already pointed the right way

Both live in `AppServiceProvider::configureDefaults()`, and their polarity is opposite on
purpose:

| Guard | Value in production | Why |
|---|---|---|
| `Model::preventLazyLoading(! app()->isProduction())` | **off** | An N+1 should be slow for users, not a 500. It throws in dev and CI, where you want to hear about it. |
| `DB::prohibitDestructiveCommands(app()->isProduction())` | **on** | Blocks `migrate:fresh` and friends against live data. |

A consequence worth knowing: you **cannot** run `php artisan migrate:fresh` against
production, by design. Migrating forward is the only path.

---

## Gotchas

- **A real mailer is not optional.** `MAIL_MAILER=log` sends password-reset and
  email-verification mail to the log file. With registration closed and no working
  mailer, losing your password means losing access.
- **Choose MySQL, not Postgres.** Development runs MySQL and the test suite runs
  in-memory SQLite; that divergence is already documented in `TUTORIAL.md` (collation
  case-sensitivity, and SQLite not enforcing `DECIMAL` scale). Adding Postgres as a third
  engine multiplies it for no gain.
- **`APP_KEY` is generated once and kept.** Rotating it invalidates every session,
  encrypted column and signed URL.
- **`SESSION_SECURE_COOKIE=true`** behind HTTPS, otherwise the session cookie is sent
  over plain HTTP too.
- **Build assets on the host or in CI.** `public/build` is gitignored, and the app renders
  a blank page without it.
