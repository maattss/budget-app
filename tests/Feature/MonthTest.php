<?php

use App\Models\User;
use Livewire\Livewire;

test('saving cash flow stores the row for the selected month', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::month.show')
        ->set('income', 50000)
        ->set('spending', 30000)
        ->call('saveCashFlow')
        ->assertHasNoErrors();

    // Then assert the row actually landed, and that savings derives correctly.
    // $this->assertDatabaseHas(...)
});

/**
 * The #[Url] gotcha: query-string properties hydrate BEFORE mount() runs, so an
 * unconditional assignment in mount() would discard them. Pin that behaviour down
 * with Livewire::withQueryParams(['year' => ..., 'month' => ...]) and assert the
 * component kept them rather than overwriting with now().
 */
test('year and month come from the query string, not the current date', function () {
    //
})->todo();

/**
 * The auth-scoping rule: saveAssetValues() iterates $this->assets (server-owned)
 * and uses $this->values only for lookup, so a forged asset id from the client
 * never matches. Set $values with another user's asset id and assert zero rows
 * were written for it.
 */
test('a forged asset id cannot write to another user\'s asset', function () {
    //
})->todo();
