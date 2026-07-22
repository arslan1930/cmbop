@props([
    'icon' => 'fa-inbox',
    'title' => 'Nothing here yet',
    'message' => null,
    'primaryLabel' => null,
    'primaryUrl' => null,
    'secondaryLabel' => null,
    'secondaryUrl' => null,
])

<div {{ $attributes->class(['ui-empty-state text-center mx-auto py-4']) }} style="max-width: 420px;">
    <div class="ui-empty-state__icon mx-auto mb-3" aria-hidden="true">
        <i class="fa-solid {{ $icon }}"></i>
    </div>
    <h5 class="mb-2">{{ $title }}</h5>
    @if($message)
        <p class="text-muted mb-3">{{ $message }}</p>
    @endif
    @if($slot->isNotEmpty())
        <div class="mb-3">{{ $slot }}</div>
    @endif
    @if($primaryLabel || $secondaryLabel)
        <div class="d-flex flex-wrap justify-content-center gap-2">
            @if($primaryLabel && $primaryUrl)
                <a href="{{ $primaryUrl }}" class="btn btn-primary btn-sm">{{ $primaryLabel }}</a>
            @endif
            @if($secondaryLabel && $secondaryUrl)
                <a href="{{ $secondaryUrl }}" class="btn btn-outline-secondary btn-sm">{{ $secondaryLabel }}</a>
            @endif
        </div>
    @endif
</div>

<style>
.ui-empty-state__icon {
    width: 52px; height: 52px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: var(--brand-primary-bg, #e6f5f5);
    color: var(--brand-primary, #185054);
    font-size: 1.25rem;
}
</style>
