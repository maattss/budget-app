<?php

use App\Models\Asset;
use App\Models\AssetValue;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * The asset being viewed, held as an id rather than a model.
     *
     * This is deliberate. A public Eloquent property round-trips through the browser and
     * Livewire rehydrates it from its id on the next request - without re-checking who
     * owns it. Keeping only the id and resolving through Auth::user()->assets() below
     * means ownership is re-verified on *every* request, not just the first one.
     */
    public int $assetId;

    public function mount(int $asset): void
    {
        $this->assetId = $asset;

        // Resolve once here so a bad id 404s on load rather than mid-render.
        $this->asset();
    }

    /**
     * The asset, scoped to its owner.
     *
     * findOrFail on the relation - not Asset::findOrFail - so another user's id is a
     * 404 rather than a successful read. The framework does not do this for you.
     */
    #[Computed]
    public function asset(): Asset
    {
        return Auth::user()->assets()->findOrFail($this->assetId);
    }

    /**
     * Every recorded value for this asset, oldest first.
     *
     * @return Collection<int, AssetValue>
     */
    #[Computed]
    public function values(): Collection
    {
        return $this->asset->values()
            ->orderBy('year')
            ->orderBy('month')
            ->get();
    }

    /**
     * The value history shaped for the area chart.
     *
     * @return array<int, array{label: string, value: float}>
     */
    #[Computed]
    public function series(): array
    {
        return $this->values
            ->map(fn (AssetValue $value): array => [
                'label' => Carbon::create($value->year, $value->month)->translatedFormat('M y'),
                'value' => (float) $value->value,
            ])
            ->all();
    }

    /**
     * The most recently recorded value, or null if nothing has been entered.
     */
    #[Computed]
    public function latest(): ?AssetValue
    {
        return $this->values->last();
    }

    /**
     * The change between the last two recorded values, or null if there is only one.
     */
    #[Computed]
    public function change(): ?float
    {
        if ($this->values->count() < 2) {
            return null;
        }

        return (float) $this->values->last()->value - (float) $this->values[$this->values->count() - 2]->value;
    }
}; ?>

<section class="w-full max-w-4xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('assets.index')" wire:navigate>{{ __('Assets') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $this->asset->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex items-start gap-4">
        <x-asset-icon :type="$this->asset->type" size="lg" />

        <div class="min-w-0 flex-1">
            <flux:heading size="xl" class="truncate">{{ $this->asset->name }}</flux:heading>
            <flux:subheading>
                {{ $this->asset->type->label() }}
                @if ($this->asset->type->isLiability())
                    · <span class="text-[var(--viz-bad)]">{{ __('Counts against net worth') }}</span>
                @endif
            </flux:subheading>
        </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <x-stat-tile
            :label="__('Latest value')"
            :value="$this->latest?->value ?? 0"
            :delta="$this->change"
            :delta-label="__('vs previous month')"
            :up-is-good="! $this->asset->type->isLiability()"
        />
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text size="sm">{{ __('Months recorded') }}</flux:text>
            <div class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100">{{ $this->values->count() }}</div>
            @if ($this->latest)
                <flux:text size="sm" class="mt-1.5 block text-zinc-400 dark:text-zinc-500">
                    {{ __('Latest :month', ['month' => Carbon::create($this->latest->year, $this->latest->month)->translatedFormat('F Y')]) }}
                </flux:text>
            @endif
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text size="sm">{{ __('Trend') }}</flux:text>
            <div class="mt-2">
                <x-chart.sparkline
                    :values="collect($this->series)->pluck('value')->all()"
                    :var="'--viz-'.$this->asset->type->seriesSlot()"
                />
            </div>
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading>{{ __('Value history') }}</flux:heading>
        <flux:subheading>{{ __('Every month you have recorded a value for this asset') }}</flux:subheading>

        <x-chart.area
            class="mt-4"
            :points="$this->series"
            :var="'--viz-'.$this->asset->type->seriesSlot()"
            :label="__(':name value history', ['name' => $this->asset->name])"
        />
    </div>

    <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading>{{ __('Recorded values') }}</flux:heading>

        @if ($this->values->isEmpty())
            <flux:text class="mt-2">
                {{ __('No values recorded yet.') }}
                <flux:link :href="route('month.show')" wire:navigate>{{ __('Enter one for this month.') }}</flux:link>
            </flux:text>
        @else
            <flux:table class="mt-3">
                <flux:table.columns>
                    <flux:table.column>{{ __('Month') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Value') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Change') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->values->reverse() as $value)
                        @php
                            $position = $this->values->search(fn (AssetValue $v): bool => $v->id === $value->id);
                            $previous = $position > 0 ? $this->values[$position - 1] : null;
                            $diff = $previous ? (float) $value->value - (float) $previous->value : null;
                        @endphp
                        <flux:table.row :key="$value->id">
                            <flux:table.cell variant="strong">
                                <flux:link
                                    :href="route('month.show', ['year' => $value->year, 'month' => $value->month])"
                                    wire:navigate
                                >
                                    {{ Carbon::create($value->year, $value->month)->translatedFormat('F Y') }}
                                </flux:link>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ Money::kr($value->value) }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">
                                @if ($diff === null)
                                    <span class="text-zinc-400 dark:text-zinc-500">—</span>
                                @elseif ($diff == 0)
                                    <span class="text-zinc-400 dark:text-zinc-500">0 kr</span>
                                @else
                                    <span class="{{ ($diff > 0) === ! $this->asset->type->isLiability() ? 'text-[var(--viz-good)]' : 'text-[var(--viz-bad)]' }}">
                                        {{ $diff > 0 ? '+' : '−' }}{{ Money::kr(abs($diff)) }}
                                    </span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
