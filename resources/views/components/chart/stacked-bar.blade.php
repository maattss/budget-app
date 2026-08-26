@props([
    'segments' => [],
    'label' => null,
])

@php
    use App\Support\Chart;
    use App\Support\Money;

    // $segments: list of ['name' => 'Bank account', 'value' => 1000.0, 'var' => '--viz-1']
    $segments = array_values(array_filter($segments, fn (array $s): bool => (float) $s['value'] > 0));
    $shares = Chart::shares(array_map(fn (array $s): float => (float) $s['value'], $segments));
    $total = array_sum(array_map(fn (array $s): float => (float) $s['value'], $segments));
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }}>
    @if ($total <= 0)
        <div class="flex h-24 items-center justify-center rounded-lg border border-dashed border-zinc-200 dark:border-zinc-700">
            <flux:text size="sm">{{ __('No values recorded for this month.') }}</flux:text>
        </div>
    @else
        {{--
            Plain flexbox rather than SVG. A horizontal stacked bar has no geometry an
            svg would help with, and stretching a viewBox to fill the width squashes the
            corner radius into an ellipse. The gap does the separating - never a stroke
            drawn around a segment.
        --}}
        <div
            class="flex h-7 w-full gap-0.5 overflow-hidden rounded-md"
            role="img"
            @if ($label) aria-label="{{ $label }}" @endif
        >
            @foreach ($segments as $index => $segment)
                <div
                    class="h-full first:rounded-s-md last:rounded-e-md"
                    style="flex: {{ Chart::round($shares[$index] * 1000) }} 1 0%; background: var({{ $segment['var'] }});"
                    title="{{ $segment['name'] }}: {{ Money::kr($segment['value']) }} ({{ round($shares[$index] * 100) }}%)"
                ></div>
            @endforeach
        </div>

        {{--
            The legend carries identity, and every value is written out beside it. Three
            light-mode slots sit under 3:1 contrast against a white surface, so the
            palette validator requires exactly this relief - a visible label and value,
            never colour alone.
        --}}
        <div class="mt-4 space-y-2">
            @foreach ($segments as $index => $segment)
                <div class="flex items-baseline justify-between gap-3">
                    <span class="flex min-w-0 items-center gap-2">
                        <span class="size-2.5 shrink-0 rounded-full" style="background: var({{ $segment['var'] }});"></span>
                        <flux:text size="sm">{{ $segment['name'] }}</flux:text>
                    </span>
                    <flux:text size="sm" class="shrink-0 tabular-nums">
                        {{ Money::kr($segment['value']) }}
                        <span class="text-zinc-400 dark:text-zinc-500">{{ round($shares[$index] * 100) }}%</span>
                    </flux:text>
                </div>
            @endforeach
        </div>
    @endif
</div>
