<?php

use App\Models\Asset;
use App\Support\Money;
use App\Support\Portfolio;
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
     * Start on the current month, or on whichever month the url asks for.
     */
    public function mount(): void
    {
        $now = now();

        $this->year ??= $now->year;
        $this->month ??= $now->month;

        // #[Url] means these two arrive from the address bar, so they are user input and
        // get checked like any other. ?month=99 used to be taken at face value, and
        // Carbon happily overflowed it: one click of "previous month" from month 99 of
        // 2026 landed on February 2034.
        if (! $this->isRealMonth($this->year, $this->month)) {
            $this->year = $now->year;
            $this->month = $now->month;
        }

        $this->loadCashFlow();
        $this->loadAssetValues();
    }

    /**
     * Whether a year and month pair names a month a person could mean.
     *
     * The year bounds are deliberately loose. This rejects nonsense, it does not decide
     * how far back someone is allowed to record - a user back-filling a decade of
     * history is doing something reasonable, and being bounced to the current month for
     * it would be the app second-guessing them.
     */
    protected function isRealMonth(int $year, int $month): bool
    {
        return $month >= 1
            && $month <= 12
            && $year >= 1900
            && $year <= 2200;
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
     * Everything the user owns and owes.
     *
     * This page needs the owned/owed split for display and nothing else from Portfolio:
     * its totals come from what is currently typed into the form, not from what is
     * stored, so that every figure moves as you type.
     *
     * @see \App\Support\Portfolio
     */
    #[Computed]
    public function portfolio(): Portfolio
    {
        return Portfolio::for(Auth::user());
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
        return $this->portfolio->all();
    }

    /**
     * The things the user owns.
     *
     * @return Collection<int, Asset>
     */
    #[Computed]
    public function assets(): Collection
    {
        return $this->portfolio->owned();
    }

    /**
     * The things the user owes.
     *
     * @return Collection<int, Asset>
     */
    #[Computed]
    public function liabilities(): Collection
    {
        return $this->portfolio->owed();
    }

    /**
     * Fill $values from what was recorded for the selected month.
     *
     * Read from the already-loaded histories rather than queried per month: the
     * portfolio arrives with every value attached, so stepping between months costs no
     * further round trip.
     *
     * recordedValueIn(), not valueAt(). A field must show what the user actually typed
     * for this month and stay empty otherwise - prefilling with a carried-forward figure
     * would then let one unedited visit to an old month save a guess as a record.
     */
    public function loadAssetValues(): void
    {
        $this->values = $this->allAssets
            ->mapWithKeys(fn (Asset $asset) => [
                $asset->id => Money::input($asset->recordedValueIn($this->year, $this->month)?->value),
            ])
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
        ], [
            // The field is labelled with the asset's name, so the default message - "The
            // values.7 field must be at least 0" - would introduce an array index the
            // user has never seen and cannot map back to a row.
            'values.*.numeric' => __('Enter an amount, or leave this blank.'),
            'values.*.min' => __('An amount cannot be negative.'),
        ]);

        foreach ($this->allAssets as $asset) {
            $assetValue = $this->values[$asset->id] ?? '';

            // Blank means "there is no figure for this month", which is a different
            // claim from the one already stored - so the row goes. Skipping empties
            // instead made clearing a field a silent no-op: the user was told the save
            // succeeded and the old number came straight back on reload. Now that values
            // carry forward, a wrong figure left in place propagates onwards too.
            if ($assetValue === '') {
                $asset->values()
                    ->where('year', $this->year)
                    ->where('month', $this->month)
                    ->delete();

                continue;
            }

            $asset->values()->updateOrCreate(
                ['year' => $this->year, 'month' => $this->month],
                ['value' => $assetValue]
            );
        }

        // The loaded histories are now a request older than the database, and
        // loadAssetValues() below reads them. Drop the memo so it re-reads.
        unset($this->portfolio, $this->allAssets, $this->assets, $this->liabilities);

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
