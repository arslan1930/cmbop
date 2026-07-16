@props([
    'rows' => 4,
    'showHeader' => true,
])

<div {{ $attributes->class(['ui-skeleton']) }} aria-hidden="true" role="presentation">
    @if($showHeader)
        <div class="ui-skeleton__line ui-skeleton__line--lg mb-3" style="width:40%"></div>
    @endif
    @for($i = 0; $i < (int) $rows; $i++)
        <div class="ui-skeleton__line mb-2" style="width: {{ 70 + ($i % 3) * 10 }}%"></div>
    @endfor
</div>

<style>
.ui-skeleton__line {
    height: 12px;
    border-radius: 6px;
    background: linear-gradient(90deg, #eef2f6 25%, #f8fafc 50%, #eef2f6 75%);
    background-size: 200% 100%;
    animation: ui-skeleton-shimmer 1.2s ease-in-out infinite;
}
.ui-skeleton__line--lg { height: 18px; }
@media (prefers-reduced-motion: reduce) {
    .ui-skeleton__line { animation: none; }
}
@keyframes ui-skeleton-shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
</style>
