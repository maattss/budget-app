<?php

use App\Models\Asset;
use App\Models\AssetValue;
use App\Models\MonthlyFinance;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Month')] class extends Component {
    #[Url]
    public int $year;

    #[Url]
    public int $month;

    public string $income = '';

    public string $spending = '';

    /**
     * This month's value per asset, keyed by asset id.
     *
     * @var array<int, string>
     */
    public array $values = [];

    /**
     * Start on the current month.
     */
    public function mount(): void
    {
        $now = now();

        $this->year ??= $now->year;
        $this->month ??= $now->month;

        $this->loadCashFlow();
        $this->loadAssetValues();
    }

    /**
     * Step one month back.
     */
    public function previousMonth(): void
    {
        $date = Carbon::create($this->year, $this->month)->subMonth();

        $this->year = $date->year;
        $this->month = $date->month;

        $this->loadCashFlow();
        $this->loadAssetValues();
    }

    /**
     * Step one month forward.
     */
    public function nextMonth(): void
    {
        $date = Carbon::create($this->year, $this->month)->addMonth();

        $this->year = $date->year;
        $this->month = $date->month;

        $this->loadCashFlow();
        $this->loadAssetValues();
    }

    /**
     * Fill the form from the stored row for the selected month, if there is one.
     */
    public function loadCashFlow(): void
    {
        $finance = Auth::user()->monthlyFinances()
            ->where('year', $this->year)
            ->where('month', $this->month)
            ->first();

        $this->income = Money::input($finance?->income);
        $this->spending = Money::input($finance?->spending);
    }

    /**
     * Store the numbers for the selected month.
     */
    public function saveCashFlow(): void
    {
        // Normalise first so a grouped number validates instead of being rejected as
        // non-numeric. Assigning back means the field also redisplays cleanly on error.
        $this->income = Money::parse($this->income);
        $this->spending = Money::parse($this->spending);

        $this->validate([
            'income' => ['required', 'numeric', 'min:0'],
            'spending' => ['required', 'numeric', 'min:0'],
        ]);

        Auth::user()->monthlyFinances()->updateOrCreate(
            ['year' => $this->year, 'month' => $this->month],
            ['income' => $this->income, 'spending' => $this->spending]
        );

        $this->loadCashFlow();
        $this->loadAssetValues();

        Flux::toast(text: __('Saved!'), variant: 'success');
    }

    /**
     * Income minus spending, for the numbers currently in the form.
     */
    #[Computed]
    public function savings(): float
    {
        // Money::parse, not a bare cast: (float) '62 000' is 62.0, so a grouped value
        // would quietly render the wrong figure here rather than erroring.
        return (float) Money::parse($this->income) - (float) Money::parse($this->spending);
    }

    /**
     * Every asset belonging to the current user, in a stable display order.
     *
     * The form holds one field per asset whichever group it renders in, so loading and
     * saving stay ungrouped and only the display splits in two.
     *
     * @return Collection<int, Asset>
     */
    #[Computed]
    public function allAssets(): Collection
    {
        return Auth::user()->assets()->orderBy('name')->get();
    }

    /**
     * The things the user owns.
     *
     * @return Collection<int, Asset>
     */
    #[Computed]
    public function assets(): Collection
    {
        return $this->allAssets->reject(fn (Asset $asset): bool => $asset->type->isLiability());
    }

    /**
     * The things the user owes.
     *
     * @return Collection<int, Asset>
     */
    #[Computed]
    public function liabilities(): Collection
    {
        return $this->allAssets->filter(fn (Asset $asset): bool => $asset->type->isLiability());
    }

    /**
     * Fill $values from asset_values for the selected month.
     */
    public function loadAssetValues(): void
    {
        $stored = AssetValue::whereIn('asset_id', $this->allAssets->pluck('id'))
            ->where('year', $this->year)
            ->where('month', $this->month)
            ->pluck('value', 'asset_id');

        $this->values = $this->allAssets
            ->mapWithKeys(fn (Asset $asset) => [$asset->id => Money::input($stored->get($asset->id))])
            ->all();
    }

    /**
     * Store this month's value for every asset in the form.
     */
    public function saveAssetValues(): void
    {
        $this->values = array_map(fn ($value): string => Money::parse($value), $this->values);

        $this->validate([
            'values.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($this->allAssets as $asset) {
            $assetValue = $this->values[$asset->id] ?? '';

            if ($assetValue  === '') {
                continue;
            }

            $asset->values()->updateOrCreate(
                ['year' => $this->year, 'month' => $this->month],
                ['value' => $assetValue ]
            );
        }

        $this->loadAssetValues();

        Flux::toast(text: __('Values saved!'), variant: 'success');
    }

    /**
     * What the form currently says is owned.
     */
    #[Computed]
    public function assetsTotal(): float
    {
        return $this->totalFor($this->assets);
    }

    /**
     * What the form currently says is owed.
     */
    #[Computed]
    public function liabilitiesTotal(): float
    {
        return $this->totalFor($this->liabilities);
    }

    /**
     * Assets minus liabilities, for the numbers currently in the form.
     */
    #[Computed]
    public function netWorth(): float
    {
        return $this->assetsTotal - $this->liabilitiesTotal;
    }

    /**
     * The sum of the values currently typed into one group's fields.
     *
     * Reads $this->values, not the stored rows, so every total moves as you type. And
     * Money::parse rather than a bare cast, for the reason savings() gives.
     *
     * @param  Collection<int, Asset>  $assets
     */
    protected function totalFor(Collection $assets): float
    {
        return (float) $assets->sum(
            fn (Asset $asset): float => (float) Money::parse($this->values[$asset->id] ?? '')
        );
    }
}; ?>

<section class="w-full max-w-3xl">
    <flux:heading size="xl">{{ __('Month') }}</flux:heading>

    <div class="mt-6 flex items-center gap-4">
        <flux:button icon="chevron-left" wire:click="previousMonth" />

        <flux:heading>{{ Carbon::create($year, $month)->translatedFormat('F Y') }}</flux:heading>

        <flux:button icon="chevron-right" wire:click="nextMonth" />
    </div>

    <form wire:submit="saveCashFlow" class="mt-8 max-w-md space-y-6">
        <x-money-input wire:model="income" :label="__('Income')" />

        <x-money-input wire:model="spending" :label="__('Spending')" />

        <div class="flex items-center justify-between">
            <flux:text>
                {{ __('Saved') }}:
                <span class="font-medium tabular-nums {{ $this->savings < 0 ? 'text-[var(--viz-bad)]' : '' }}">
                    {{ Money::kr($this->savings) }}
                </span>
            </flux:text>

            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </div>
    </form>

    <div class="mt-12">
        <flux:heading>{{ __('Asset values') }}</flux:heading>
        <flux:subheading>{{ __('What each asset was worth this month.') }}</flux:subheading>

        @if ($this->allAssets->isEmpty())
            <flux:text class="mt-2">
                {{ __('No assets yet.') }}
                <flux:link :href="route('assets.index')" wire:navigate>{{ __('Add one first.') }}</flux:link>
            </flux:text>
        @else
        <form wire:submit="saveAssetValues" class="mt-4 space-y-6">
            {{-- Grouped the same way as the assets page, and for the same reason: what you
                 own and what you owe pull net worth in opposite directions, so a single
                 alphabetical list puts a 3 million mortgage next to a 3 million flat with
                 nothing to say they cancel out. --}}
            @foreach ([
                ['heading' => __('Assets'), 'group' => $this->assets, 'total' => $this->assetsTotal, 'sign' => ''],
                ['heading' => __('Liabilities'), 'group' => $this->liabilities, 'total' => $this->liabilitiesTotal, 'sign' => '−'],
            ] as $section)
                @continue($section['group']->isEmpty())

                <div>
                    <flux:heading>{{ $section['heading'] }}</flux:heading>

                    <div class="mt-2 divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($section['group'] as $asset)
                            {{-- Stacked on a phone, side by side from sm up. This was a
                                 three-column flux:table, which is table-fixed: every column
                                 took a third of the viewport whatever it held, so on a phone
                                 the input was ~110px and clipped the amount mid-digit. A
                                 flex row gives the field the whole width when there is none
                                 to spare. --}}
                            <div
                                wire:key="asset-value-{{ $asset->id }}"
                                class="flex flex-col gap-2 bg-white p-4 sm:flex-row sm:items-center sm:gap-4 dark:bg-zinc-900"
                            >
                                <div class="flex min-w-0 flex-1 items-center gap-3">
                                    <x-asset-icon :type="$asset->type" size="sm" />

                                    <div class="min-w-0">
                                        <div class="truncate">
                                            <flux:link :href="route('assets.show', $asset)" wire:navigate class="font-medium">{{ $asset->name }}</flux:link>
                                        </div>
                                        <flux:text size="sm">{{ $asset->type->label() }}</flux:text>
                                    </div>
                                </div>

                                {{-- The column header that used to name this field is gone, so
                                     the field has to name itself to a screen reader. --}}
                                <div class="sm:w-44">
                                    <x-money-input
                                        wire:model="values.{{ $asset->id }}"
                                        size="sm"
                                        :aria-label="__('Value of :name this month', ['name' => $asset->name])"
                                    />
                                </div>
                            </div>
                        @endforeach

                        <div class="flex items-baseline justify-between bg-zinc-50 px-4 py-3 dark:bg-zinc-800/50">
                            <flux:text size="sm">{{ __('Total') }}</flux:text>
                            <flux:text size="sm" class="font-medium tabular-nums text-zinc-800 dark:text-zinc-100">
                                {{ $section['sign'] }}{{ Money::kr($section['total']) }}
                            </flux:text>
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:text>
                    {{ __('Net worth') }}:
                    <span class="font-medium tabular-nums {{ $this->netWorth < 0 ? 'text-[var(--viz-bad)]' : '' }}">
                        {{ Money::kr($this->netWorth) }}
                    </span>
                </flux:text>

                <flux:button variant="primary" type="submit">{{ __('Save values') }}</flux:button>
            </div>
        </form>
        @endif
    </div>
</section>
