@props([
    'label' => '',
    'value' => '',
    'hint' => null,
    'icon' => null,
])

<div {{ $attributes->class(['ui-kpi-card card border-0 shadow-sm h-100']) }}>
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
                <div class="small text-muted mb-1">{{ $label }}</div>
                <div class="fs-4 fw-semibold text-dark">{{ $value }}</div>
                @if($hint)
                    <div class="small text-muted mt-1">{{ $hint }}</div>
                @endif
            </div>
            @if($icon)
                <div class="ui-kpi-card__icon" aria-hidden="true">
                    <i class="fa {{ $icon }}"></i>
                </div>
            @endif
        </div>
        {{ $slot }}
    </div>
</div>

<style>
.ui-kpi-card__icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--brand-primary-bg, #e8f8f7);
    color: var(--brand-primary, #0b6266);
}
</style>
