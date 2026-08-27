<?php

use App\Models\Asset;
use App\Models\AssetValue;
use App\Models\MonthlyFinance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Both pages read every asset's whole value history, so the thing that goes wrong here
 * is always the same: a per-asset read that looks free in a Blade loop and is a query.
 *
 * These assert an exact count rather than an upper bound. A ceiling of "no more than
 * ten" passes while a page quietly doubles its work; an exact number fails the moment
 * anything changes, which is the point - the failure is a prompt to look, and the fix
 * is often to update the number.
 *
 * This is not hypothetical. Extracting Portfolio put the month form at four queries by
 * reading asset_values twice over, and nothing caught it: this file used to print the
 * query log and assert nothing at all.
 *
 * @param  callable(): void  $render
 * @return array<int, array<string, mixed>>
 */
function queriesFor(callable $render): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $render();

    $log = DB::getQueryLog();

    DB::disableQueryLog();

    return $log;
}

/**
 * Five assets with a year of history each, which is enough that an N+1 shows up as a
 * count in the dozens rather than hiding inside the noise of a small fixture.
 */
function aPopulatedUser(): User
{
    $user = User::factory()->create();

    Asset::factory()->count(5)->for($user)->create()->each(
        fn (Asset $asset) => collect(range(0, 11))->each(
            fn (int $back) => AssetValue::factory()
                ->for($asset)
                ->in(now()->subMonths($back))
                ->create()
        )
    );

    collect(range(0, 11))->each(
        fn (int $back) => MonthlyFinance::factory()->for($user)->in(now()->subMonths($back))->create()
    );

    return $user;
}

test('the dashboard renders in three queries', function () {
    $this->actingAs(aPopulatedUser());

    $log = queriesFor(fn () => Livewire::test('pages::dashboard')->assertOk());

    // Assets, their values, and the cash flow rows. Nothing per asset and nothing
    // per month, however many of either there are.
    expect($log)->toHaveCount(3);
})->group('queries');

test('the assets page renders in two queries', function () {
    $this->actingAs(aPopulatedUser());

    $log = queriesFor(fn () => Livewire::test('pages::assets.index')->assertOk());

    // Assets and their values. Every row draws a sparkline and a current value from
    // what is already in memory.
    expect($log)->toHaveCount(2);
})->group('queries');

test('the month form renders in three queries', function () {
    $this->actingAs(aPopulatedUser());

    $log = queriesFor(fn () => Livewire::test('pages::month.show')->assertOk());

    // The cash flow row, the assets, their values. The form fields come from the
    // loaded histories rather than a fourth read of the same table.
    expect($log)->toHaveCount(3);
})->group('queries');

/**
 * Stepping months is the interaction most likely to reintroduce a per-asset query,
 * because "load the values for the new month" is the obvious way to write it.
 *
 * Three per step, not zero: every Livewire roundtrip is a fresh request that rebuilds
 * the component from scratch, so each step pays the same three reads an initial render
 * does. What matters is that the number is flat - the fields for the new month come out
 * of the histories already loaded, so ten assets cost the same as one.
 */
test('stepping between months costs a flat three queries per step', function () {
    $this->actingAs(aPopulatedUser());

    $page = Livewire::test('pages::month.show')->assertOk();

    $log = queriesFor(function () use ($page) {
        $page->call('previousMonth')->call('previousMonth')->call('nextMonth');
    });

    expect($log)->toHaveCount(3 * 3);
})->group('queries');

/**
 * The assertion the counts above are really making. An N+1 is a count that grows with
 * the data, so the test that catches one compares two sizes of the same page rather
 * than trusting a number somebody wrote down.
 */
test('page cost does not grow with the number of assets', function () {
    $small = User::factory()->create();
    Asset::factory()->count(2)->for($small)->create()->each(
        fn (Asset $asset) => AssetValue::factory()->for($asset)->create()
    );

    $large = User::factory()->create();
    Asset::factory()->count(40)->for($large)->create()->each(
        fn (Asset $asset) => AssetValue::factory()->for($asset)->create()
    );

    $cost = function (User $user): int {
        $this->actingAs($user);

        return count(queriesFor(fn () => Livewire::test('pages::dashboard')->assertOk()));
    };

    expect($cost($large))->toBe($cost($small));
})->group('queries');
