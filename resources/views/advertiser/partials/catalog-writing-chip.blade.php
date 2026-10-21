@php
    $writingOffered = false;
    $writingFeeLabel = '';
    $guaranteeOffered = false;
    try {
        $fees = app(\App\Services\Marketplace\WritingFeeService::class);
        $addons = app(\App\Services\Marketplace\CheckoutAddOnService::class);
        $writingOffered = $fees->writingOffered($site);
        $writer = $addons->requestedWriter($site);
        $fee = $addons->checkoutViewItems([['id' => $site->id]])[0]['writing_fee'] ?? 0;
        $writingFeeLabel = $fee > 0 ? format_money_pay($fee) : '';
        $guaranteeOffered = $addons->siteQualifiesForGuarantee($site);
    } catch (\Throwable $e) {
        report($e);
    }
@endphp
<p class="small text-muted mb-0 mt-2">
    @if($writingOffered)
        Publisher can write the article
        @if($writingFeeLabel !== '')
            ({{ $writingFeeLabel }} writing fee)
        @endif
        — choose at checkout.
    @else
        SEOLinkBuildings can write the article
        @if($writingFeeLabel !== '')
            ({{ $writingFeeLabel }} writing fee)
        @endif
        — choose at checkout.
    @endif
</p>
@if($guaranteeOffered)
    <p class="small text-muted mb-0">Optional 12-month link guarantee at checkout (publication period is 12 months or permanent).</p>
@endif
