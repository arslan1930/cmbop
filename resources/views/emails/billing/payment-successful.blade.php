@component('mail::message')
# Payment Successful

Hello {{ $user->name }},

Your payment was successful. Your invoice is attached to this email.

## Payment confirmation

| | |
|:--|:--|
| **Invoice** | {{ $invoice->invoice_number }} |
| **Order** | #{{ $order?->order_number ?? $invoice->order_number }} |
| **Amount paid** | {{ $symbol }}{{ number_format((float) $invoice->total_amount, 2) }} {{ $invoice->currency }} |
| **Payment method** | {{ ucfirst((string) $invoice->payment_method) }} |
| **Transaction ID** | {{ $invoice->transaction_id }} |
| **Paid at** | {{ optional($invoice->paid_at)->format('F j, Y g:i A') ?? '—' }} |

## Order summary

@foreach(($order?->items ?? collect()) as $item)
- **{{ $item->site_name }}** — {{ $symbol }}{{ number_format((float) $item->price, 2) }}
@endforeach

@component('mail::button', ['url' => $viewOrderUrl, 'color' => 'primary'])
View Order
@endcomponent

@component('mail::button', ['url' => $downloadInvoiceUrl])
Download Invoice
@endcomponent

@component('mail::button', ['url' => $dashboardUrl])
Go to Dashboard
@endcomponent

Thank you for choosing {{ config('app.name') }}.

@endcomponent
