<?php

use App\Enums\AssetType;
use App\Models\Asset;
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
