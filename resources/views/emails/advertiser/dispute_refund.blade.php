@component('mail::message')
# Refund credited

Dear {{ $advertiser->name }},

Your link-removed dispute for order **#{{ $dispute->order->order_number ?? $dispute->order_id }}** was **upheld**.

**€{{ number_format($credited, 2) }}** has been credited back to your advertiser wallet.

@if($dispute->admin_notes)
**Notes:** {{ $dispute->admin_notes }}
@endif

@component('mail::button', ['url' => $balanceUrl])
View balance
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
