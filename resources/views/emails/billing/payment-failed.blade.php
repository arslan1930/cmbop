@component('mail::message')
# Payment Failed

Hello {{ $user->name }},

We could not verify your payment for order **#{{ $order?->order_number ?? $document->order_number }}**.

| | |
|:--|:--|
| **Reason** | {{ $reason }} |
| **Transaction reference** | {{ $document->transaction_id }} |
| **Attempted amount** | {{ $symbol }}{{ number_format((float) $document->total_amount, 2) }} {{ $document->currency }} |
| **Attempt date** | {{ optional($document->invoice_date)->format('F j, Y g:i A') }} |

### Suggested next steps
1. Check that your card or wallet has sufficient funds.
2. Try a different payment method.
3. Contact support if the problem continues.

@component('mail::button', ['url' => $retryUrl, 'color' => 'primary'])
Retry Payment
@endcomponent

No tax invoice was generated for this failed attempt.

@endcomponent
