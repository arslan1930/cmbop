@php
    $reliability = null;
    try {
        $reliability = \App\Models\PublisherReliabilityStat::presentationFor((int) ($site->publisher_id ?? 0));
    } catch (\Throwable $e) {
        report($e);
    }
    $variant = ($variant ?? 'details') === 'chip' ? 'chip' : 'details';
@endphp
@if($reliability && !empty($reliability['badge']) && $variant === 'chip')
    <span class="badge text-bg-success-subtle text-success border" title="Based on this publisher’s completed SEOLinkBuildings orders">Reliable publisher</span>
@elseif($reliability && !empty($reliability['visible']) && $variant === 'details')
    <div class="catalog-publisher-reliability small mt-2">
        @if(!empty($reliability['badge']))
            <div class="mb-1">
                <span class="badge text-bg-success-subtle text-success border">Reliable publisher</span>
            </div>
        @endif
        <ul class="list-unstyled mb-0 text-muted">
            @if($reliability['response_rate'] !== null)
                <li>Response rate {{ $reliability['response_rate'] }}%</li>
            @endif
            @if($reliability['avg_response_label'])
                <li>Avg. response {{ $reliability['avg_response_label'] }}</li>
            @endif
            @if($reliability['publication_rate'] !== null)
                <li>Publication rate {{ $reliability['publication_rate'] }}%</li>
            @endif
            @if($reliability['avg_delivery_label'])
                <li>Avg. delivery {{ $reliability['avg_delivery_label'] }}</li>
            @endif
            @if($reliability['completed_bucket'])
                <li>Completed orders {{ $reliability['completed_bucket'] }}</li>
            @endif
        </ul>
    </div>
@endif
