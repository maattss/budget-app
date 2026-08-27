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

/**
 * The form reads what was recorded for this month, never what was carried into it.
 *
 * Net worth carries the last known value forward, and it would be easy to make the
 * form do the same "for convenience". It must not: a carried figure in an empty box
 * is a number the user never typed, and saving the form would write it back as though
 * they had. One unedited visit to an old month and a guess becomes a record - which
 * then carries forward itself.
 */
test('a field stays empty in a month nothing was recorded in', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->ofType(AssetType::Property)->create();

    $recorded = now()->subMonths(3);
    $asset->values()->create(['year' => $recorded->year, 'month' => $recorded->month, 'value' => 4_000_000]);

    Livewire::test('pages::month.show')
        ->assertSet('values.'.$asset->id, '')
        // Saving an untouched form must not turn the carried value into a new row.
        ->call('saveAssetValues')
        ->assertHasNoErrors();

    expect($asset->values()->count())->toBe(1);
});

/**
 * Stepping between months re-reads the fields from the loaded histories rather than
 * requerying, so this guards the memo as much as the display: a stale collection would
 * show the previous month's figures under the new month's heading.
 */
test('stepping to another month shows that month\'s figures', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->ofType(AssetType::BankAccount)->create();

    $last = now()->subMonth();
    $asset->values()->create(['year' => $last->year, 'month' => $last->month, 'value' => 111]);
    $asset->values()->create(['year' => now()->year, 'month' => now()->month, 'value' => 222]);

    Livewire::test('pages::month.show')
        ->assertSet('values.'.$asset->id, '222')
        ->call('previousMonth')
        ->assertSet('values.'.$asset->id, '111')
        ->call('nextMonth')
        ->assertSet('values.'.$asset->id, '222');
});

/**
 * A save has to invalidate the loaded histories, or the reload that follows it reads a
 * collection older than the write and redisplays the figure the user just replaced.
 */
test('a saved value is what the form shows afterwards', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->ofType(AssetType::BankAccount)->create();
    $asset->values()->create(['year' => now()->year, 'month' => now()->month, 'value' => 100]);

    Livewire::test('pages::month.show')
        ->set('values.'.$asset->id, '999')
        ->call('saveAssetValues')
        ->assertHasNoErrors()
        ->assertSet('values.'.$asset->id, '999');
});
