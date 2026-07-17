{{-- resources/views/emails/site-owner-order-notification.blade.php --}}
@component('mail::message')
Hello {{ $publisherName }},

A new order has been placed for your site **{{ $site->site_name }}**.

## Order Summary

**Order Numbers:** {{ $orderNumbers }}
**Order Count:** {{ $orderCount }} item(s)
**Total Amount:** €{{ number_format($totalAmount, 2) }}

@component('mail::button', ['url' => url('/publisher/sites')])
View Your Sites
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent