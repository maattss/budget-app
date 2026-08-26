@props([
    'points' => [],
    'label' => null,
])

@php
    use App\Support\Chart;
    use App\Support\Money;

    // $points: list of ['label' => 'Aug', 'value' => 1234.0]
    $points = array_values($points);
    $values = array_map(fn (array $point): float => (float) $point['value'], $points);

    [$domainMin, $domainMax] = Chart::domain($values);

    // viewBox units, not pixels. The svg scales with its container and strokes are
    // pinned with vector-effect so a 2px line stays 2px at any width.
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

    $count = count($points);
    $lastIndex = $count - 1;
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }}>
    @if ($count === 0)
        <div class="flex h-40 items-center justify-center rounded-lg border border-dashed border-zinc-200 dark:border-zinc-700">
            <flux:text size="sm">{{ __('No history yet.') }}</flux:text>
        </div>
    @else
        <svg
            viewBox="0 0 {{ $w }} {{ $h }}"
            class="h-auto w-full overflow-visible"
            role="img"
            @if ($label) aria-label="{{ $label }}" @endif
        >
            {{-- Gridlines: solid hairlines one step off the surface, never dashed. --}}
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

            {{-- Zero line, a touch stronger than the grid, only when the domain straddles it. --}}
            @if ($domainMin < 0)
                <line
                    x1="{{ $padLeft }}" y1="{{ Chart::round($baselineY) }}"
                    x2="{{ $padLeft + $plotW }}" y2="{{ Chart::round($baselineY) }}"
                    stroke="var(--viz-axis)" stroke-width="1" vector-effect="non-scaling-stroke"
                />
            @endif

            {{-- Area wash at ~10% then the 2px line on top. --}}
            <path
                d="{{ Chart::areaPath($values, $domainMin, $domainMax, $padLeft, $padTop, $plotW, $plotH) }}"
                fill="var(--viz-1)" fill-opacity="0.10"
            />
            <path
                d="{{ Chart::linePath($values, $domainMin, $domainMax, $padLeft, $padTop, $plotW, $plotH) }}"
                fill="none" stroke="var(--viz-1)" stroke-width="2"
                stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"
            />

            {{-- x labels, thinned so they never collide on narrow screens. --}}
            @php $every = (int) max(1, ceil($count / 7)); @endphp
            @foreach ($points as $index => $point)
                @if ($index % $every === 0 || $index === $lastIndex)
                    <text
                        x="{{ Chart::round(Chart::pointX($index, $count, $padLeft, $plotW)) }}"
                        y="{{ $h - 8 }}"
                        text-anchor="middle" font-size="11" fill="var(--viz-muted)"
                    >{{ $point['label'] }}</text>
                @endif
            @endforeach

            {{-- Per-point hover: a generous transparent hit band, a marker, and a native
                 title so a value is reachable without JavaScript. The table below the
                 chart is the ungated equivalent. --}}
            @foreach ($points as $index => $point)
                @php
                    $px = Chart::pointX($index, $count, $padLeft, $plotW);
                    $py = Chart::scale((float) $point['value'], $domainMin, $domainMax, $padTop + $plotH, $padTop);
                    $band = $count > 1 ? $plotW / max(1, $count - 1) : $plotW;
                @endphp
                <g class="group">
                    <rect
                        x="{{ Chart::round($px - $band / 2) }}" y="{{ $padTop }}"
                        width="{{ Chart::round($band) }}" height="{{ $plotH }}"
                        fill="transparent"
                    >
                        <title>{{ $point['label'] }}: {{ Money::kr($point['value']) }}</title>
                    </rect>
                    <line
                        x1="{{ Chart::round($px) }}" y1="{{ $padTop }}"
                        x2="{{ Chart::round($px) }}" y2="{{ $padTop + $plotH }}"
                        stroke="var(--viz-axis)" stroke-width="1" vector-effect="non-scaling-stroke"
                        class="opacity-0 transition-opacity group-hover:opacity-100"
                        pointer-events="none"
                    />
                    <circle
                        cx="{{ Chart::round($px) }}" cy="{{ Chart::round($py) }}" r="4"
                        fill="var(--viz-1)" stroke="var(--viz-surface)" stroke-width="2"
                        class="{{ $index === $lastIndex ? '' : 'opacity-0 transition-opacity group-hover:opacity-100' }}"
                        pointer-events="none"
                    />
                </g>
            @endforeach
        </svg>

        {{-- The endpoint is the one value worth direct-labelling; the rest ride the axis. --}}
        <div class="mt-1 flex justify-end pe-4">
            <flux:text size="sm" class="tabular-nums">
                {{ $points[$lastIndex]['label'] }}: <span class="font-medium">{{ Money::kr($points[$lastIndex]['value']) }}</span>
            </flux:text>
        </div>
    @endif
</div>
