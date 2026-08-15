@if($item->starts_at || $item->ends_at)
    {{ optional($item->starts_at)->format('M j') ?? 'Now' }}
    →
    {{ optional($item->ends_at)->format('M j, Y') ?? '∞' }}
@else
    Always
@endif
