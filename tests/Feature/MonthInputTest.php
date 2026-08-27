<?php

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\User;
use Livewire\Livewire;

/**
 * Blanking a field and saving used to be a silent no-op: the loop skipped empty
 * strings, so the old row survived and the page redisplayed it on the next load. The
 * user is told the save succeeded while the number they just deleted comes straight
 * back. Now that values carry forward, a wrong figure left in place propagates into
 * every later month too.
 */
test('clearing a value removes the recorded row', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->ofType(AssetType::BankAccount)->create();
    $asset->values()->create(['year' => now()->year, 'month' => now()->month, 'value' => 1_234]);

    Livewire::test('pages::month.show')
        ->set('values.'.$asset->id, '')
        ->call('saveAssetValues')
        ->assertHasNoErrors();

    expect($asset->values()->count())->toBe(0);
});

/**
 * Only the month on screen. Clearing August must not touch July's row, or stepping
 * back through an untouched month would erase it on the way past.
 */
test('clearing a value leaves other months alone', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->ofType(AssetType::BankAccount)->create();

    $last = now()->subMonth();
    $asset->values()->create(['year' => $last->year, 'month' => $last->month, 'value' => 500]);
    $asset->values()->create(['year' => now()->year, 'month' => now()->month, 'value' => 900]);

    Livewire::test('pages::month.show')
        ->set('values.'.$asset->id, '')
        ->call('saveAssetValues')
        ->assertHasNoErrors();

    expect($asset->values()->count())->toBe(1)
        ->and((float) $asset->values()->sole()->value)->toBe(500.0);
});

/**
 * $year and $month are #[Url], so they arrive from the address bar and cannot be
 * trusted. ?month=99 was accepted verbatim, and Carbon overflowed it: one click of
 * "previous month" from month 99 of 2026 landed the user in February 2034.
 */
test('an out-of-range month in the url falls back to the current month', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::withQueryParams(['year' => 2026, 'month' => 99])
        ->test('pages::month.show')
        ->assertSet('year', now()->year)
        ->assertSet('month', now()->month);
});

test('an implausible year in the url falls back to the current month', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::withQueryParams(['year' => 999999, 'month' => 3])
        ->test('pages::month.show')
        ->assertSet('year', now()->year)
        ->assertSet('month', now()->month);
});

/**
 * A month the user could plausibly want is left alone - the guard exists to reject
 * nonsense, not to confine the user to the current month.
 */
test('a real month in the url is kept', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $past = now()->subMonths(14);

    Livewire::withQueryParams(['year' => $past->year, 'month' => $past->month])
        ->test('pages::month.show')
        ->assertSet('year', $past->year)
        ->assertSet('month', $past->month);
});

/**
 * The field is labelled with the asset's name, so the error must not introduce a
 * different noun - least of all "values.7", which names an array index the user has
 * never seen.
 */
test('a rejected value is explained without leaking the field name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->ofType(AssetType::BankAccount)->create();

    $errors = Livewire::test('pages::month.show')
        ->set('values.'.$asset->id, '-500')
        ->call('saveAssetValues')
        ->assertHasErrors('values.'.$asset->id)
        ->errors()
        ->get('values.'.$asset->id);

    expect($errors[0])->not->toContain('values.')
        ->and($errors[0])->not->toContain((string) $asset->id);
});
