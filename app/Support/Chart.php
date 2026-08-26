<?php

namespace App\Support;

/**
 * Geometry helpers for the hand-rolled SVG charts in resources/views/components/chart.
 *
 * This lives in app/ rather than inside the Blade components on purpose: phpstan.neon
 * does not scan resources/, so arithmetic written in a template is neither type-checked
 * nor reachable from a unit test. Keeping the maths here makes it both.
 *
 * Every method is pure - it takes numbers and returns numbers or an SVG path string.
 * Nothing here knows about colours, themes or Blade.
 */
class Chart
{
    /**
     * Map a value from one range onto another, clamping nothing.
     *
     * Returns the midpoint of the target range when the domain is empty, which keeps a
     * flat series (every value identical) drawn as a centred horizontal line instead of
     * dividing by zero.
     */
    public static function scale(float $value, float $domainMin, float $domainMax, float $rangeMin, float $rangeMax): float
    {
        $span = $domainMax - $domainMin;

        if ($span === 0.0) {
            return ($rangeMin + $rangeMax) / 2;
        }

        return $rangeMin + (($value - $domainMin) / $span) * ($rangeMax - $rangeMin);
    }

    /**
     * A y-axis domain that always includes zero and is padded to a round number.
     *
     * Net worth can be negative, so the domain is not assumed to start at zero - but it
     * must always *contain* zero, otherwise the baseline sits off-canvas and the area
     * fill reads as though it were measured from an arbitrary floor.
     *
     * @param  array<int, float>  $values
     * @return array{0: float, 1: float}
     */
    public static function domain(array $values): array
    {
        $values = array_map(fn ($value): float => (float) $value, $values);

        $min = min([0.0, ...$values]);
        $max = max([0.0, ...$values]);

        if ($min === 0.0 && $max === 0.0) {
            return [0.0, 1.0];
        }

        $step = self::niceStep(($max - $min) / 4);

        return [floor($min / $step) * $step, ceil($max / $step) * $step];
    }

    /**
     * Round a raw interval up to a human one: 1, 2, 2.5 or 5 times a power of ten.
     *
     * Axis ticks land on numbers a reader recognises (0 / 5 000 / 10 000) rather than
     * whatever the data divided by four happened to produce.
     */
    public static function niceStep(float $raw): float
    {
        if ($raw <= 0.0) {
            return 1.0;
        }

        $magnitude = 10 ** floor(log10($raw));
        $normalised = $raw / $magnitude;

        $factor = match (true) {
            $normalised <= 1.0 => 1.0,
            $normalised <= 2.0 => 2.0,
            $normalised <= 2.5 => 2.5,
            $normalised <= 5.0 => 5.0,
            default => 10.0,
        };

        return $factor * $magnitude;
    }

    /**
     * Evenly spaced tick values across a domain, inclusive of both ends.
     *
     * @return array<int, float>
     */
    public static function ticks(float $min, float $max, int $count = 4): array
    {
        if ($count < 1 || $min === $max) {
            return [$min];
        }

        $step = ($max - $min) / $count;

        return array_map(fn (int $i): float => $min + $step * $i, range(0, $count));
    }

    /**
     * The x position of the nth of $count points spread across a width.
     *
     * A single point sits in the middle rather than hugging the left edge.
     */
    public static function pointX(int $index, int $count, float $left, float $width): float
    {
        if ($count <= 1) {
            return $left + $width / 2;
        }

        return $left + ($index / ($count - 1)) * $width;
    }

    /**
     * An SVG polyline path through a series.
     *
     * @param  array<int, float>  $values
     */
    public static function linePath(array $values, float $domainMin, float $domainMax, float $left, float $top, float $width, float $height): string
    {
        $count = count($values);

        if ($count === 0) {
            return '';
        }

        $commands = [];

        foreach (array_values($values) as $index => $value) {
            $x = self::pointX($index, $count, $left, $width);
            $y = self::scale((float) $value, $domainMin, $domainMax, $top + $height, $top);

            $commands[] = ($index === 0 ? 'M' : 'L').self::round($x).' '.self::round($y);
        }

        return implode(' ', $commands);
    }

    /**
     * The same series closed down to a baseline, for the area wash under the line.
     *
     * @param  array<int, float>  $values
     */
    public static function areaPath(array $values, float $domainMin, float $domainMax, float $left, float $top, float $width, float $height, float $baselineValue = 0.0): string
    {
        $line = self::linePath($values, $domainMin, $domainMax, $left, $top, $width, $height);

        if ($line === '') {
            return '';
        }

        $count = count($values);
        $baselineY = self::scale($baselineValue, $domainMin, $domainMax, $top + $height, $top);
        $lastX = self::pointX($count - 1, $count, $left, $width);
        $firstX = self::pointX(0, $count, $left, $width);

        return $line
            .' L'.self::round($lastX).' '.self::round($baselineY)
            .' L'.self::round($firstX).' '.self::round($baselineY).' Z';
    }

    /**
     * A column with only its top rounded, square where it meets the baseline.
     *
     * Rounding all four corners (what `<rect rx>` gives you) detaches a column from its
     * axis and makes short bars look like pills. The radius is capped at the bar's own
     * height and half its width, so a very short bar degrades to a dome rather than
     * inverting into a shape with negative geometry.
     */
    public static function barPath(float $x, float $y, float $width, float $height, float $radius = 4.0): string
    {
        if ($width <= 0 || $height <= 0) {
            return '';
        }

        $r = min($radius, $height, $width / 2);

        return 'M'.self::round($x).' '.self::round($y + $height)
            .' V'.self::round($y + $r)
            .' Q'.self::round($x).' '.self::round($y).' '.self::round($x + $r).' '.self::round($y)
            .' H'.self::round($x + $width - $r)
            .' Q'.self::round($x + $width).' '.self::round($y).' '.self::round($x + $width).' '.self::round($y + $r)
            .' V'.self::round($y + $height)
            .' Z';
    }

    /**
     * Each value's share of the total, as a fraction between 0 and 1.
     *
     * Negatives are floored at zero: a part-to-whole chart cannot represent a negative
     * share, and letting one through would make every other segment overstate itself.
     * An empty or all-zero input returns zeros rather than dividing by zero.
     *
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    public static function shares(array $values): array
    {
        $values = array_map(fn ($value): float => max(0.0, (float) $value), array_values($values));
        $total = array_sum($values);

        if ($total <= 0.0) {
            return array_map(fn (): float => 0.0, $values);
        }

        return array_map(fn (float $value): float => $value / $total, $values);
    }

    /**
     * Trim floating point noise out of path data so the markup stays readable.
     */
    public static function round(float $value): float
    {
        return round($value, 2);
    }
}
