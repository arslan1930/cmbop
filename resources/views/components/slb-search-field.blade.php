@props([
    'name' => 'q',
    'id' => null,
    'value' => '',
    'placeholder' => 'Search…',
    'label' => 'Search',
    'showLabel' => true,
    'labelClass' => 'form-label fw-semibold small text-muted mb-1',
    'inputClass' => 'form-control form-control-sm',
    'mode' => 'form',
    'title' => 'Type at least 2 characters, or press Enter. Clear restores the full list.',
])

@php
    $inputId = $id ?: $name.'SearchInput';
    $clearId = $inputId.'Clear';
    $statusId = $inputId.'Status';
    $value = scalar_text($value);
@endphp

@if($showLabel)
    <label class="{{ $labelClass }}" for="{{ $inputId }}">{{ $label }}</label>
@endif
<div class="position-relative slb-search-wrap">
    <input type="search"
           name="{{ $name }}"
           id="{{ $inputId }}"
           class="{{ $inputClass }}"
           value="{{ $value }}"
           placeholder="{{ $placeholder }}"
           title="{{ $title }}"
           autocomplete="off"
           enterkeyhint="search"
           aria-describedby="{{ $statusId }}"
           @if($mode !== '' && $mode !== null)
               data-slb-live-search="{{ $mode }}"
           @endif
           data-slb-live-clear="{{ $clearId }}"
           data-slb-live-status="{{ $statusId }}"
           {{ $attributes }}>
    <button type="button"
            id="{{ $clearId }}"
            class="btn btn-sm btn-link slb-search-clear{{ $value !== '' ? '' : ' d-none' }}"
            aria-label="Clear search">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
    </button>
</div>
<div id="{{ $statusId }}" class="form-text slb-search-status" role="status" aria-live="polite"></div>
