<?php

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\MonthlyFinance;
use App\Models\User;

function seedRealistic(User $user): void
{
    $types = [AssetType::BankAccount, AssetType::Property, AssetType::Stock, AssetType::Mortgage, AssetType::CreditCard];

    foreach ($types as $i => $type) {
        $asset = Asset::factory()->for($user)->ofType($type)->create(['name' => 'Asset '.$i]);

        for ($back = 11; $back >= 0; $back--) {
            $date = now()->subMonths($back);
            $asset->values()->create([
                'year' => $date->year,
                'month' => $date->month,
                'value' => 100000 + $i * 250000 + $back * 3000,
            ]);
        }
    }

    for ($back = 11; $back >= 0; $back--) {
        $date = now()->subMonths($back);
        MonthlyFinance::create([
            'user_id' => $user->id,
            'year' => $date->year,
            'month' => $date->month,
            'income' => 62000,
            'spending' => 41000 + $back * 500,
        ]);
    }
}

test('every page renders with realistic data and well-formed svg', function () {
    $user = User::factory()->create();
    seedRealistic($user);
    $this->actingAs($user);

    $asset = $user->assets()->first();

    $pages = [
        'dashboard' => route('dashboard'),
        'assets.index' => route('assets.index'),
        'assets.show' => route('assets.show', $asset),
        'month.show' => route('month.show'),
    ];

    foreach ($pages as $name => $url) {
        $html = $this->get($url)->assertOk()->getContent();

        // The classic hand-rolled-SVG failures: NaN or Inf leaking into path data,
        // and negative width/height attributes from an inverted scale.
        expect($html)->not->toContain('NaN')
            ->and($html)->not->toContain('INF')
            ->and($html)->not->toMatch('/(width|height|r)="-/');

        preg_match_all('/ d="([^"]+)"/', $html, $m);
        $paths = $m[1] ?? [];

        foreach ($paths as $d) {
            expect($d)->not->toBeEmpty();
            // Every number in a path must be finite and parseable.
            preg_match_all('/-?\d+(\.\d+)?/', $d, $nums);
            foreach ($nums[0] as $n) {
                expect(is_finite((float) $n))->toBeTrue();
            }
        }

        fwrite(STDERR, sprintf(
            "%-14s %d svg  %d paths  %d rects  %d circles\n",
            $name,
            substr_count($html, '<svg'),
            count($paths),
            substr_count($html, '<rect'),
            substr_count($html, '<circle')
        ));
    }
});

test('the dashboard renders kr amounts and no raw laravel chrome', function () {
    $user = User::factory()->create();
    seedRealistic($user);
    $this->actingAs($user);

    $html = $this->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain('kr')
        ->and($html)->not->toContain('placeholder-pattern')
        ->and($html)->not->toContain('M17.2 5.633');   // the Laravel logo path

    // Chart colour tokens must be referenced by role, never raw hex in markup.
    expect($html)->toContain('var(--viz-1)');

    fwrite(STDERR, "dashboard: kr present, laravel logo gone, tokens by role\n");
});
