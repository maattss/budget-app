<?php

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\AssetValue;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Assets')]
class extends Component {
    public string $name = '';

    public string $type = '';

    /**
     * How many months of history the row sparklines show.
     */
    public int $months = 12;

    /**
     * The things the user owns.
     *
     * @return Collection<int, Asset>
     */
    #[Computed]
    public function assets(): Collection
    {
        return $this->allAssets()->reject(fn (Asset $asset): bool => $asset->type->isLiability());
    }

    /**
     * The things the user owes.
     *
     * @return Collection<int, Asset>
     */
    #[Computed]
    public function liabilities(): Collection
    {
        return $this->allAssets()->filter(fn (Asset $asset): bool => $asset->type->isLiability());
    }

    /**
     * Total current value of everything owned.
     */
    #[Computed]
    public function assetsTotal(): float
    {
        return $this->assets->sum(fn (Asset $asset): float => $this->latestValue($asset));
    }

    /**
     * Total current value of everything owed.
     */
    #[Computed]
    public function liabilitiesTotal(): float
    {
        return $this->liabilities->sum(fn (Asset $asset): float => $this->latestValue($asset));
    }

    /**
     * The most recent recorded value for one asset, or zero if there is none.
     */
    public function latestValue(Asset $asset): float
    {
        return (float) ($asset->values->last()?->value ?? 0);
    }

    /**
     * The value history for one asset's sparkline.
     *
     * @return array<int, float>
     */
    public function history(Asset $asset): array
    {
        return $asset->values->map(fn (AssetValue $value): float => (float) $value->value)->all();
    }

    /**
     * Add an asset for the current user.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AssetType::class)],
        ]);

        Auth::user()->assets()->create($validated);

        $this->reset(['name', 'type']);

        unset($this->allAssets, $this->assets, $this->liabilities, $this->assetsTotal, $this->liabilitiesTotal);

        Flux::toast(text: __('Asset added.'), variant: 'success');
    }

    /**
     * Delete one of the current user's assets.
     */
    public function delete(int $assetId): void
    {
        Auth::user()->assets()->findOrFail($assetId)->delete();

        unset($this->allAssets, $this->assets, $this->liabilities, $this->assetsTotal, $this->liabilitiesTotal);

        Flux::toast(text: __('Asset deleted.'), variant: 'success');
    }

    /**
     * Every asset belonging to the current user, loaded once per request.
     *
     * The values are eager loaded because every row draws a sparkline and a current
     * value - without ->with() that is a query per asset, twice over.
     *
     * @return Collection<int, Asset>
     */
    #[Computed]
    protected function allAssets(): Collection
    {
        $earliest = now()->subMonths($this->months - 1)->startOfMonth();

        return Auth::user()->assets()
            ->orderBy('name')
            ->with(['values' => fn ($query) => $query
                ->whereRaw('(year * 100 + month) >= ?', [$earliest->year * 100 + $earliest->month])
                ->orderBy('year')
                ->orderBy('month'),
            ])
            ->get();
    }
}; ?>

<section class="w-full max-w-4xl">
    <flux:heading size="xl">{{ __('Assets & liabilities') }}</flux:heading>
    <flux:subheading>{{ __('What you own and what you owe. Values are entered per month.') }}</flux:subheading>

    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <x-stat-tile :label="__('Assets')" :value="$this->assetsTotal" :caption="__('latest recorded value')" />
        <x-stat-tile :label="__('Liabilities')" :value="$this->liabilitiesTotal" :caption="__('latest recorded value')" />
        <x-stat-tile :label="__('Net worth')" :value="$this->assetsTotal - $this->liabilitiesTotal" :caption="__('assets minus liabilities')" />
    </div>

    <div class="mt-8 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading>{{ __('Add something') }}</flux:heading>

        <form wire:submit="save" class="mt-3 flex flex-wrap items-start gap-3">
            <flux:input wire:model="name" :label="__('Name')" class="min-w-48 flex-1" />

            <flux:select wire:model="type" :label="__('Type')" :placeholder="__('Choose a type')" class="w-56">
                {{-- Real <optgroup>, not disabled value="" options. flux:select renders a
                     native <select> and injects the placeholder as
                     <option value="" disabled selected>; a second empty-valued option
                     later in the markup wins the selection and the placeholder is lost. --}}
                <optgroup label="{{ __('Assets') }}">
                    @foreach (AssetType::assets() as $assetType)
                        <flux:select.option value="{{ $assetType->value }}">{{ $assetType->label() }}</flux:select.option>
                    @endforeach
                </optgroup>

                <optgroup label="{{ __('Liabilities') }}">
                    @foreach (AssetType::liabilities() as $assetType)
                        <flux:select.option value="{{ $assetType->value }}">{{ $assetType->label() }}</flux:select.option>
                    @endforeach
                </optgroup>
            </flux:select>

            <flux:button variant="primary" type="submit" class="mt-6">{{ __('Add') }}</flux:button>
        </form>
    </div>

    <div class="mt-8 space-y-8">
        @foreach ([
            ['heading' => __('Assets'), 'group' => $this->assets, 'empty' => __('Nothing owned recorded yet.')],
            ['heading' => __('Liabilities'), 'group' => $this->liabilities, 'empty' => __('Nothing owed recorded yet.')],
        ] as $section)
            <div>
                <flux:heading>{{ $section['heading'] }}</flux:heading>

                @if ($section['group']->isEmpty())
                    <flux:text class="mt-2">{{ $section['empty'] }}</flux:text>
                @else
                    <div class="mt-3 divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($section['group'] as $asset)
                            <div class="group flex items-center gap-4 bg-white p-4 dark:bg-zinc-900">
                                <x-asset-icon :type="$asset->type" />

                                <div class="min-w-0 flex-1">
                                    <flux:link
                                        :href="route('assets.show', $asset)"
                                        wire:navigate
                                        class="block truncate font-medium"
                                    >{{ $asset->name }}</flux:link>
                                    <flux:text size="sm">{{ $asset->type->label() }}</flux:text>
                                </div>

                                <div class="hidden sm:block">
                                    <x-chart.sparkline
                                        :values="$this->history($asset)"
                                        :var="'--viz-'.$asset->type->seriesSlot()"
                                    />
                                </div>

                                <div class="text-end">
                                    <div class="font-medium tabular-nums text-zinc-900 dark:text-zinc-100">
                                        {{ Money::kr($this->latestValue($asset)) }}
                                    </div>
                                    <flux:text size="sm">
                                        {{ trans_choice('{0}no values|{1}:count month|[2,*]:count months', $asset->values->count(), ['count' => $asset->values->count()]) }}
                                    </flux:text>
                                </div>

                                <flux:button
                                    variant="subtle"
                                    size="sm"
                                    icon="trash"
                                    class="opacity-40 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                                    wire:click="delete({{ $asset->id }})"
                                    wire:confirm="{{ __('Delete :name and all its values?', ['name' => $asset->name]) }}"
                                />
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</section>
