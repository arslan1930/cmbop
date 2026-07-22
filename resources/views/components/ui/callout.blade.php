@props([
    'variant' => 'attention',
    'icon' => null,
    'role' => 'status',
    'banner' => false,
])

@php
    $variant = in_array($variant, ['attention', 'info', 'success', 'danger'], true)
        ? $variant
        : 'attention';

    $defaultIcons = [
        'attention' => 'fa-circle-exclamation',
        'info' => 'fa-circle-info',
        'success' => 'fa-circle-check',
        'danger' => 'fa-triangle-exclamation',
    ];

    $iconClass = $icon ?: ($defaultIcons[$variant] ?? 'fa-circle-exclamation');
    if (! str_contains($iconClass, 'fa-')) {
        $iconClass = 'fa-'.$iconClass;
    }

    $role = in_array($role, ['status', 'alert'], true) ? $role : 'status';
@endphp

<div {{ $attributes->class([
        'ui-callout',
        'ui-callout--'.$variant,
        'ui-callout--banner' => $banner,
    ]) }} role="{{ $role }}">
    @if($banner)
        <div class="ui-callout__main">
            <span class="ui-callout__icon" aria-hidden="true">
                <i class="fa-solid {{ $iconClass }}"></i>
            </span>
            <div class="ui-callout__body">{{ $slot }}</div>
        </div>
        @isset($actions)
            <div class="ui-callout__actions">{{ $actions }}</div>
        @endisset
    @else
        <span class="ui-callout__icon" aria-hidden="true">
            <i class="fa-solid {{ $iconClass }}"></i>
        </span>
        <div class="ui-callout__body">{{ $slot }}</div>
    @endif
</div>
