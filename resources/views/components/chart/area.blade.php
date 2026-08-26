@props([
    'points' => [],
    'label' => null,
    // Colour follows the entity being charted, not the chart's position on the page.
    'var' => '--viz-1',
])

@php
    use App\Support\Chart;
    use App\Support\Money;

    // $points: list of ['label' => 'Aug', 'value' => 1234.0]
    $points = array_values($points);
    // A null value means 'not recorded'. It must not become a zero.
    $values = array_map(fn (array $point) => $point['value'] === null ? null : (float) $point['value'], $points);
    $runs = Chart::runs($values);

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

    $recordedIndices = array_keys(array_filter($values, fn ($v): bool => $v !== null));
    $lastRecordedIndex = $recordedIndices === [] ? -1 : max($recordedIndices);
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

            {{-- One area wash and one line per run of recorded months. Unrecorded months
                 break the line rather than pulling it to zero. --}}
            @foreach ($runs as $run)
                @php $area = Chart::areaPath($run, $count, $domainMin, $domainMax, $padLeft, $padTop, $plotW, $plotH); @endphp
                @if ($area !== '')
                    <path d="{{ $area }}" fill="var({{ $var }})" fill-opacity="0.10" />
                @endif
                <path
                    d="{{ Chart::linePath($run, $count, $domainMin, $domainMax, $padLeft, $padTop, $plotW, $plotH) }}"
                    fill="none" stroke="var({{ $var }})" stroke-width="2"
                    stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"
                />
                {{-- A lone recorded month has no line to draw, so mark it as a point. --}}
                @if (count($run) === 1)
                    @php $only = array_key_first($run); @endphp
                    <circle
                        cx="{{ Chart::round(Chart::pointX($only, $count, $padLeft, $plotW)) }}"
                        cy="{{ Chart::round(Chart::scale($run[$only], $domainMin, $domainMax, $padTop + $plotH, $padTop)) }}"
                        r="4" fill="var({{ $var }})" stroke="var(--viz-surface)" stroke-width="2"
                    />
                @endif
            @endforeach

            {{-- x labels, thinned so they never collide on narrow screens. --}}
            @php $every = (int) max(1, ceil($count / 7)); @endphp
            @foreach ($points as $index => $point)
                @if ($index % $every === 0 || $index === $count - 1)
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
                @continue($point['value'] === null)
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
                        fill="var({{ $var }})" stroke="var(--viz-surface)" stroke-width="2"
                        class="{{ $index === $lastRecordedIndex ? '' : 'opacity-0 transition-opacity group-hover:opacity-100' }}"
                        pointer-events="none"
                    />
                </g>
            @endforeach
        </svg>

        {{-- Direct-label the last *recorded* month, not the last month in the window. --}}
        @php
            $recorded = array_values(array_filter($points, fn (array $p): bool => $p['value'] !== null));
            $latest = $recorded === [] ? null : end($recorded);
        @endphp
        @if ($latest)
            <div class="mt-1 flex justify-end pe-4">
                <flux:text size="sm" class="tabular-nums">
                    {{ $latest['label'] }}: <span class="font-medium">{{ Money::kr($latest['value']) }}</span>
                </flux:text>
            </div>
        @endif
    @endif
</div>
