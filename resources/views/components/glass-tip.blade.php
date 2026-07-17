@props([
    'title' => null,
    'body' => '',
    'label' => 'More information',
    'placement' => 'top',
    'size' => 'md', // md = 16px, lg = 18px
])

@php
    $classes = 'glass-tip-trigger' . ($size === 'lg' ? ' glass-tip-trigger--lg' : '');
@endphp

<button
    type="button"
    {{ $attributes->class($classes) }}
    data-glass-tip
    @if($title) data-glass-tip-title="{{ $title }}" @endif
    data-glass-tip-body="{{ $body }}"
    data-glass-tip-placement="{{ $placement }}"
    aria-label="{{ $label }}"
>
    {{-- Lucide "Info" — stroke inherits currentColor --}}
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="glass-tip-icon"
        aria-hidden="true"
        focusable="false"
    >
        <circle cx="12" cy="12" r="10"></circle>
        <path d="M12 16v-4"></path>
        <path d="M12 8h.01"></path>
    </svg>
</button>
