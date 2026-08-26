<?php

use App\Support\Chart;

test('scale maps a value between two ranges', function () {
    expect(Chart::scale(5, 0, 10, 0, 100))->toBe(50.0)
        ->and(Chart::scale(0, 0, 10, 100, 0))->toBe(100.0);
});

test('scale centres a flat series instead of dividing by zero', function () {
    // Every value identical means an empty domain. Returning the midpoint draws a
    // centred horizontal line; dividing would be a DivisionByZeroError.
    expect(Chart::scale(7, 7, 7, 0, 200))->toBe(100.0);
});

test('the domain always contains zero', function () {
    // A baseline off-canvas makes an area fill look measured from an arbitrary floor.
    [$min, $max] = Chart::domain([500.0, 900.0]);
    expect($min)->toBe(0.0)->and($max)->toBeGreaterThanOrEqual(900.0);

    [$min, $max] = Chart::domain([-4000.0, -1000.0]);
    expect($max)->toBe(0.0)->and($min)->toBeLessThanOrEqual(-4000.0);
});

test('the domain straddles zero when the data does', function () {
    [$min, $max] = Chart::domain([-2000.0, 5000.0]);

    expect($min)->toBeLessThanOrEqual(-2000.0)
        ->and($max)->toBeGreaterThanOrEqual(5000.0);
});

test('an all-zero series gets a usable domain', function () {
    expect(Chart::domain([0.0, 0.0]))->toBe([0.0, 1.0]);
});

test('nice steps land on numbers a reader recognises', function () {
    expect(Chart::niceStep(1))->toBe(1.0)
        ->and(Chart::niceStep(1.5))->toBe(2.0)
        ->and(Chart::niceStep(2.3))->toBe(2.5)
        ->and(Chart::niceStep(3))->toBe(5.0)
        ->and(Chart::niceStep(7))->toBe(10.0)
        ->and(Chart::niceStep(23000))->toBe(25000.0);
});

test('nice step never returns zero for a degenerate input', function () {
    // A zero step would make every tick land on the same line.
    expect(Chart::niceStep(0))->toBe(1.0)
        ->and(Chart::niceStep(-5))->toBe(1.0);
});

test('ticks span the domain inclusively', function () {
    expect(Chart::ticks(0, 100, 4))->toBe([0.0, 25.0, 50.0, 75.0, 100.0]);
});

test('a single point sits in the middle rather than at the left edge', function () {
    expect(Chart::pointX(0, 1, 50, 600))->toBe(350.0)
        ->and(Chart::pointX(0, 3, 50, 600))->toBe(50.0)
        ->and(Chart::pointX(2, 3, 50, 600))->toBe(650.0);
});

test('a column is rounded at the data end and square at the baseline', function () {
    $path = Chart::barPath(10, 20, 24, 100, 4);

    // Starts at the baseline (y = 20 + 100) and closes there, so the bar is anchored
    // to the axis; the quadratic curves are all at the top.
    expect($path)->toStartWith('M10 120')
        ->and($path)->toEndWith('Z')
        ->and(substr_count($path, 'Q'))->toBe(2);
});

test('a bar shorter than its radius degrades instead of inverting', function () {
    $path = Chart::barPath(0, 0, 24, 2, 4);

    expect($path)->not->toBeEmpty()
        ->and($path)->not->toContain('-');
});

test('a zero-size bar produces no path at all', function () {
    expect(Chart::barPath(0, 0, 0, 100))->toBe('')
        ->and(Chart::barPath(0, 0, 24, 0))->toBe('');
});

test('shares are proportions of the total', function () {
    expect(Chart::shares([50.0, 30.0, 20.0]))->toBe([0.5, 0.3, 0.2]);
});

test('shares of an empty or all-zero input are zeros, not a division by zero', function () {
    expect(Chart::shares([]))->toBe([])
        ->and(Chart::shares([0.0, 0.0]))->toBe([0.0, 0.0]);
});

test('negative values are floored so other shares are not overstated', function () {
    // A part-to-whole chart cannot represent a negative share. Letting one through
    // would shrink the denominator and make every other segment claim too much.
    expect(Chart::shares([100.0, -50.0]))->toBe([1.0, 0.0]);
});

test('shares always sum to one when there is any positive value', function () {
    expect(array_sum(Chart::shares([7.0, 11.0, 3.0, 0.0])))->toBe(1.0);
});

test('line and area paths are empty for an empty series', function () {
    expect(Chart::linePath([], 0, 1, 0, 0, 100, 100))->toBe('')
        ->and(Chart::areaPath([], 0, 1, 0, 0, 100, 100))->toBe('');
});

test('an area path closes back to the baseline', function () {
    $path = Chart::areaPath([10.0, 20.0], 0.0, 20.0, 0.0, 0.0, 100.0, 100.0);

    expect($path)->toEndWith('Z')
        ->and($path)->toStartWith('M0 ');
});
