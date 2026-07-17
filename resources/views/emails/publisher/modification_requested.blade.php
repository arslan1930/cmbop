{{-- resources/views/emails/publisher/modification_requested.blade.php --}}
@component('mail::message')
# Modification Requested

Dear Publisher,

The advertiser has requested modifications for Order **#{{ $order->order_number }}**.

## Reason for Modification:
> {{ $reason }}

## Order Details:
- **Order Number:** {{ $order->order_number }}
- **Reference Code:** {{ $order->reference_code }}
- **Total Amount:** €{{ number_format($order->total_amount, 2) }}
- **Status:** Processing (awaiting your updates)

## Next Steps:
1. Please review the modification request above
2. Make the required changes to your content
3. Resubmit the updated live URL

@component('mail::button', ['url' => route('publisher.tasks')])
View Order
@endcomponent

The 48-hour auto-approve timer has been stopped. The countdown will restart once you resubmit the updated live URL.

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent