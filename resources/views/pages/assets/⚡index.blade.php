<?php

use App\Enums\AssetType;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Assets')] class extends Component {
    public string $name = '';

    public string $type = '';

    /**
     * The things the user owns.
     *
     * @return Collection<int, \App\Models\Asset>
     */
    #[Computed]
    public function assets(): Collection
    {
        return $this->allAssets()->reject(fn ($asset) => $asset->type->isLiability());
    }

    /**
     * The things the user owes.
     *
     * @return Collection<int, \App\Models\Asset>
     */
    #[Computed]
    public function liabilities(): Collection
    {
        return $this->allAssets()->filter(fn ($asset) => $asset->type->isLiability());
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

        unset($this->allAssets, $this->assets, $this->liabilities);

        Flux::toast(variant: 'success', text: __('Asset added.'));
    }

    /**
     * Delete one of the current user's assets.
     */
    public function delete(int $assetId): void
    {
        Auth::user()->assets()->findOrFail($assetId)->delete();

        unset($this->allAssets, $this->assets, $this->liabilities);

        Flux::toast(variant: 'success', text: __('Asset deleted.'));
    }

    /**
     * Every asset belonging to the current user, loaded once per request.
     *
     * @return Collection<int, \App\Models\Asset>
     */
    #[Computed]
    protected function allAssets(): Collection
    {
        return Auth::user()->assets()->orderBy('name')->get();
    }
}; ?>

<section class="w-full max-w-3xl">
    <flux:heading size="xl">{{ __('Assets & liabilities') }}</flux:heading>
    <flux:subheading>{{ __('What you own and what you owe. Values are entered per month.') }}</flux:subheading>

    <form wire:submit="save" class="mt-6 flex items-start gap-3">
        <flux:input wire:model="name" :label="__('Name')" class="flex-1" />

        <flux:select wire:model="type" :label="__('Type')" :placeholder="__('Choose a type')" class="w-56">
            @foreach (AssetType::cases() as $assetType)
                <flux:select.option value="{{ $assetType->value }}">
                    {{ $assetType->label() }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:button variant="primary" type="submit" class="mt-6">{{ __('Add') }}</flux:button>
    </form>

    <div class="mt-10 space-y-10">
        @foreach ([__('Assets') => $this->assets, __('Liabilities') => $this->liabilities] as $heading => $group)
            <div>
                <flux:heading>{{ $heading }}</flux:heading>

                @if ($group->isEmpty())
                    <flux:text class="mt-2">{{ __('Nothing here yet.') }}</flux:text>
                @else
                    <flux:table class="mt-2">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Type') }}</flux:table.column>
                            <flux:table.column />
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($group as $asset)
                                <flux:table.row :key="$asset->id">
                                    <flux:table.cell variant="strong">{{ $asset->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $asset->type->label() }}</flux:table.cell>
                                    <flux:table.cell align="end">
                                        <flux:button
                                            variant="subtle"
                                            size="sm"
                                            icon="trash"
                                            wire:click="delete({{ $asset->id }})"
                                            wire:confirm="{{ __('Delete :name and all its values?', ['name' => $asset->name]) }}"
                                        />
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </div>
        @endforeach
    </div>
</section>
