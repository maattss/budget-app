@props([
    'label',
    'value',
    'delta' => null,
    // Shown only alongside a delta. "vs last month" on its own describes a comparison
    // that is not on screen, which is what it did when there was no previous month.
    'deltaLabel' => null,
    // Standalone caption, always shown - for text that explains the value rather than
    // qualifying a change.
    'caption' => null,
    'upIsGood' => true,
    'hero' => false,
])

@php
    use App\Support\Money;

    $direction = $delta === null ? 0 : ($delta > 0 ? 1 : ($delta < 0 ? -1 : 0));
    $isGood = $direction === 0 ? null : ($direction > 0) === (bool) $upIsGood;
@endphp

<div {{ $attributes->merge(['class' => 'rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900']) }}>
    <flux:text size="sm">{{ $label }}</flux:text>

    {{-- Proportional figures, not tabular: equal-width digits make a large standalone
         number look loose. A negative amount is coloured, matching how the same figure
         is treated in the tables. --}}
    <div @class([
        'mt-1 font-semibold',
        'text-4xl sm:text-5xl' => $hero,
        'text-2xl' => ! $hero,
        'text-[var(--viz-bad)]' => (float) $value < 0,
        'text-zinc-900 dark:text-zinc-100' => (float) $value >= 0,
    ])>{{ Money::kr($value) }}</div>

    @if ($delta !== null && $direction !== 0)
        <div class="mt-1.5 flex items-center gap-1">
            <flux:icon
                :name="$direction > 0 ? 'arrow-up-right' : 'arrow-down-right'"
                variant="micro"
                class="{{ $isGood ? 'text-[var(--viz-good)]' : 'text-[var(--viz-bad)]' }}"
            />
            <flux:text size="sm" class="tabular-nums">
                {{ Money::kr(abs($delta)) }}
                @if ($deltaLabel)
                    <span class="text-zinc-400 dark:text-zinc-500">{{ $deltaLabel }}</span>
                @endif
            </flux:text>
        </div>
    @endif

    @if ($caption)
        <flux:text size="sm" class="mt-1.5 block text-zinc-400 dark:text-zinc-500">{{ $caption }}</flux:text>
    @endif
</div>
