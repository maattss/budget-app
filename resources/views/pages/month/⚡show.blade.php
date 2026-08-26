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

        $this->income = $finance?->income ?? '';
        $this->spending = $finance?->spending ?? '';
    }

    /**
     * Store the numbers for the selected month.
     */
    public function saveCashFlow(): void
    {
        $this->validate([
            'income' => ['required', 'numeric', 'min:0'],
            'spending' => ['required', 'numeric', 'min:0']
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
        return (float) $this->income - (float) $this->spending;
    }

    /**
     * The current user's assets, in a stable display order.
     *
     * @return Collection<int, Asset>
     */
    #[Computed]
    public function assets(): Collection
    {
        return Auth::user()->assets()->orderBy('name')->get();
    }

    /**
     * Fill $values from asset_values for the selected month.
     */
    public function loadAssetValues(): void
    {
        $stored = AssetValue::whereIn('asset_id', $this->assets->pluck('id'))
            ->where('year', $this->year)
            ->where('month', $this->month)
            ->pluck('value', 'asset_id');

        $this->values = $this->assets
            ->mapWithKeys(fn (Asset $asset) => [$asset->id => $stored->get($asset->id, '')])
            ->all();
    }

    /**
     * Store this month's value for every asset in the form.
     */
    public function saveAssetValues(): void
    {
        $this->validate([
            'values.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($this->assets as $asset) {
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
     * Assets minus liabilities, for the numbers currently in the form.
     */
    #[Computed]
    public function netWorth(): float
    {
        $netWorth = 0;
        foreach ($this->assets as $asset) {
            $assetValue = $this->values[$asset->id] ?? '';
            if ($asset->type->isLiability()) {
                $netWorth = $netWorth - (float) $assetValue;
            } else {
                $netWorth = $netWorth + (float) $assetValue;
            }
        }

        return $netWorth;
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
        <flux:input wire:model="income" :label="__('Income')" inputmode="decimal" class="tabular-nums">
            <x-slot name="iconTrailing">
                <span class="pe-3 text-sm text-zinc-400 dark:text-zinc-500">kr</span>
            </x-slot>
        </flux:input>

        <flux:input wire:model="spending" :label="__('Spending')" inputmode="decimal" class="tabular-nums">
            <x-slot name="iconTrailing">
                <span class="pe-3 text-sm text-zinc-400 dark:text-zinc-500">kr</span>
            </x-slot>
        </flux:input>

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

        @if ($this->assets->isEmpty())
            <flux:text class="mt-2">
                {{ __('No assets yet.') }}
                <flux:link :href="route('assets.index')" wire:navigate>{{ __('Add one first.') }}</flux:link>
            </flux:text>
        @else
        <form wire:submit="saveAssetValues" class="mt-4 space-y-4">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Asset') }}</flux:table.column>
                    <flux:table.column>{{ __('Type') }}</flux:table.column>
                    <flux:table.column>{{ __('Value') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->assets as $asset)
                        <flux:table.row :key="$asset->id">
                            <flux:table.cell variant="strong">
                                <span class="flex items-center gap-3">
                                    <x-asset-icon :type="$asset->type" size="sm" />
                                    <flux:link :href="route('assets.show', $asset)" wire:navigate>{{ $asset->name }}</flux:link>
                                </span>
                            </flux:table.cell>
                            <flux:table.cell>{{ $asset->type->label() }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:input
                                    wire:model="values.{{ $asset->id }}"
                                    inputmode="decimal"
                                    size="sm"
                                    class="tabular-nums"
                                >
                                    <x-slot name="iconTrailing">
                                        <span class="pe-3 text-sm text-zinc-400 dark:text-zinc-500">kr</span>
                                    </x-slot>
                                </flux:input>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <div class="flex items-center justify-between">
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
