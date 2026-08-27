<?php

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\AssetValue;
use App\Support\Portfolio;
use Illuminate\Database\Eloquent\Collection;

/**
 * Build an asset and its history entirely in memory.
 *
 * No database: Portfolio only ever reads a loaded collection, so its tests do not need
 * one either. That is the point of having extracted it - the same arithmetic sitting
 * inside a Blade page component could only be reached by booting a Livewire component
 * against a migrated SQLite file.
 *
 * @param  array<int, array{0: int, 1: int, 2: float|int}>  $values  [year, month, amount]
 */
function assetNamed(string $name, AssetType $type, array $values = []): Asset
{
    $asset = Asset::make(['name' => $name, 'type' => $type]);

    $asset->setRelation('values', new Collection(array_map(
        fn (array $row): AssetValue => AssetValue::make([
            'year' => $row[0],
            'month' => $row[1],
            'value' => $row[2],
        ]),
        $values
    )));

    return $asset;
}

/**
 * @param  array<int, Asset>  $assets
 */
function portfolioOf(array $assets): Portfolio
{
    return new Portfolio(new Collection($assets));
}

test('it splits what is owned from what is owed', function () {
    $portfolio = portfolioOf([
        assetNamed('Huset', AssetType::Property),
        assetNamed('Boliglån', AssetType::Mortgage),
        assetNamed('Sparekonto', AssetType::BankAccount),
    ]);

    expect($portfolio->owned()->pluck('name')->all())->toBe(['Huset', 'Sparekonto'])
        ->and($portfolio->owed()->pluck('name')->all())->toBe(['Boliglån']);
});

test('net worth is what is owned minus what is owed', function () {
    $portfolio = portfolioOf([
        assetNamed('Huset', AssetType::Property, [[2026, 8, 4_000_000]]),
        assetNamed('Boliglån', AssetType::Mortgage, [[2026, 8, 2_500_000]]),
    ]);

    expect($portfolio->ownedTotal(2026, 8))->toBe(4_000_000.0)
        ->and($portfolio->owedTotal(2026, 8))->toBe(2_500_000.0)
        ->and($portfolio->netWorth(2026, 8))->toBe(1_500_000.0);
});

/**
 * Owing more than you own is an ordinary state - the first years of a mortgage are
 * exactly this - so the figure has to go negative rather than floor at zero.
 */
test('net worth can be negative', function () {
    $portfolio = portfolioOf([
        assetNamed('Huset', AssetType::Property, [[2026, 8, 3_000_000]]),
        assetNamed('Boliglån', AssetType::Mortgage, [[2026, 8, 3_400_000]]),
    ]);

    expect($portfolio->netWorth(2026, 8))->toBe(-400_000.0);
});

/**
 * The bug that started this pass, at the level it should have been testable all along.
 */
test('a month with nothing recorded still counts what was recorded earlier', function () {
    $portfolio = portfolioOf([
        assetNamed('Huset', AssetType::Property, [[2026, 3, 4_000_000]]),
    ]);

    expect($portfolio->netWorth(2026, 8))->toBe(4_000_000.0);
});

test('an empty portfolio is worth nothing rather than erroring', function () {
    $portfolio = portfolioOf([]);

    expect($portfolio->netWorth(2026, 8))->toBe(0.0)
        ->and($portfolio->allocation(2026, 8))->toBe([])
        ->and($portfolio->hasAnyValueBy(2026, 8))->toBeFalse();
});

test('nothing is recorded until the first entry, and everything after it is', function () {
    $portfolio = portfolioOf([
        assetNamed('Sparekonto', AssetType::BankAccount, [[2026, 5, 10_000]]),
    ]);

    expect($portfolio->hasAnyValueBy(2026, 4))->toBeFalse()
        ->and($portfolio->hasAnyValueBy(2026, 5))->toBeTrue()
        // Continuous from there: carried-forward values keep later months defined.
        ->and($portfolio->hasAnyValueBy(2026, 12))->toBeTrue();
});

/**
 * A December entry must not count as recorded in the January before it. Comparing the
 * month alone, or the year alone, gets this backwards.
 */
test('the year is not confused with the month across a boundary', function () {
    $portfolio = portfolioOf([
        assetNamed('Sparekonto', AssetType::BankAccount, [[2025, 12, 10_000]]),
    ]);

    expect($portfolio->hasAnyValueBy(2026, 1))->toBeTrue()
        ->and($portfolio->hasAnyValueBy(2025, 11))->toBeFalse()
        ->and($portfolio->netWorth(2026, 1))->toBe(10_000.0);
});

test('allocation groups by type and puts the largest first', function () {
    $allocation = portfolioOf([
        assetNamed('Sparekonto', AssetType::BankAccount, [[2026, 8, 100_000]]),
        assetNamed('Brukskonto', AssetType::BankAccount, [[2026, 8, 50_000]]),
        assetNamed('Huset', AssetType::Property, [[2026, 8, 4_000_000]]),
    ])->allocation(2026, 8);

    expect($allocation)->toHaveCount(2)
        ->and($allocation[0]['name'])->toBe('Property')
        ->and($allocation[0]['value'])->toBe(4_000_000.0)
        // The two accounts are one slice, not two.
        ->and($allocation[1]['name'])->toBe('Bank account')
        ->and($allocation[1]['value'])->toBe(150_000.0);
});

/**
 * A part-to-whole chart mixing what you own with what you owe has no meaningful whole,
 * so the mortgage must not appear as a slice of the things you own.
 */
test('allocation leaves liabilities out', function () {
    $allocation = portfolioOf([
        assetNamed('Huset', AssetType::Property, [[2026, 8, 4_000_000]]),
        assetNamed('Boliglån', AssetType::Mortgage, [[2026, 8, 2_500_000]]),
    ])->allocation(2026, 8);

    expect($allocation)->toHaveCount(1)
        ->and($allocation[0]['name'])->toBe('Property');
});

test('allocation leaves out a type with nothing in it', function () {
    $allocation = portfolioOf([
        assetNamed('Huset', AssetType::Property, [[2026, 8, 4_000_000]]),
        assetNamed('Tom konto', AssetType::BankAccount, [[2026, 8, 0]]),
        assetNamed('Aldri ført', AssetType::Cash),
    ])->allocation(2026, 8);

    expect($allocation)->toHaveCount(1)
        ->and($allocation[0]['name'])->toBe('Property');
});

/**
 * Colour follows the entity, never its rank - a type keeps its slot as other types come
 * and go, so a reader's memory of "the violet one is stocks" survives a reordering.
 */
test('allocation carries each type\'s fixed palette slot', function () {
    $allocation = portfolioOf([
        assetNamed('Fond', AssetType::Stock, [[2026, 8, 200_000]]),
    ])->allocation(2026, 8);

    expect($allocation[0]['var'])->toBe('--viz-'.AssetType::Stock->seriesSlot());
});
