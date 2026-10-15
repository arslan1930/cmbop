{{-- Closed-row sensitive-topic indicator. Casino is never included. --}}
@php
    $sensitiveChip = null;
    try {
        $sensitiveChip = \App\Support\CatalogSensitiveTopics::chip($sensitivePrices ?? []);
    } catch (\Throwable $e) {
        report($e);
    }
@endphp
@if($sensitiveChip)
    @if(! empty($openDetailsId))
        <button type="button"
                class="site-chip site-chip--sensitive site-chip--descriptor"
                data-catalog-open-details="{{ $openDetailsId }}"
                data-catalog-open-section="sensitive"
                data-no-tip
                aria-label="{{ $sensitiveChip['label'] }} — choose add-on in Details">
            <span>{{ $sensitiveChip['label'] }}</span>
        </button>
    @else
        <span class="site-chip site-chip--sensitive site-chip--descriptor"
              aria-label="{{ $sensitiveChip['label'] }}">
            <span>{{ $sensitiveChip['label'] }}</span>
        </span>
    @endif
@endif
