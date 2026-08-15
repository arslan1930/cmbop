@component('mail::message')
# Placement clawback

Dear {{ $publisher->name }},

A link-removed dispute was **upheld** for order **#{{ $dispute->order->order_number ?? $dispute->order_id }}**.

@if($debited > 0)
- **Debited from your wallet:** €{{ number_format($debited, 2) }}
@endif
@if($debtCreated > 0)
- **Outstanding debt:** €{{ number_format($debtCreated, 2) }} (withdrawals are blocked until this is cleared)
@endif

@if($dispute->admin_notes)
**Admin notes:** {{ $dispute->admin_notes }}
@endif

Please keep completed placements live. If you believe this was a mistake, contact support.

@component('mail::button', ['url' => $balanceUrl])
View balance
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
