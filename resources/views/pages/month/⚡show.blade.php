<?php

use App\Models\MonthlyFinance;
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
     * Start on the current month.
     */
    public function mount(): void
    {
        $now = now();

        $this->year ??= $now->year;
        $this->month ??= $now->month;

        $this->loadCashFlow();
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
}; ?>

<section class="w-full max-w-3xl">
    <flux:heading size="xl">{{ __('Month') }}</flux:heading>

    <div class="mt-6 flex items-center gap-4">
        <flux:button icon="chevron-left" wire:click="previousMonth" />

        <flux:heading>{{ Carbon::create($year, $month)->translatedFormat('F Y') }}</flux:heading>

        <flux:button icon="chevron-right" wire:click="nextMonth" />
    </div>

    <form wire:submit="saveCashFlow" class="mt-8 max-w-md space-y-6">
        <flux:input wire:model="income" :label="__('Income')" inputmode="decimal" />

        <flux:input wire:model="spending" :label="__('Spending')" inputmode="decimal" />

        <div class="flex items-center justify-between">
            <flux:text>
                {{ __('Savings') }}:
                <span class="font-medium">{{ number_format($this->savings, 2, ',', ' ') }}</span>
            </flux:text>

            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
