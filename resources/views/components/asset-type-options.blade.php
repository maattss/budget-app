@use('App\Enums\AssetType')

{{--
    The type options for an asset select, grouped the way the app groups everything else.

    Real <optgroup>, not disabled value="" options. flux:select renders a native <select>
    and injects its placeholder as <option value="" disabled selected>; a second
    empty-valued option later in the markup wins the selection and the placeholder is
    lost.
--}}
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
