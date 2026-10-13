@component('mail::message')
@if($isInstant)
# Wallet topped up
@else
# Deposit Approved
@endif

Dear {{ $deposit->user?->name ?? 'Advertiser' }},

@if($isPaypal)
Your PayPal payment succeeded and €{{ number_format($deposit->amount, 2) }} has been added to your wallet.
@elseif($isCard)
Your card payment succeeded and €{{ number_format($deposit->amount, 2) }} has been added to your wallet.
@else
Your deposit request has been approved and the funds have been added to your wallet.
@if(!empty($approvalFxLine))
{{ $approvalFxLine }}
@endif
@endif

@if($receipt)
Your deposit receipt PDF is attached.
@endif

## Deposit Details:

- Amount: €{{ number_format($deposit->amount, 2) }}
- Reference Code: {{ $deposit->reference_code }}
- Payment method: {{ \App\Models\Invoice::paymentMethodLabel($deposit->payment_method) }}
@if($isInstant)
- Credited At: {{ optional($deposit->approved_at ?? $deposit->paid_at)->format('M d, Y H:i') ?? now()->format('M d, Y H:i') }}
@else
- Approved At: {{ optional($deposit->approved_at)->format('M d, Y H:i') ?? now()->format('M d, Y H:i') }}
@endif
@if($receipt)
- Receipt: {{ $receipt->invoice_number }}
@endif

## Your Current Balance:

€{{ number_format($walletBalance ?? 0, 2) }}

@component('mail::button', ['url' => $balanceUrl, 'color' => 'primary'])
View Balance
@endcomponent

@if(!empty($downloadReceiptUrl))
@component('mail::button', ['url' => $downloadReceiptUrl])
Download Receipt
@endcomponent
@endif

Thank you for using {{ mail_brand_name() }}!

Thanks,<br>
{{ mail_brand_name() }} Team
@endcomponent
