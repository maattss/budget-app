@props([
    'points' => [],
    'series' => [],
    'label' => null,
])

@php
    use App\Support\Chart;
    use App\Support\Money;

    // $points: list of ['label' => 'Aug', 'values' => [1000.0, 800.0]]
    // $series: list of ['name' => 'Income', 'var' => '--viz-1']
    $points = array_values($points);
    $series = array_values($series);

    $all = [];
    foreach ($points as $point) {
        foreach ($point['values'] as $value) {
            $all[] = (float) $value;
        }
    }

    [$domainMin, $domainMax] = Chart::domain($all ?: [0.0]);

    $w = 720.0;
    $h = 220.0;
    $padLeft = 52.0;
    $padRight = 16.0;
    $padTop = 16.0;
    $padBottom = 28.0;

    $plotW = $w - $padLeft - $padRight;
    $plotH = $h - $padTop - $padBottom;

    $ticks = Chart::ticks($domainMin, $domainMax, 4);
    $baselineY = Chart::scale(0.0, $domainMin, $domainMax, $padTop + $plotH, $padTop);

    $count = max(1, count($points));
    $seriesCount = max(1, count($series));

    // Band per group, then cap the bar at 24 units so a sparse chart gets air rather
    // than fat slabs. The 2px surface gap does the separating between the pair.
    $band = $plotW / $count;
    $gap = 2.0;
    $groupWidth = min($band * 0.62, ($seriesCount * 24.0) + ($seriesCount - 1) * $gap);
    $barWidth = max(2.0, ($groupWidth - ($seriesCount - 1) * $gap) / $seriesCount);
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }}>
    @if (count($points) === 0)
        <div class="flex h-40 items-center justify-center rounded-lg border border-dashed border-zinc-200 dark:border-zinc-700">
            <flux:text size="sm">{{ __('Nothing recorded yet.') }}</flux:text>
        </div>
    @else
        {{-- Two or more series always get a legend; identity is never colour alone. --}}
        <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1">
            @foreach ($series as $s)
                <span class="inline-flex items-center gap-1.5">
                    <span class="size-2.5 rounded-full" style="background: var({{ $s['var'] }});"></span>
                    <flux:text size="sm">{{ $s['name'] }}</flux:text>
                </span>
            @endforeach
        </div>

        <svg
            viewBox="0 0 {{ $w }} {{ $h }}"
            class="h-auto w-full overflow-visible"
            role="img"
            @if ($label) aria-label="{{ $label }}" @endif
        >
            @foreach ($ticks as $tick)
                @php $y = Chart::scale($tick, $domainMin, $domainMax, $padTop + $plotH, $padTop); @endphp
                <line
                    x1="{{ $padLeft }}" y1="{{ Chart::round($y) }}"
                    x2="{{ $padLeft + $plotW }}" y2="{{ Chart::round($y) }}"
                    stroke="var(--viz-grid)" stroke-width="1" vector-effect="non-scaling-stroke"
                />
                <text
                    x="{{ $padLeft - 10 }}" y="{{ Chart::round($y + 4) }}"
                    text-anchor="end" font-size="11" fill="var(--viz-muted)"
                    style="font-variant-numeric: tabular-nums;"
                >{{ Money::compact($tick) }}</text>
            @endforeach

            <line
                x1="{{ $padLeft }}" y1="{{ Chart::round($baselineY) }}"
                x2="{{ $padLeft + $plotW }}" y2="{{ Chart::round($baselineY) }}"
                stroke="var(--viz-axis)" stroke-width="1" vector-effect="non-scaling-stroke"
            />

            @foreach ($points as $index => $point)
                @php
                    $groupCentre = $padLeft + $band * ($index + 0.5);
                    $groupLeft = $groupCentre - $groupWidth / 2;
                @endphp

                @foreach (array_values($point['values']) as $sIndex => $value)
                    @php
                        $value = (float) $value;
                        $x = $groupLeft + $sIndex * ($barWidth + $gap);
                        $valueY = Chart::scale($value, $domainMin, $domainMax, $padTop + $plotH, $padTop);
                        $top = min($valueY, $baselineY);
                        $barHeight = abs($baselineY - $valueY);
                        $var = $series[$sIndex]['var'] ?? '--viz-1';
                    @endphp
                    @if ($barHeight > 0.5)
                        <path
                            d="{{ Chart::barPath($x, $top, $barWidth, $barHeight) }}"
                            fill="var({{ $var }})"
                            class="transition-opacity hover:opacity-80"
                        >
                            <title>{{ $point['label'] }} · {{ $series[$sIndex]['name'] ?? '' }}: {{ Money::kr($value) }}</title>
                        </path>
                    @endif
                @endforeach

                <text
                    x="{{ Chart::round($groupCentre) }}" y="{{ $h - 8 }}"
                    text-anchor="middle" font-size="11" fill="var(--viz-muted)"
                >{{ $point['label'] }}</text>
            @endforeach
        </svg>
    @endif
</div>
