@if($item->safeStartsAt() || $item->safeEndsAt())
    {{ optional($item->safeStartsAt())->format('M j') ?? 'Now' }}
    →
    {{ optional($item->safeEndsAt())->format('M j, Y') ?? '∞' }}
@else
    Always
@endif
