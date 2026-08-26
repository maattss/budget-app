@props([
    'values' => [],
    'var' => '--viz-1',
])

@php
    use App\Support\Chart;

    $values = array_values(array_map(fn ($value): float => (float) $value, $values));

    $w = 96.0;
    $h = 28.0;
    $pad = 3.0;

    // A sparkline is a shape, not a scale - it uses the series' own min/max so a flat
    // line means "no change" rather than "small compared to zero".
    $min = $values === [] ? 0.0 : min($values);
    $max = $values === [] ? 1.0 : max($values);

    if ($min === $max) {
        $min -= 1.0;
        $max += 1.0;
    }

    $count = count($values);
@endphp

@if ($count < 2)
    <span class="inline-block h-7 w-24" aria-hidden="true"></span>
@else
    <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-7 w-24" aria-hidden="true" focusable="false">
        <path
            d="{{ Chart::linePath(array_combine(range(0, $count - 1), $values), $count, $min, $max, $pad, $pad, $w - $pad * 2, $h - $pad * 2) }}"
            fill="none" stroke="var({{ $var }})" stroke-width="2"
            stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"
            opacity="0.55"
        />
        <circle
            cx="{{ Chart::round(Chart::pointX($count - 1, $count, $pad, $w - $pad * 2)) }}"
            cy="{{ Chart::round(Chart::scale($values[$count - 1], $min, $max, $h - $pad, $pad)) }}"
            r="3"
            fill="var({{ $var }})" stroke="var(--viz-surface)" stroke-width="2"
        />
    </svg>
@endif
