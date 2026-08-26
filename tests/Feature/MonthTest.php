<?php

use App\Models\Asset;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

test('saving cash flow stores the row for the selected month', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Deliberately not the current month, so nothing below can pass by coinciding
    // with mount()'s now() fallback.
    $date = now()->subYear()->subMonth();

    expect($date->year)->not->toBe(now()->year)
        ->and($date->month)->not->toBe(now()->month);

    Livewire::withQueryParams(['year' => $date->year, 'month' => $date->month]);

    Livewire::test('pages::month.show')
        ->set('income', 50000)
        ->set('spending', 30000)
        ->call('saveCashFlow')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('monthly_finances', [
        'user_id' => $user->id,
        'year' => $date->year,
        'month' => $date->month,
        'income' => 50000,
        'spending' => 30000,
    ]);

    // savings is derived on the model rather than stored, so it is invisible to
    // assertDatabaseHas. Read it back through Eloquent instead. sole() also asserts
    // that updateOrCreate produced exactly one row rather than duplicating.
    $finance = $user->monthlyFinances()
        ->where('year', $date->year)
        ->where('month', $date->month)

        ->sole();

    // The accessor casts to float; income/spending stay decimal:2 strings.
    expect($finance->savings)->toBe(20000.0)
        ->and($finance->income)->toBe('50000.00');
});

/**
 * The #[Url] gotcha: query-string properties hydrate BEFORE mount() runs, so an
 * unconditional assignment in mount() would discard them. Pin that behaviour down
 * with Livewire::withQueryParams(['year' => ..., 'month' => ...]) and assert the
 * component kept them rather than overwriting with now().
 */
test('year and month come from the query string, not the current date', function () {
    $this->actingAs(User::factory()->create());
    $date = now()->subYear()->subMonth();
    $year = $date->year;
    $month = $date->month;

    expect($year)->not->toBe(now()->year)
        ->and($month)->not->toBe(now()->month);

    Livewire::withQueryParams(['year' => $year, 'month' => $month]);

    Livewire::test('pages::month.show')
        ->assertSet('year', $year)
        ->assertSet('month', $month);
});

/**
 * The other half of `??=`: with a bare URL there is nothing to hydrate from, so
 * mount() must supply the current month. This test cannot detect the clobber bug
 * above — `=` and `??=` both produce now() here — and does not need to. It exists
 * to catch the default being removed.
 */
test('year and month fall back to the current month when the url is bare', function () {
    $this->actingAs(User::factory()->create());

    $now = now();

    Livewire::test('pages::month.show')
        ->assertSet('year', $now->year)
        ->assertSet('month', $now->month);
});

/**
 * The auth-scoping rule: saveAssetValues() iterates $this->assets (server-owned)
 * and uses $this->values only for lookup, so a forged asset id from the client
 * never matches. Set $values with another user's asset id and assert zero rows
 * were written for it.
 */
test('a forged asset id cannot write to another user\'s asset', function () {
    $victim = User::factory()->create();
    $attacker = User::factory()->create();
    $this->actingAs($attacker);

    $victimAsset = Asset::factory()->for($victim)->create();
    $attackerAsset = Asset::factory()->for($attacker)->create();

    Livewire::test('pages::month.show')->set('values', [
        $attackerAsset->id => 5000,     // positive control
        $victimAsset->id => 999999,   // forged
    ])
        ->call('saveAssetValues');

    $this->assertDatabaseMissing('asset_values', ['asset_id' => $victimAsset->id]);

    $this->assertDatabaseHas('asset_values', [
        'asset_id' => $attackerAsset->id,
        'year' => now()->year,
        'month' => now()->month,
        'value' => 5000,
    ]);

    $this->assertDatabaseCount('asset_values', 1);   // blunt but effective

});

test('a grouped amount saves as the number it looks like', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // What a user sees in the field, and what a paste from elsewhere in the app looks
    // like. Without normalisation (float) '62 000' is 62.0, so this would either fail
    // validation or store a wildly wrong figure.
    Livewire::test('pages::month.show')
        ->set('income', "62\u{00A0}000")
        ->set('spending', '41 000')
        ->call('saveCashFlow')
        ->assertHasNoErrors();

    $finance = $user->monthlyFinances()->sole();

    expect((float) $finance->income)->toBe(62000.0)
        ->and((float) $finance->spending)->toBe(41000.0)
        ->and($finance->savings)->toBe(21000.0);
});

test('the live savings readout is right while a grouped number is being typed', function () {
    $this->actingAs(User::factory()->create());

    // The computed property runs on every keystroke, before any save. A bare cast here
    // would render "Saved: 21 kr" for an income of 62 000 - wrong, and silently so.
    Livewire::test('pages::month.show')
        ->set('income', '62 000')
        ->set('spending', '41 000')
        // Money::kr() emits non-breaking spaces, so assert against the formatter rather
        // than a literal with ordinary spaces - which is what made this test fail first.
        ->assertSee(Money::kr(21000));
});

test('a grouped decimal keeps its fractional part', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::month.show')
        ->set('income', '62 000,50')
        ->set('spending', '0')
        ->call('saveCashFlow')
        ->assertHasNoErrors();

    expect((float) $user->monthlyFinances()->sole()->income)->toBe(62000.50);
});

test('grouped asset values save correctly too', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->create();

    Livewire::test('pages::month.show')
        ->set('values', [$asset->id => '4 250 000'])
        ->call('saveAssetValues')
        ->assertHasNoErrors();

    expect((float) $asset->values()->sole()->value)->toBe(4250000.0);
});

test('genuinely non-numeric input is still rejected', function () {
    $this->actingAs(User::factory()->create());

    // Normalisation must not be so eager that it invents a number out of nonsense.
    Livewire::test('pages::month.show')
        ->set('income', 'not a number')
        ->set('spending', '0')
        ->call('saveCashFlow')
        ->assertHasErrors(['income' => 'numeric']);
});
