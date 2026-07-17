@component('mail::message')
# Refund Receipt

Hello {{ $user->name }},

A refund has been processed for your order.

| | |
|:--|:--|
| **Refund receipt** | {{ $refund->invoice_number }} |
| **Refund amount** | {{ $symbol }}{{ number_format((float) $refund->total_amount, 2) }} {{ $refund->currency }} |
| **Refund date** | {{ optional($refund->invoice_date)->format('F j, Y g:i A') }} |
| **Transaction ID** | {{ $refund->transaction_id }} |
| **Original invoice** | {{ $originalInvoice?->invoice_number ?? data_get($refund->meta, 'original_invoice', '—') }} |
| **Reason** | {{ $reason }} |

Your refund receipt PDF is attached.

@component('mail::button', ['url' => $downloadUrl, 'color' => 'primary'])
Download Refund Receipt
@endcomponent

@component('mail::button', ['url' => $ordersUrl])
View Orders
@endcomponent

@endcomponent
