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

test('the input form strips decimal-cast noise without adding separators', function () {
    // A separator would not survive `numeric` validation on save, so the input form
    // only removes the trailing zeros the decimal:2 cast adds.
    expect(Money::input('62000.00'))->toBe('62000')
        ->and(Money::input('1234.50'))->toBe('1234.5')
        ->and(Money::input(1000))->toBe('1000')
        ->and(Money::input(null))->toBe('')
        ->and(Money::input(''))->toBe('');
});

test('parse strips the group separators a human would type', function () {
    // (float) '62 000' is 62.0 - a grouped number does not fail loudly, it silently
    // becomes a different number. This is the guard against that.
    expect(Money::parse('62 000'))->toBe('62000')
        ->and(Money::parse("62\u{00A0}000"))->toBe('62000')   // non-breaking, what kr() emits
        ->and(Money::parse("1\u{202F}234\u{202F}567"))->toBe('1234567'); // narrow no-break
});

test('parse treats a comma as the decimal separator', function () {
    expect(Money::parse('1234,50'))->toBe('1234.50')
        ->and(Money::parse('62 000,25'))->toBe('62000.25');
});

test('parse reads dots as grouping when a comma comes after them', function () {
    // "1.234,56" is the usual Norwegian paste.
    expect(Money::parse('1.234,56'))->toBe('1234.56');
});

test('parse leaves a plain dot decimal alone', function () {
    expect(Money::parse('1234.56'))->toBe('1234.56');
});

test('parse passes malformed input through for validation to reject', function () {
    // It must not invent a number out of nonsense - "not a number" has to stay
    // something `numeric` refuses.
    expect(Money::parse('12.34.56'))->toBe('12.34.56')
        ->and(is_numeric(Money::parse('12.34.56')))->toBeFalse()
        ->and(Money::parse('abc'))->toBe('abc');
});

test('parse returns an empty string for nothing', function () {
    expect(Money::parse(''))->toBe('')
        ->and(Money::parse(null))->toBe('')
        ->and(Money::parse('   '))->toBe('');
});

test('parse round-trips this app own formatting', function () {
    // kr() out, parse() back in - the pair has to be lossless, since the formatted
    // value is what a user sees and may retype or paste.
    foreach ([0, 999, 45000, 1234567] as $amount) {
        $formatted = str_replace("\u{00A0}kr", '', Money::kr($amount));
        expect((float) Money::parse($formatted))->toBe((float) $amount);
    }
});
