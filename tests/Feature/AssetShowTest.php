<?php

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\User;
use Livewire\Livewire;

test('an owner can view their own asset', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->ofType(AssetType::Stock)->create(['name' => 'Index fund']);
    $asset->values()->create(['year' => 2026, 'month' => 7, 'value' => 120000]);
    $asset->values()->create(['year' => 2026, 'month' => 8, 'value' => 135000]);

    Livewire::test('pages::assets.show', ['asset' => $asset->id])
        ->assertOk()
        ->assertSee('Index fund')
        ->assertSee('Stocks &amp; funds', escape: false);
});

/**
 * The scoping rule from hour 4, now on a route parameter rather than a form field.
 *
 * Implicit route-model binding would have resolved this id happily - Route::livewire
 * with an {asset} parameter hands mount() whatever integer is in the URL, and
 * Asset::findOrFail() does not care who owns the row. Resolving through
 * Auth::user()->assets() is what turns a foreign id into a 404.
 */
test('one user cannot view another user\'s asset', function () {
    $victim = User::factory()->create();
    $attacker = User::factory()->create();
    $this->actingAs($attacker);

    $victimAsset = Asset::factory()->for($victim)->create(['name' => 'Secret cabin']);

    // Positive control: the attacker's own asset resolves, so a 404 below means
    // "scoped out", not "the page is broken for everyone".
    $ownAsset = Asset::factory()->for($attacker)->create(['name' => 'My account']);
    $this->get(route('assets.show', $ownAsset))->assertOk()->assertSee('My account');

    $this->get(route('assets.show', $victimAsset))
        ->assertNotFound()
        ->assertDontSee('Secret cabin');
});

test('a missing asset is a 404 rather than an error', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('assets.show', 999999))->assertNotFound();
});

test('guests are redirected to the login page', function () {
    $asset = Asset::factory()->create();

    $this->get(route('assets.show', $asset))->assertRedirect(route('login'));
});

test('an asset with no recorded values still renders', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->create(['name' => 'Brand new']);

    $this->get(route('assets.show', $asset))
        ->assertOk()
        ->assertSee('Brand new')
        ->assertSee('No values recorded yet.');
});
