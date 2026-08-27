<?php

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

test('a car counts towards net worth rather than against it', function () {
    expect(AssetType::Car->isLiability())->toBeFalse()
        ->and(AssetType::assets())->toContain(AssetType::Car)
        ->and(AssetType::liabilities())->not->toContain(AssetType::Car);
});

/**
 * icon() names are strings, so a typo or a Heroicon that does not exist fails at
 * render time on whichever page happens to show that type - not here, and not in the
 * test suite at all unless something walks every case. 'car' in particular has no
 * Heroicon and is drawn locally, so this is what proves the local one is found.
 */
test('every asset type has an icon that renders', function () {
    foreach (AssetType::cases() as $type) {
        $svg = Blade::render('<flux:icon :name="$name" />', ['name' => $type->icon()]);

        expect($svg)->toContain('<svg')
            ->and($svg)->toContain('data-flux-icon');
    }
});

/**
 * Colours are allowed to repeat across the two groups but never within one, because
 * only within a group do two types share a chart. Assert the rule rather than the
 * specific numbers, so slots can be reshuffled without editing this test.
 */
test('no two types in the same group share a palette slot', function () {
    foreach ([AssetType::assets(), AssetType::liabilities()] as $group) {
        $slots = array_map(fn (AssetType $type): int => $type->seriesSlot(), $group);

        expect($slots)->toHaveCount(count(array_unique($slots)));
    }
});

test('a car is worth something on the dashboard, not nothing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $car = Asset::factory()->for($user)->ofType(AssetType::Car)->create(['name' => 'Bil']);

    $car->values()->create([
        'year' => now()->year,
        'month' => now()->month,
        'value' => 300000,
    ]);

    // The sign is the whole point, and it is what a name-and-amount assertion misses:
    // classify Car as a liability and "Bil" plus "300 000 kr" still appear on the page,
    // just under the wrong heading and with net worth inverted.
    Livewire::test('pages::dashboard')
        ->assertSee('Bil')
        ->assertSee(Money::kr(300000))
        ->assertDontSee(Money::kr(-300000));
});
