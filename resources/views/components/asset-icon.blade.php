@props([
    'type',
    'size' => 'md',
])

@php
    /** @var \App\Enums\AssetType $type */
    $box = $size === 'lg' ? 'size-11' : ($size === 'sm' ? 'size-7' : 'size-9');
    $glyph = $size === 'lg' ? 'size-6' : ($size === 'sm' ? 'size-4' : 'size-5');
@endphp

<span
    {{ $attributes->merge([
        'class' => 'inline-flex shrink-0 items-center justify-center rounded-lg '.$box.' '.$type->badgeClasses(),
    ]) }}
    title="{{ $type->label() }}"
>
    <flux:icon :name="$type->icon()" :class="$glyph" />
</span>
