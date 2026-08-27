<?php

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

/**
 * A value recorded once is a standing claim about what something is worth, not a
 * statement about one calendar month. A house valued in March is still worth roughly
 * that in August; nobody re-appraises a house monthly.
 *
 * Reading only the current month meant net worth collapsed to zero on the first of
 * every month and stayed there until the user re-entered every asset by hand.
 */
test('an asset keeps its last recorded value in months where nothing was entered', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->ofType(AssetType::Property)->create(['name' => 'Huset']);

    $recorded = now()->subMonths(3);
    $asset->values()->create(['year' => $recorded->year, 'month' => $recorded->month, 'value' => 4_000_000]);

    expect($asset->valueAt(now()->year, now()->month))->toBe(4_000_000.0);
});

/**
 * Carry forward, never backward. Before an asset's first recorded value it did not
 * exist as far as this app knows, and back-filling would rewrite history - a house
 * bought in June would appear to have been owned all year.
 */
test('an asset is worth nothing before its first recorded value', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->ofType(AssetType::Property)->create();

    $bought = now()->subMonths(2);
    $asset->values()->create(['year' => $bought->year, 'month' => $bought->month, 'value' => 4_000_000]);

    $before = now()->subMonths(5);

    expect($asset->valueAt($before->year, $before->month))->toBe(0.0);
});

/**
 * The nearest value at or before the month wins, not the newest overall - otherwise
 * every historical point on the net worth chart would show today's figure and the
 * trend line would be flat.
 */
test('a month reads the nearest earlier value, not the newest one', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->ofType(AssetType::BankAccount)->create();

    foreach ([[6, 10_000], [3, 20_000], [0, 30_000]] as [$back, $value]) {
        $at = now()->subMonths($back);
        $asset->values()->create(['year' => $at->year, 'month' => $at->month, 'value' => $value]);
    }

    // Four months back sits between the 10 000 entry and the 20 000 one. The earlier of
    // the two wins: the 20 000 had not been recorded yet at that point in time.
    $fourBack = now()->subMonths(4);

    expect($asset->valueAt($fourBack->year, $fourBack->month))->toBe(10_000.0)
        ->and($asset->valueAt(now()->year, now()->month))->toBe(30_000.0);
});

/**
 * The dashboard's hero figure. This is the bug in its most visible form: on the first
 * of the month, before anything is entered, net worth read 0 kr.
 */
test('dashboard net worth counts assets last valued in an earlier month', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $last = now()->subMonth();

    Asset::factory()->for($user)->ofType(AssetType::BankAccount)->create(['name' => 'Sparekonto'])
        ->values()->create(['year' => $last->year, 'month' => $last->month, 'value' => 250_000]);

    Asset::factory()->for($user)->ofType(AssetType::Mortgage)->create(['name' => 'Boliglån'])
        ->values()->create(['year' => $last->year, 'month' => $last->month, 'value' => 100_000]);

    Livewire::test('pages::dashboard')->assertSee(Money::kr(150_000));
});

/**
 * The assets page loads a bounded window of values so each row can draw a sparkline.
 * The current value was then read off that same window, so anything not touched
 * within it counted as zero - and the totals quietly understated net worth by the
 * value of every long-lived asset, which is exactly the set a house belongs to.
 */
test('assets page totals include an asset last valued before the sparkline window', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $stale = now()->subMonths(18);

    Asset::factory()->for($user)->ofType(AssetType::Property)->create(['name' => 'Huset'])
        ->values()->create(['year' => $stale->year, 'month' => $stale->month, 'value' => 4_000_000]);

    Livewire::test('pages::assets.index')->assertSee(Money::kr(4_000_000));
});
