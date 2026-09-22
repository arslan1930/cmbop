@php
    $selectId = $selectId ?? '';
    $name = $name ?? $selectId;
    $label = $label ?? 'Choose';
    $current = (string) ($current ?? '');
    $form = $form ?? null;
    $modifier = $modifier ?? '';
    $normalized = [];
    foreach ($options ?? [] as $key => $option) {
        if (is_array($option)) {
            $normalized[] = [
                'value' => (string) ($option['value'] ?? ''),
                'label' => (string) ($option['label'] ?? ''),
            ];
        } else {
            $normalized[] = [
                'value' => (string) $key,
                'label' => (string) $option,
            ];
        }
    }
    $currentLabel = $label;
    foreach ($normalized as $option) {
        if ($option['value'] === $current) {
            $currentLabel = $option['label'];
            break;
        }
    }
@endphp
<div class="single-select-wrapper theme-select catalog-theme-select{{ $modifier !== '' ? ' '.$modifier : '' }}" data-theme-select="{{ $selectId }}">
    <select name="{{ $name }}" id="{{ $selectId }}" class="visually-hidden" tabindex="-1" @if($form) form="{{ $form }}" @endif>
        @foreach($normalized as $option)
            <option value="{{ $option['value'] }}" @selected($option['value'] === $current)>{{ $option['label'] }}</option>
        @endforeach
    </select>
    <button type="button"
            id="{{ $selectId }}-trigger"
            class="single-select-input single-select-input--sm"
            aria-haspopup="listbox"
            aria-expanded="false"
            aria-label="{{ $label }}">
        <span class="single-select-value">{{ $currentLabel }}</span>
        <i class="fa fa-chevron-down single-select-arrow" aria-hidden="true"></i>
    </button>
    <div class="single-select-dropdown">
        <div class="single-select-options" role="listbox" aria-label="{{ $label }}">
            @foreach($normalized as $option)
                <div class="single-select-option{{ $option['value'] === $current ? ' selected' : '' }}"
                     role="option"
                     data-value="{{ $option['value'] }}"
                     data-label="{{ $option['label'] }}"
                     aria-selected="{{ $option['value'] === $current ? 'true' : 'false' }}">
                    {{ $option['label'] }}
                </div>
            @endforeach
        </div>
    </div>
</div>
