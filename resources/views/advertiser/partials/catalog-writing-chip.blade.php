@php
    $writingOffered = false;
    $writingFeeLabel = '';
    try {
        $fees = app(\App\Services\Marketplace\WritingFeeService::class);
        $writingOffered = $fees->writingOffered($site);
        if ($writingOffered) {
            $fee = $fees->resolvedAdvertiserWritingFee($site);
            $writingFeeLabel = $fee > 0 ? format_money_pay($fee) : '';
        }
    } catch (\Throwable $e) {
        report($e);
    }
@endphp
@if($writingOffered)
    <p class="small text-muted mb-0 mt-2">
        Publisher can write the article
        @if($writingFeeLabel !== '')
            ({{ $writingFeeLabel }} writing fee)
        @endif
        — choose at checkout.
    </p>
@endif
