<?php

use App\Support\Money;

test('amounts are formatted as kroner with a non-breaking space', function () {
    // The thousands separator is a non-breaking space so an amount never wraps
    // mid-number, and the same character sits before "kr".
    expect(Money::kr(45000))->toBe("45\u{00A0}000\u{00A0}kr")
        ->and(Money::kr(999))->toBe("999\u{00A0}kr")
        ->and(Money::kr(1234567))->toBe("1\u{00A0}234\u{00A0}567\u{00A0}kr");
});

test('decimals use a comma, as Norwegian convention wants', function () {
    expect(Money::kr(1234.5, 2))->toBe("1\u{00A0}234,50\u{00A0}kr");
});

test('decimal cast strings are accepted, not just floats', function () {
    // Eloquent's decimal:2 cast hands back strings, so this is the common case
    // rather than an edge one.
    expect(Money::kr('50000.00'))->toBe("50\u{00A0}000\u{00A0}kr");
});

test('null reads as zero rather than blowing up', function () {
    expect(Money::kr(null))->toBe("0\u{00A0}kr");
});

test('negative amounts keep their sign', function () {
    expect(Money::kr(-2500))->toBe("-2\u{00A0}500\u{00A0}kr");
});

test('the compact form is used for axis ticks', function () {
    expect(Money::compact(0))->toBe('0')
        ->and(Money::compact(999))->toBe('999')
        ->and(Money::compact(45000))->toBe('45k')
        ->and(Money::compact(1200000))->toBe('1,2M')
        ->and(Money::compact(15000000))->toBe('15M');
});

test('the compact form signs negatives so a negative axis reads correctly', function () {
    expect(Money::compact(-45000))->toBe('-45k')
        ->and(Money::compact(-1200000))->toBe('-1,2M');
});

test('the bare number form omits the suffix for columns', function () {
    expect(Money::number(45000))->toBe("45\u{00A0}000");
});
