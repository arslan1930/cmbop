@component('mail::message')
# Manual Payment Required

A customer has placed an order with manual payment.

**Customer:** {{ $customer->name }}
**Total Amount:** €{{ number_format($totalAmount, 2) }}

Please review and confirm payment.

@component('mail::button', ['url' => url('/admin/payments')])
View Payments
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent