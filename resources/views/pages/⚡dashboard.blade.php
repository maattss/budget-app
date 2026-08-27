<?php

use App\Models\Asset;
use App\Models\MonthlyFinance;
use App\Support\Money;
use App\Support\Portfolio;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * How many months of history the trend charts show.
     *
     * A constant rather than a public property. Nothing in the UI changes it, and a
     * public Livewire property round-trips through the browser - so as state it was
     * only ever an input nobody validated. window() below builds a row per month, so a
     * crafted request asking for a million of them allocates gigabytes before it fails.
     */
    protected const MONTHS = 12;

    /**
     * Every cash flow row the user has, keyed by year * 100 + month.
     *
     * One query serves the stat tiles, the deltas, the columns chart and the recent
     * months table. Fetching each month separately cost thirteen queries on this page,
     * because #[Computed] writes its memo with ??= and so cannot cache a null return -
     * a month with no row re-queried on every single read. Keying a collection sidesteps
     * that entirely: a miss is a null array entry, not another round trip.
     *
     * Unbounded by design. A personal budget accumulates twelve rows a year, so the
     * whole history is smaller than one page of the query log it replaces.
     *
     * @return SupportCollection<int, MonthlyFinance>
     */
    #[Computed]
    public function finances(): SupportCollection
    {
        return Auth::user()->monthlyFinances()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get()
            ->keyBy(fn (MonthlyFinance $finance): int => $finance->year * 100 + $finance->month);
    }

    /**
     * The cash flow row for the current month, if one has been entered.
     */
    #[Computed]
    public function currentMonth(): ?MonthlyFinance
    {
        return $this->financeFor(now()->year, now()->month);
    }

    /**
     * The cash flow row for the month before this one, for the stat-tile deltas.
     */
    #[Computed]
    public function previousMonth(): ?MonthlyFinance
    {
        $previous = now()->subMonth();

        return $this->financeFor($previous->year, $previous->month);
    }

    /**
     * Net worth as of the current month: assets minus liabilities.
     */
    #[Computed]
    public function netWorth(): float
    {
        return $this->portfolio->netWorth(now()->year, now()->month);
    }

    /**
     * Everything the user owns and owes, loaded once for the whole page.
     *
     * Two queries whatever the asset count. Every figure below - the hero tile, the
     * twelve points of the trend line, the allocation bar, both group totals - is
     * answered from this one in-memory collection.
     *
     * @see \App\Support\Portfolio
     */
    #[Computed]
    public function portfolio(): Portfolio
    {
        return Portfolio::for(Auth::user());
    }

    /**
     * The charted window as a list of [year, month, label], oldest first.
     *
     * @return array<int, array{year: int, month: int, label: string}>
     */
    #[Computed]
    public function window(): array
    {
        return collect(range(self::MONTHS - 1, 0))
            ->map(function (int $back): array {
                $date = now()->subMonths($back);

                return [
                    'year' => $date->year,
                    'month' => $date->month,
                    'label' => $date->translatedFormat('M'),
                ];
            })
            ->all();
    }

    /**
     * Net worth per month across the window, for the area chart.
     *
     * A month with no recorded value for any asset yields null, not zero. Charting it as
     * zero would claim the user had nothing, when the data only says nothing was
     * measured - and on a net worth chart that reads as having been wiped out.
     *
     * @return array<int, array{label: string, value: float|null}>
     */
    #[Computed]
    public function netWorthSeries(): array
    {
        return array_map(fn (array $month): array => [
            'label' => $month['label'],
            'value' => $this->portfolio->hasAnyValueBy($month['year'], $month['month'])
                ? $this->portfolio->netWorth($month['year'], $month['month'])
                : null,
        ], $this->window);
    }

    /**
     * The change in net worth between the two most recently recorded months.
     *
     * Recorded, not merely the previous slot in the window - otherwise a gap in the
     * history reports the entire net worth as this month's gain.
     */
    #[Computed]
    public function netWorthChange(): ?float
    {
        $recorded = array_values(array_filter(
            $this->netWorthSeries,
            fn (array $point): bool => $point['value'] !== null
        ));

        if (count($recorded) < 2) {
            return null;
        }

        return $recorded[count($recorded) - 1]['value'] - $recorded[count($recorded) - 2]['value'];
    }

    /**
     * Income and spending per month across the window, for the grouped columns.
     *
     * @return array<int, array{label: string, values: array<int, float>}>
     */
    #[Computed]
    public function cashFlowSeries(): array
    {
        return array_map(function (array $month): array {
            $finance = $this->financeFor($month['year'], $month['month']);

            return [
                'label' => $month['label'],
                'values' => [(float) ($finance?->income ?? 0), (float) ($finance?->spending ?? 0)],
            ];
        }, $this->window);
    }

    /**
     * This month's asset value grouped by type, for the allocation bar.
     *
     * @return array<int, array{name: string, value: float, var: string}>
     */
    #[Computed]
    public function allocation(): array
    {
        return $this->portfolio->allocation(now()->year, now()->month);
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
     * Total value of everything owned, this month.
     */
    #[Computed]
    public function assetsTotal(): float
    {
        return $this->portfolio->ownedTotal(now()->year, now()->month);
    }

    /**
     * Total value of everything owed, this month.
     */
    #[Computed]
    public function liabilitiesTotal(): float
    {
        return $this->portfolio->owedTotal(now()->year, now()->month);
    }

    /**
     * One asset's value this month, for the owned/owed lists.
     *
     * Public, like latestValue() on the assets page, because the template calls it -
     * Livewire binds the view to the component so protected would in fact work, but a
     * method the markup depends on belongs to this component's public surface.
     */
    public function currentValue(Asset $asset): float
    {
        return $asset->valueAt(now()->year, now()->month);
    }

    /**
     * The last six months of cash flow, newest first.
     *
     * Sliced from the already-loaded collection rather than queried again - finances()
     * is ordered newest first for exactly this.
     *
     * @return SupportCollection<int, MonthlyFinance>
     */
    #[Computed]
    public function recentMonths(): SupportCollection
    {
        return $this->finances->take(6)->values();
    }

    /**
     * One month's cash flow row from the loaded collection, or null if none exists.
     */
    protected function financeFor(int $year, int $month): ?MonthlyFinance
    {
        return $this->finances->get($year * 100 + $month);
    }

}; ?>

<section class="w-full max-w-5xl">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
            <flux:subheading>{{ Carbon::now()->translatedFormat('F Y') }}</flux:subheading>
        </div>

        <flux:button :href="route('month.show')" wire:navigate icon="pencil-square" size="sm">
            {{ __('Enter this month') }}
        </flux:button>
    </div>

    {{-- Hero figure: exactly one per view, and net worth is what this app is for. --}}
    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <x-stat-tile
            :label="__('Net worth')"
            :value="$this->netWorth"
            :delta="$this->netWorthChange"
            :delta-label="__('vs last month')"
            hero
        />

        <div class="grid gap-4 sm:grid-cols-3 lg:col-span-2">
            <x-stat-tile
                :label="__('Income')"
                :value="$this->currentMonth?->income ?? 0"
                :delta="$this->currentMonth && $this->previousMonth ? (float) $this->currentMonth->income - (float) $this->previousMonth->income : null"
            />
            <x-stat-tile
                :label="__('Spending')"
                :value="$this->currentMonth?->spending ?? 0"
                :delta="$this->currentMonth && $this->previousMonth ? (float) $this->currentMonth->spending - (float) $this->previousMonth->spending : null"
                :up-is-good="false"
            />
            <x-stat-tile
                :label="__('Saved')"
                :value="$this->currentMonth?->savings ?? 0"
                :delta="$this->currentMonth && $this->previousMonth ? $this->currentMonth->savings - $this->previousMonth->savings : null"
            />
        </div>
    </div>

    @unless ($this->currentMonth)
        <flux:callout icon="information-circle" class="mt-4">
            <flux:callout.heading>{{ __('Nothing entered for this month yet') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('The numbers above stay at zero until you record this month\'s income and spending.') }}
            </flux:callout.text>
        </flux:callout>
    @endunless

    {{-- Net worth over time. One series, so the heading names it and no legend is needed. --}}
    <div class="mt-8 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading>{{ __('Net worth') }}</flux:heading>
        <flux:subheading>{{ __('Assets minus liabilities, last :count months', ['count' => $this::MONTHS]) }}</flux:subheading>

        <x-chart.area
            class="mt-4"
            :points="$this->netWorthSeries"
            :label="__('Net worth over the last :count months', ['count' => $this::MONTHS])"
        />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-5">
        {{-- Two series, so a legend is always present. --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading>{{ __('Income & spending') }}</flux:heading>
            <flux:subheading>{{ __('Per month, last :count months', ['count' => $this::MONTHS]) }}</flux:subheading>

            <x-chart.columns
                class="mt-4"
                :points="$this->cashFlowSeries"
                :series="[
                    ['name' => __('Income'), 'var' => '--viz-1'],
                    ['name' => __('Spending'), 'var' => '--viz-2'],
                ]"
                :label="__('Income and spending per month')"
            />
        </div>

        {{-- Part-to-whole: a horizontal stacked bar, not a donut. Asset types have long
             names and several can be on screen at once. --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-2 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading>{{ __('Allocation') }}</flux:heading>
            <flux:subheading>{{ __('What you own, by type, this month') }}</flux:subheading>

            <x-chart.stacked-bar class="mt-4" :segments="$this->allocation" :label="__('Asset allocation by type')" />
        </div>
    </div>

    {{-- What the net worth figure is actually made of, split the way it is calculated:
         the two groups are summed with opposite signs, so they are shown apart rather
         than as one alphabetical list where a mortgage sits next to a flat. --}}
    <div class="mt-6 grid items-start gap-6 sm:grid-cols-2">
        @foreach ([
            [
                'heading' => __('Assets'),
                'caption' => __('What you own'),
                'group' => $this->assets,
                'total' => $this->assetsTotal,
                'sign' => '',
                'empty' => __('Nothing owned recorded yet.'),
            ],
            [
                'heading' => __('Liabilities'),
                'caption' => __('What you owe'),
                'group' => $this->liabilities,
                'total' => $this->liabilitiesTotal,
                'sign' => '−',
                'empty' => __('Nothing owed recorded yet.'),
            ],
        ] as $section)
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-baseline justify-between gap-3">
                    <flux:heading>{{ $section['heading'] }}</flux:heading>
                    <flux:heading class="tabular-nums">
                        {{ $section['total'] > 0 ? $section['sign'] : '' }}{{ Money::kr($section['total']) }}
                    </flux:heading>
                </div>
                <flux:subheading>{{ $section['caption'] }}</flux:subheading>

                @if ($section['group']->isEmpty())
                    <flux:text class="mt-3">
                        {{ $section['empty'] }}
                        <flux:link :href="route('assets.index')" wire:navigate>{{ __('Add one.') }}</flux:link>
                    </flux:text>
                @else
                    <div class="mt-3 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($section['group'] as $asset)
                            <div class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                <x-asset-icon :type="$asset->type" size="sm" />

                                <div class="min-w-0 flex-1">
                                    <div class="truncate">
                                        <flux:link :href="route('assets.show', $asset)" wire:navigate class="text-sm font-medium">{{ $asset->name }}</flux:link>
                                    </div>
                                    <flux:text size="sm">{{ $asset->type->label() }}</flux:text>
                                </div>

                                <div class="text-end text-sm font-medium tabular-nums text-zinc-800 dark:text-zinc-100">
                                    {{ Money::kr($this->currentValue($asset)) }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- The table view: every charted value is reachable here, so no number is gated
         behind a hover. --}}
    <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading>{{ __('Recent months') }}</flux:heading>

        @if ($this->recentMonths->isEmpty())
            <flux:text class="mt-2">{{ __('No months recorded yet.') }}</flux:text>
        @else
            <flux:table class="mt-3 min-w-[30rem]">
                <flux:table.columns>
                    <flux:table.column>{{ __('Month') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Income') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Spending') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Saved') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->recentMonths as $finance)
                        <flux:table.row :key="$finance->id">
                            <flux:table.cell variant="strong">
                                <flux:link
                                    :href="route('month.show', ['year' => $finance->year, 'month' => $finance->month])"
                                    wire:navigate
                                >
                                    {{ Carbon::create($finance->year, $finance->month)->translatedFormat('F Y') }}
                                </flux:link>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ Money::kr($finance->income) }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ Money::kr($finance->spending) }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">
                                <span class="{{ $finance->savings < 0 ? 'text-[var(--viz-bad)]' : '' }}">
                                    {{ Money::kr($finance->savings) }}
                                </span>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
