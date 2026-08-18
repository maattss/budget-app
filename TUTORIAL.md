# Budget app — a ~4 hour Laravel/Livewire learning build

> **Status (2026-08-18): Hours 1–3 complete. Resume at Hour 4.**
>
> `resources/views/pages/month/⚡show.blade.php` is finished: `#[Url]`-synced
> `year`/`month`, month navigation, the cash-flow form (`loadCashFlow`,
> `saveCashFlow`, `savings`), per-asset value inputs (`assets`, `loadAssetValues`,
> `saveAssetValues`) and `netWorth`. **Mats writes the component logic himself and Claude
> reviews** — Claude scaffolds files and method signatures only.
>
> Hour 4 remains: dashboard as a Livewire component, one Pest test, sidebar nav (already
> done for both pages). The N+1 demo and the auth-scoping lesson from 4.3 have both
> already been covered.
>
> **The auth-scoping point landed for real in `saveAssetValues()`:** the keys of
> `$this->values` come from the client, so iterating `$this->values` would let anyone
> write to another user's asset. Iterating `$this->assets` — which comes from
> `Auth::user()->assets()` — makes a forged id simply never match. Verified with a test
> that injected a foreign asset id: zero rows written. The rule worth keeping: never let
> the client decide which rows you iterate over; use its input only to look up values.
>
> **Two things learned the hard way, worth not rediscovering:**
> - `#[Url]` properties are hydrated from the query string **before** `mount()` runs, so
>   an unconditional `$this->month = now()->month` in `mount()` silently discards the URL
>   value. Use `??=`. Verified with `Livewire::withQueryParams()`.
> - PhpStorm flags `updateOrCreate(['year' => ...])` as "attribute 'year' is guarded"
>   even though it is in `$fillable`. False positive: neither the IDE nor PHPStan can
>   resolve types inside an SFC, because the file is not valid PHP until Livewire compiles
>   it. In SFCs, both warnings and the absence of warnings are unreliable — component
>   tests are the only real safety net.
>

> - **1.1 done** — `savings` column removed from the `monthly_finances` migration;
>   `php artisan migrate:fresh` run against MySQL, schema rebuilt clean.
> - **1.2 done** — `savings` dropped from `$fillable` and `casts()` on
>   `app/Models/MonthlyFinance.php`, replaced by an `Attribute::get()` accessor
>   returning `float`. Also added the Larastan-required relation generics
>   (`@return BelongsTo<User, $this>` / `@return HasMany<MonthlyFinance, $this>`) that
>   were missing on both models from session 1.
> - **1.3 done** — `app/Enums/AssetType.php` created (`php artisan make:enum
>   Enums/AssetType --string` — note the `Enums/` prefix is required, otherwise it lands
>   in `app/`). Eight string-backed cases, `label()` and `isLiability()`, both `match`
>   expressions with **no `default` branch** so a new case forces an explicit decision.
> - **1.4 done** — `assets` and `asset_values` migrations, `Asset` and `AssetValue`
>   models, `assets()` relation on `User`. Applied with `php artisan migrate` (additive,
>   no rebuild needed — unlike 1.1, which edited an existing migration).
> - **1.5 done** — `AssetFactory` with `ofType()` and `liability()` states. Seeded
>   `test@example.com` (password `password`) with 5 assets × 3 months of values and 3
>   months of cash flow, via a throwaway Tinker script. Note `HasFactory` is opt-in per
>   model: `Asset::factory()` fails without the trait even when the factory exists.
> - The N+1 demo from step 4.3 was pulled forward while relations were fresh: looping
>   assets and reading `->values` inside the loop costs 6 queries, `with('values')` costs
>   2. Also worth remembering that `values()->count()` and `values->count()` are both a
>   single query — the difference is payload and PHP memory, not query count.
> - Pint and PHPStan (level 7) both pass clean. Steps 1.1–1.4 are committed and pushed.
>
> **Local gotcha:** PHPStan OOMs at PHP's default 128 MB `memory_limit`. Run it as
> `vendor/bin/phpstan analyse --memory-limit=1G` (or raise the limit in php.ini) —
> `composer types:check` and `composer test` will otherwise fail with a fatal error
> that looks like a code problem but isn't.

## Context

This app is a comprehension vehicle, not a product. The goal is to understand the
Laravel/Livewire stack well enough to follow a team's conversations and reason about
the problems they hit — coming from a C#/.NET and React background, with no prior PHP.
Scope is chosen for *concept coverage per hour*, not feature completeness.

The app is a personal budget tool. Decisions already made:

- **Cash flow is the primary feature** — monthly income/spending. The existing
  `MonthlyFinance` model and migration are kept and become the core.
- **Balance sheet is the secondary layer** — assets *and* liabilities, so net worth
  (assets − liabilities) is a meaningful number.
- **`savings` is derived, not stored** — `income − spending`, computed in the model.
- **Valuation is manual** — one typed value per asset per month. No live price feeds.
- **Single user.** MySQL (`personal_finance`), already created and migrated.

Stack: the official Laravel Livewire starter kit — Laravel 13, **Livewire 4**,
Flux UI v2, Fortify, Pest 5, Pint, Larastan level 7.

Working mode is **pair-programming**: each step below is a small piece of code plus the
concept it teaches. Build and explain one at a time, not in bulk.

---

## Hour 1 — Schema and the Eloquent layer

### 1.1 Fix `savings` in the existing migration

`database/migrations/2026_08_11_091235_create_monthly_finances_table.php` currently
stores `income`, `spending` **and** `savings` — three columns where the third is
implied by the first two, so the row can contradict itself.

Remove the `savings` column from the migration and rebuild with
`php artisan migrate:fresh`. This is safe *only* because the migration is uncommitted
and the data is disposable.

> **Concept:** editing an already-run migration vs. adding a new one. Locally,
> pre-commit, editing is fine. Once a migration has run anywhere else it is immutable
> and you must add a follow-up migration — same rule as EF Core. Worth seeing the line
> clearly, because crossing it is a common team incident.

### 1.2 Derive savings on the model

In `app/Models/MonthlyFinance.php`, drop `savings` from `$fillable` and its cast, then
add a derived attribute using Laravel's `Attribute` class:

```php
protected function savings(): Attribute
{
    return Attribute::get(fn () => $this->income - $this->spending);
}
```

`$finance->savings` now reads like a normal property.

> **Concept:** Eloquent *accessors* ≈ C# computed properties. Note the difference from
> EF Core: this is computed in PHP after hydration, so it is **not queryable in SQL** —
> you cannot `->where('savings', '>', 0)`. That tradeoff (derived-in-app vs.
> computed-column-in-DB) is a recurring design argument on real teams.

### 1.3 An `AssetType` backed enum

New file `app/Enums/AssetType.php` — a PHP backed enum (PHP 8.1+, very close to a C#
enum) covering both sides of the balance sheet:

- Assets: `BankAccount`, `Property`, `Stock`, `Cash`, `OtherAsset`
- Liabilities: `Mortgage`, `Loan`, `CreditCard`

with two methods: `label(): string` for display, and `isLiability(): bool`.

Deriving asset-vs-liability from the type means no separate stored column that could
disagree with the type — the same "don't store what you can derive" call as `savings`.

> **Concept:** PHP enums, and Eloquent's ability to cast a string column straight into
> an enum instance (`'type' => AssetType::class`). Closest .NET analogue is an enum
> with a `ValueConverter`.

### 1.4 Two migrations and two models

**`assets`** — `id`, `user_id` (FK cascade), `name` (string), `type` (string), timestamps.

**`asset_values`** — `id`, `asset_id` (FK cascade), `year` (smallint), `month`
(tinyint), `value` (decimal 14,2), timestamps, `unique(asset_id, year, month)`.

New models `app/Models/Asset.php` and `app/Models/AssetValue.php`, plus a
`hasMany(Asset::class)` on `User`.

> **Concept:** relationships as methods (`hasMany`/`belongsTo`) rather than EF
> navigation properties, and the fact that `$asset->values` (property) lazily runs a
> query while `$asset->values()` (method) returns a query builder. That one-character
> difference is the root of most N+1 problems — revisited in hour 4.

**Note for later, not now:** both `monthly_finances` and `asset_values` define a month
as a loose `(year, month)` pair. A shared `months` table would be the normalised
answer. Left denormalised deliberately — a discussion point, not a TODO.

### 1.5 Seed some data

Add an `AssetFactory`, then create a handful of assets and a few months of values from
`php artisan tinker`.

> **Concept:** Tinker as a REPL against the live app (≈ `dotnet script` with your DI
> container wired up), and factories as test-data builders.

---

## Hour 2 — First Livewire component: managing assets

New single-file component `resources/views/pages/assets/⚡index.blade.php`, routed in
`routes/web.php` inside the existing auth group:

```php
Route::livewire('assets', 'pages::assets.index')->name('assets.index');
```

It lists the user's assets grouped into assets vs. liabilities, and has an inline form
to add one (name + type dropdown) and a delete action.

Follow the exact pattern already in `resources/views/pages/settings/⚡profile.blade.php`:
an anonymous `Component` class in a `<?php ?>` block at the top, Blade markup below,
`wire:model` for binding, `wire:submit` for the form, Flux components for UI.

> **Concept — the big one.** Livewire looks like React (state on the component,
> re-render on change) but the component instance lives on the **server**. Every
> `wire:model` update or action is an HTTP round-trip that rehydrates the component,
> runs your method, re-renders the Blade, and diffs the HTML back into the DOM. That
> buys you no API layer and no client state management; it costs you a network hop per
> interaction and means component state must be serialisable. This is *the* tradeoff to
> understand — nearly every "Livewire feels slow" conversation traces back to it.
>
> Also worth showing: `wire:model` (deferred by default in Livewire 3+) vs
> `wire:model.live`, which is exactly the chattiness dial.

---

## Hour 3 — The month screen

New component `resources/views/pages/month/⚡show.blade.php`, routed as:

```php
Route::livewire('month', 'pages::month.show')->name('month.show');
```

Contents:

- Year/month held as component state with Livewire's `#[Url]` attribute so the
  selected month lives in the query string and is shareable/bookmarkable.
- Previous/next month navigation.
- A cash-flow form (income, spending) writing to `monthly_finances` via
  `updateOrCreate`, showing derived savings live.
- A row per asset with an input for that month's value, saved to `asset_values`.
- A computed net-worth total (assets − liabilities) using `#[Computed]`.

> **Concepts:** `#[Url]` for URL-synced state (React Router's `useSearchParams`, but
> declarative); `updateOrCreate` as an upsert; `#[Computed]` properties, which are
> memoised per request — the analogue of `useMemo`, and the idiomatic place to put
> derived display values; validation via `$this->validate()` with errors rendered
> inline, showing that in Laravel validation lives at the boundary, not in the entity.

---

## Hour 4 — Dashboard, a test, and the production concerns

### 4.1 Dashboard

Convert `resources/views/dashboard.blade.php` (currently a static `Route::view`) into a
Livewire component showing current-month income, spending, savings and net worth, plus
the last few months as a simple table.

Add sidebar nav entries for both new pages in
`resources/views/layouts/app/sidebar.blade.php`.

### 4.2 One Pest test

`tests/Feature/MonthTest.php`, following `tests/Feature/Settings/ProfileUpdateTest.php`:

```php
Livewire::test('pages::month.show')
    ->set('income', 50000)
    ->set('spending', 30000)
    ->call('saveCashFlow')
    ->assertHasNoErrors();
```

> **Concept:** Livewire components are testable server-side without a browser — no
> Playwright, no DOM. Note that the suite runs on **in-memory SQLite** (`phpunit.xml`)
> while the app runs on MySQL; that divergence is a real and common source of
> "passes in CI, fails in prod" bugs, and is worth knowing about explicitly.

### 4.3 The two things that bite Laravel teams

Time permitting, demonstrated live rather than described:

1. **N+1 queries.** Enable query logging, load the dashboard, count the queries. Show
   `Asset::with('values')` (eager loading) collapsing N+1 into 2. Then mention
   `Model::preventLazyLoading()` as the guardrail teams add in dev.
2. **Auth scoping.** Show that `Asset::find($id)` will happily return *another user's*
   asset, and that scoping to `auth()->user()->assets()` is a discipline the framework
   does not enforce for you. This is the most common real vulnerability class in
   Laravel apps and the one most worth recognising in code review.

---

## Files to be created / modified

**New**
- `app/Enums/AssetType.php`
- `app/Models/Asset.php`, `app/Models/AssetValue.php`
- `database/migrations/*_create_assets_table.php`
- `database/migrations/*_create_asset_values_table.php`
- `database/factories/AssetFactory.php`
- `resources/views/pages/assets/⚡index.blade.php`
- `resources/views/pages/month/⚡show.blade.php`
- `resources/views/pages/⚡dashboard.blade.php`
- `tests/Feature/MonthTest.php`

**Modified**
- `database/migrations/2026_08_11_091235_create_monthly_finances_table.php` — drop `savings`
- `app/Models/MonthlyFinance.php` — derived `savings` accessor
- `app/Models/User.php` — `assets()` relation
- `routes/web.php` — two new routes, dashboard becomes `Route::livewire`
- `resources/views/layouts/app/sidebar.blade.php` — nav entries
- `resources/views/dashboard.blade.php` — replaced by the SFC

**Untouched:** `database/database.sqlite` is a leftover from the starter kit and is not
used by the running app (though the *test* suite uses in-memory SQLite). Left in place.

---

## Verification

- `php artisan migrate:fresh` — schema rebuilds cleanly.
- `composer dev` (runs `php artisan dev`) — app boots; walk `/dashboard`, `/assets`,
  `/month` in the browser and enter real numbers.
- `composer test` — chains Pint → Larastan level 7 → Pest. Must pass clean; Larastan at
  level 7 is strict and will catch missing type hints on the new models.

## Scope control

Hours 1–3 are the core. If time runs short, hour 4 compresses to just the dashboard and
the N+1 demo — the test and auth-scoping discussion can be dropped without leaving
anything half-built.

---

## Environment notes

- MySQL: database `personal_finance` at `127.0.0.1:3306`, user `root`, empty password
  (`DB_PASSWORD` is commented out in `.env`). Already created and migrated.
- Livewire 4 single-file components live at `resources/views/pages/**/⚡name.blade.php`
  and are addressed as `pages::path.name` in routes, `<livewire:...>` tags and
  `Livewire::test()`. The `pages` namespace is registered by Livewire's default config.
- `composer dev` starts the whole dev stack; `composer test` is the full gate.
