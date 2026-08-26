@props([
    'label' => null,
    'size' => null,
    'suffix' => 'kr',
])

@php
    // The Livewire property this input is bound to. Read off wire:model so callers write
    // <x-money-input wire:model="income" /> exactly as they would with flux:input.
    $model = $attributes->wire('model')->value();
@endphp

{{--
    Groups digits with a thin space as you type, while the value sent to the server stays
    a plain number.

    Two things make this fiddly and are worth knowing:

    - Reformatting on every keystroke moves the caret, so typing into the middle of a
      number would jump you to the end. The caret is restored by counting digits rather
      than characters: remember how many digits sit left of the caret, reformat, then walk
      the new string until that many digits have been passed.
    - Livewire owns this input's value too. After a server render (month navigation, a
      save) the property changes underneath Alpine, so $wire.$watch re-formats the
      display. Without it the field would show a stale number after changing month.

    The server normalises independently via Money::parse - this layer is a convenience,
    never the thing correctness depends on.
--}}
<div
    x-data="{
        model: @js($model),
        display: '',
        group: ' ',

        init() {
            this.display = this.format(this.$wire.get(this.model));

            // Re-format when the server changes the value under us.
            if (typeof this.$wire.$watch === 'function') {
                this.$wire.$watch(this.model, (value) => {
                    if (this.strip(this.display) !== this.strip(value)) {
                        this.display = this.format(value);
                    }
                });
            }
        },

        strip(value) {
            return String(value ?? '').replace(/[^\d.,-]/g, '');
        },

        format(value) {
            const clean = this.strip(value).replace(',', '.');

            if (clean === '' || clean === '-') {
                return clean;
            }

            const negative = clean.startsWith('-');
            const [whole, ...rest] = clean.replace('-', '').split('.');
            const grouped = (whole || '').replace(/\B(?=(\d{3})+(?!\d))/g, this.group);

            return (negative ? '-' : '')
                + grouped
                + (rest.length ? ',' + rest.join('') : '');
        },

        onInput(event) {
            const input = event.target;
            const caret = input.selectionStart ?? input.value.length;

            // Count digits to the left of the caret, not characters - separators shift
            // as the number grows, so a character offset is meaningless after a reformat.
            const digitsBefore = (input.value.slice(0, caret).match(/\d/g) || []).length;

            const formatted = this.format(input.value);
            this.display = formatted;
            input.value = formatted;

            // Walk forward until digitsBefore digits have been passed.
            let seen = 0;
            let position = 0;
            while (position < formatted.length && seen < digitsBefore) {
                if (/\d/.test(formatted[position])) {
                    seen++;
                }
                position++;
            }

            input.setSelectionRange(position, position);

            // The server always receives a plain number.
            this.$wire.set(this.model, this.strip(formatted).replace(',', '.'), false);
        },
    }"
>
    <flux:input
        x-model="display"
        x-on:input="onInput"
        :label="$label"
        :size="$size"
        inputmode="decimal"
        autocomplete="off"
        class="tabular-nums"
        {{ $attributes->except(['wire:model', 'wire:model.live', 'wire:model.blur']) }}
    >
        @if ($suffix)
            <x-slot name="iconTrailing">
                <span class="pe-3 text-sm text-zinc-400 dark:text-zinc-500">{{ $suffix }}</span>
            </x-slot>
        @endif
    </flux:input>

    {{-- Errors still come from the Livewire property, not the display value. --}}
    <flux:error :name="$model" />
</div>
