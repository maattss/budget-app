<?php

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\AssetValue;
use App\Models\MonthlyFinance;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

/**
 * The dashboard shows what the net worth figure is made of, split the way it is
 * calculated. Assert order, not just presence: an asset under the wrong heading is
 * the failure worth catching.
 */
test('the dashboard groups this month\'s holdings into owned and owed', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $now = now();

    foreach ([
        ['name' => 'Bil', 'type' => AssetType::Car, 'value' => 300000],
        ['name' => 'Boliglån', 'type' => AssetType::Mortgage, 'value' => 2000000],
    ] as $row) {
        Asset::factory()->for($user)->ofType($row['type'])->create(['name' => $row['name']])
            ->values()->create(['year' => $now->year, 'month' => $now->month, 'value' => $row['value']]);
    }

    $page = Livewire::test('pages::dashboard')
        ->assertSee(Money::kr(300000))
        ->assertSee(Money::kr(2000000))
        // Net worth stays assets minus liabilities, not the sum of the two panels.
        ->assertSee(Money::kr(-1700000));

    // Absence, not order - see the note on the equivalent month test.
    $html = $page->html();

    expect($html)->toContain('Liabilities');

    [$owned, $owed] = explode('Liabilities', $html, 2);

    expect($owned)->toContain('Bil')
        ->and($owned)->not->toContain('Boliglån')
        ->and($owed)->toContain('Boliglån');
});

/**
 * Every read on this page goes through Auth::user()'s relations, which is what keeps
 * one user's holdings out of another's net worth. That is a property of how the
 * queries are written rather than of anything the framework enforces, so it is worth
 * a test that fails loudly if someone "simplifies" a relation into a bare
 * Asset::all() or MonthlyFinance::all().
 */
test('the dashboard shows nothing belonging to another user', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    Asset::factory()->for($stranger)->ofType(AssetType::Property)->create(['name' => 'Fremmedhus'])
        ->values()->save(AssetValue::factory()->worth(9_000_000)->make());

    MonthlyFinance::factory()->for($stranger)->of(123_456, 1_000)->create();

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->assertDontSee('Fremmedhus')
        ->assertDontSee(Money::kr(9_000_000))
        ->assertDontSee(Money::kr(123_456))
        // Its own net worth, not the stranger's.
        ->assertSee(Money::kr(0));
});
