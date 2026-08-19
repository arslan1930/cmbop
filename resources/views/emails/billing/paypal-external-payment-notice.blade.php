@component('mail::message')
@if($kind === 'partial_refund')
# Partial PayPal refund
@elseif($kind === 'reversed')
# PayPal payment reversed
@elseif($kind === 'dispute_created')
# PayPal buyer dispute
@elseif($kind === 'dispute_resolved')
# PayPal dispute update
@else
# PayPal refunded a completed order
@endif

Dear {{ $user->name ?? 'there' }},

@if($kind === 'partial_refund')
PayPal refunded {{ $amountLabel }} on {{ $orderLabel }}. The marketplace order was **not** automatically cancelled. Support will adjust the order if needed — your wallet was not changed by this notice.
@elseif($kind === 'dispute_created')
A buyer opened a PayPal dispute on {{ $orderLabel }}. No wallet or order status change was applied automatically.
@elseif($kind === 'dispute_resolved')
PayPal updated a buyer dispute on {{ $orderLabel }}. Check the order and your wallet; this email is a notice only.
@elseif($audience === 'publisher')
PayPal {{ $kind === 'reversed' ? 'reversed' : 'refunded' }} a **completed** guest post ({{ $orderLabel }}). Your publisher payout was **clawed back** (or recorded as wallet debt if that balance was already spent).
@else
PayPal {{ $kind === 'reversed' ? 'reversed' : 'refunded' }} {{ $amountLabel }} for completed order {{ $orderLabel }}. A credit note was issued. Your advertiser wallet was **not** credited (PayPal already returned the money to the payer). The order stays marked completed.
@endif

@if($referenceCode)
- **Checkout reference:** {{ $referenceCode }}
@endif

@component('mail::button', ['url' => $ctaUrl, 'color' => 'primary'])
{{ $audience === 'publisher' ? 'View tasks' : 'View orders' }}
@endcomponent

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent
