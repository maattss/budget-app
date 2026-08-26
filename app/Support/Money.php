<?php

namespace App\Support;

class Money
{
    /**
     * Format an amount as Norwegian kroner: "45 000 kr".
     *
     * Norwegian convention is a space for thousands and a comma for decimals. The
     * space is a non-breaking space so an amount never wraps mid-number.
     *
     * Accepts strings because Eloquent's decimal casts hand back strings rather than
     * floats, and null so callers can pass a missing row straight through.
     */
    public static function kr(float|int|string|null $amount, int $decimals = 0): string
    {
        return self::number($amount, $decimals)."\u{00A0}kr";
    }

    /**
     * The same formatting without the currency suffix, for axis ticks and columns
     * where "kr" would repeat on every row.
     */
    public static function number(float|int|string|null $amount, int $decimals = 0): string
    {
        return number_format((float) ($amount ?? 0), $decimals, ',', "\u{00A0}");
    }

    /**
     * The plain form for a number <input>: no separators, no trailing decimal zeros.
     *
     * Eloquent's decimal:2 cast hands back "62000.00", which is noise in a form field.
     * Deliberately *not* thousand-separated - the value has to survive a round trip
     * through `numeric` validation, and "62 000" does not.
     */
    public static function input(float|int|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        return (string) (float) $amount;
    }

    /**
     * A compact form for chart axes and tight stat tiles: 45 000 -> "45k", 1 200 000 -> "1,2M".
     *
     * Signed, so a negative net worth still reads correctly.
     */
    public static function compact(float|int|string|null $amount): string
    {
        $value = (float) ($amount ?? 0);
        $sign = $value < 0 ? '-' : '';
        $abs = abs($value);

        return match (true) {
            $abs >= 1_000_000 => $sign.number_format($abs / 1_000_000, $abs < 10_000_000 ? 1 : 0, ',', '').'M',
            $abs >= 1_000 => $sign.number_format($abs / 1_000, 0, ',', '').'k',
            default => $sign.number_format($abs, 0, ',', ''),
        };
    }
}
