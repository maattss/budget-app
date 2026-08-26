<?php

use App\Models\Asset;
use App\Models\AssetValue;
use App\Models\MonthlyFinance;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * The cash flow row for the current month, if one has been entered.
     */
    #[Computed]
    public function currentMonth(): ?MonthlyFinance
    {
        $now = now();
        return Auth::user()->monthlyFinances()
            ->where('year', $now->year)
            ->where('month', $now->month)
            ->first();
    }

    /**
     * Net worth as of the current month: assets minus liabilities.
     */
    #[Computed]
    public function netWorth(): float
    {
        $now = now();
        $netWorth = 0.0;
        foreach ($this->assets as $asset) {
            $assetValue = $asset->values->first(
                fn (AssetValue $value) => $value->year === $now->year && $value->month === $now->month);
            $amount = $assetValue?->value ?? 0;

            if ($asset->type->isLiability()) {
                $netWorth = $netWorth - $amount;
            } else {
                $netWorth = $netWorth + $amount;
            }
        }

        return $netWorth;
    }

    /**
     * The user's assets with only the current month's value eager loaded.
     *
     * @return Collection<int, Asset>
     */
    #[Computed]
    public function assets(): Collection
    {
        $now = now();
        return Auth::user()->assets()->orderBy('name')->with(
            ['values' => fn ($query) => $query->where('year', $now->year)->where('month',$now->month)]
        )->get();
    }

    /**
     * The last six months of cash flow, newest first.
     *
     * @return Collection<int, MonthlyFinance>
     */
    #[Computed]
    public function recentMonths(): Collection
    {
        return Auth::user()->monthlyFinances()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(6)
            ->get();
    }
}; ?>

<section class="w-full max-w-4xl">
    <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
    <flux:subheading>{{ Carbon::now()->translatedFormat('F Y') }}</flux:subheading>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            __('Income') => (float) ($this->currentMonth?->income ?? 0),
            __('Spending') => (float) ($this->currentMonth?->spending ?? 0),
            __('Savings') => (float) ($this->currentMonth?->savings ?? 0),
            __('Net worth') => $this->netWorth,
        ] as $label => $amount)
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text size="sm">{{ $label }}</flux:text>
                <flux:heading size="lg" class="mt-1">{{ number_format($amount, 0, ',', ' ') }}</flux:heading>
            </div>
        @endforeach
    </div>

    @unless ($this->currentMonth)
        <flux:text class="mt-4">
            {{ __('Nothing entered for this month yet.') }}
            <flux:link :href="route('month.show')" wire:navigate>{{ __('Fill it in.') }}</flux:link>
        </flux:text>
    @endunless

    <div class="mt-12">
        <flux:heading>{{ __('Recent months') }}</flux:heading>

        @if ($this->recentMonths->isEmpty())
            <flux:text class="mt-2">{{ __('No months recorded yet.') }}</flux:text>
        @else
            <flux:table class="mt-2">
                <flux:table.columns>
                    <flux:table.column>{{ __('Month') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Income') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Spending') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Savings') }}</flux:table.column>
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
                            <flux:table.cell align="end">{{ number_format((float) $finance->income, 0, ',', ' ') }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format((float) $finance->spending, 0, ',', ' ') }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($finance->savings, 0, ',', ' ') }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
