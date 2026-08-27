<?php

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * The reason editing exists at all: a car recorded before there was a Car type sits
 * as "Other asset", and the only alternative was deleting it - which takes every
 * value recorded against it with it.
 */
test('an asset can be renamed and re-typed without losing its values', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->ofType(AssetType::OtherAsset)->create(['name' => 'Bilen']);

    $asset->values()->create(['year' => 2026, 'month' => 7, 'value' => 300000]);
    $asset->values()->create(['year' => 2026, 'month' => 8, 'value' => 295000]);

    Livewire::test('pages::assets.index')
        ->call('edit', $asset->id)
        ->assertSet('editingName', 'Bilen')
        ->assertSet('editingType', AssetType::OtherAsset->value)
        ->set('editingName', 'Bil')
        ->set('editingType', AssetType::Car->value)
        ->call('saveEdit')
        ->assertHasNoErrors();

    $asset->refresh();

    expect($asset->name)->toBe('Bil')
        ->and($asset->type)->toBe(AssetType::Car)
        ->and($asset->values)->toHaveCount(2);
});

/**
 * $editingId is a public property, so it arrives from the browser and cannot be
 * trusted. This is the same class of hole MonthTest pins down for asset values -
 * Asset::find($this->editingId) reads innocently and hands back anyone's row.
 */
test('a forged asset id cannot edit another user\'s asset', function () {
    $victim = User::factory()->create();
    $attacker = User::factory()->create();
    $this->actingAs($attacker);

    $victimAsset = Asset::factory()->for($victim)->ofType(AssetType::Property)->create(['name' => 'Their flat']);

    expect(fn () => Livewire::test('pages::assets.index')
        ->set('editingId', $victimAsset->id)
        ->set('editingName', 'Mine now')
        ->set('editingType', AssetType::Car->value)
        ->call('saveEdit')
    )->toThrow(ModelNotFoundException::class);

    $victimAsset->refresh();

    expect($victimAsset->name)->toBe('Their flat')
        ->and($victimAsset->type)->toBe(AssetType::Property);
});

/**
 * Opening the modal is a read of a client-supplied id too, not just saving.
 */
test('another user\'s asset cannot even be opened for editing', function () {
    $victim = User::factory()->create();
    $this->actingAs(User::factory()->create());

    $victimAsset = Asset::factory()->for($victim)->create();

    expect(fn () => Livewire::test('pages::assets.index')->call('edit', $victimAsset->id))
        ->toThrow(ModelNotFoundException::class);
});

test('the edit form validates the same things the add form does', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $asset = Asset::factory()->for($user)->ofType(AssetType::Property)->create(['name' => 'Fabrikkgaten']);

    Livewire::test('pages::assets.index')
        ->call('edit', $asset->id)
        ->set('editingName', '')
        ->call('saveEdit')
        ->assertHasErrors(['editingName' => 'required']);

    Livewire::test('pages::assets.index')
        ->call('edit', $asset->id)
        ->set('editingType', 'not_a_type')
        ->call('saveEdit')
        ->assertHasErrors(['editingType']);

    expect($asset->refresh()->name)->toBe('Fabrikkgaten');
});
