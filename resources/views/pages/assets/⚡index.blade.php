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
     * The asset open in the edit modal. Client-controlled, so every read of it goes
     * through the current user's relation rather than Asset::find().
     */
    public ?int $editingId = null;

    public string $editingName = '';

    public string $editingType = '';

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
        $validated = $this->validate($this->assetRules('name', 'type'));

        Auth::user()->assets()->create($validated);

        $this->reset(['name', 'type']);

        $this->forgetAssets();

        Flux::toast(text: __('Asset added.'), variant: 'success');
    }

    /**
     * Open the edit modal for one of the current user's assets.
     */
    public function edit(int $assetId): void
    {
        $asset = Auth::user()->assets()->findOrFail($assetId);

        $this->editingId = $asset->id;
        $this->editingName = $asset->name;
        $this->editingType = $asset->type->value;

        // Otherwise an error left over from a previous edit greets the next one.
        $this->resetValidation();

        Flux::modal('edit-asset')->show();
    }

    /**
     * Save the open asset's name and type.
     *
     * Re-typing is the point as much as renaming: an asset entered before its type
     * existed - a car recorded as "Other asset" - can be moved without deleting it and
     * losing every value recorded against it.
     */
    public function saveEdit(): void
    {
        $validated = $this->validate($this->assetRules('editingName', 'editingType'));

        // Scoped through the relation, exactly as delete() and the month form are:
        // $editingId arrives from the browser, and Asset::find() would hand back another
        // user's row and let this rename it.
        $asset = Auth::user()->assets()->findOrFail($this->editingId);

        $asset->update([
            'name' => $validated['editingName'],
            'type' => $validated['editingType'],
        ]);

        $this->reset(['editingId', 'editingName', 'editingType']);

        $this->forgetAssets();

        Flux::modal('edit-asset')->close();

        Flux::toast(text: __('Asset updated.'), variant: 'success');
    }

    /**
     * The rules for one asset's editable fields, under whichever property names the
     * form uses.
     *
     * Shared so the two paths cannot drift: an edit form that validates less than the
     * add form is how an asset with no type or a 300-character name gets in.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function assetRules(string $nameField, string $typeField): array
    {
        return [
            $nameField => ['required', 'string', 'max:255'],
            $typeField => ['required', Rule::enum(AssetType::class)],
        ];
    }

    /**
     * Drop the memoised collections after a write, so the page re-reads them.
     */
    protected function forgetAssets(): void
    {
        unset($this->allAssets, $this->assets, $this->liabilities, $this->assetsTotal, $this->liabilitiesTotal);
    }

    /**
     * Delete one of the current user's assets.
     */
    public function delete(int $assetId): void
    {
        Auth::user()->assets()->findOrFail($assetId)->delete();

        $this->forgetAssets();

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
                <x-asset-type-options />
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
                                    {{-- truncate on the wrapper, not the link: flux:link carries
                                         its own `inline`, and Tailwind emits .inline after .block, so
                                         the link stays inline whatever this element's class order says
                                         - and overflow:hidden does nothing to an inline box. --}}
                                    <div class="truncate">
                                        <flux:link :href="route('assets.show', $asset)" wire:navigate class="font-medium">{{ $asset->name }}</flux:link>
                                    </div>
                                    <flux:text size="sm">{{ $asset->type->label() }}</flux:text>
                                </div>

                                <div class="hidden sm:block">
                                    <x-chart.sparkline
                                        :values="$this->history($asset)"
                                        :var="'--viz-'.$asset->type->seriesSlot()"
                                    />
                                </div>

                                {{-- shrink-0, or the amount is squeezed narrower than itself and,
                                     being text-end and unbreakable, spills leftwards over the
                                     name instead of the name truncating. --}}
                                <div class="shrink-0 text-end">
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
                                    icon="pencil-square"
                                    class="opacity-40 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                                    wire:click="edit({{ $asset->id }})"
                                    :aria-label="__('Edit :name', ['name' => $asset->name])"
                                />

                                <flux:button
                                    variant="subtle"
                                    size="sm"
                                    icon="trash"
                                    class="opacity-40 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                                    wire:click="delete({{ $asset->id }})"
                                    wire:confirm="{{ __('Delete :name and all its values?', ['name' => $asset->name]) }}"
                                    :aria-label="__('Delete :name', ['name' => $asset->name])"
                                />
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <flux:modal name="edit-asset" class="w-full max-w-md" focusable>
        <form wire:submit="saveEdit" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Edit') }}</flux:heading>
                <flux:subheading>
                    {{ __('Renaming or re-typing keeps every value already recorded against it.') }}
                </flux:subheading>
            </div>

            <flux:input wire:model="editingName" :label="__('Name')" />

            <flux:select wire:model="editingType" :label="__('Type')" :placeholder="__('Choose a type')">
                <x-asset-type-options />
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
