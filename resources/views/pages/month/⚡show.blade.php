<?php

use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Month')] class extends Component {
    public int $year;

    public int $month;

    /**
     * Start on the current month.
     */
    public function mount(): void
    {
        $now = now();

        $this->year = $now->year;
        $this->month = $now->month;
    }

    /**
     * Step one month back.
     */
    public function previousMonth(): void
    {
        $date = Carbon::create($this->year, $this->month)->subMonth();

        $this->year = $date->year;
        $this->month = $date->month;
    }

    /**
     * Step one month forward.
     */
    public function nextMonth(): void
    {
        $date = Carbon::create($this->year, $this->month)->addMonth();

        $this->year = $date->year;
        $this->month = $date->month;
    }
}; ?>

<section class="w-full max-w-3xl">
    <flux:heading size="xl">{{ __('Month') }}</flux:heading>

    <div class="mt-6 flex items-center gap-4">
        <flux:button icon="chevron-left" wire:click="previousMonth" />

        <flux:heading>{{ Carbon::create($year, $month)->translatedFormat('F Y') }}</flux:heading>

        <flux:button icon="chevron-right" wire:click="nextMonth" />
    </div>
</section>
