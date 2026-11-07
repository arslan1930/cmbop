@component('mail::message')
# Order confirmed · Managed by SLB

Hello {{ $firstName ?? ($order->user?->name ?? 'there') }},

{{ $bodyCopy }}

@include('emails.partials.placement-urls', [
    'placements' => $placements ?? [],
    'missingLabel' => 'You’ll get the live URL here when it’s published.',
    'urlHeading' => 'Live article URL',
])

@include('emails.partials.summary-table', ['rows' => [
    ['label' => 'Order number', 'value' => '#'.$order->order_number],
]])

@component('mail::button', ['url' => $ctaUrl ?? route('advertiser.orders', ['focus' => 'order', 'order' => $order->id])])
View Order Details
@endcomponent

@include('emails.partials.mail-support', ['supportLine' => $supportLine ?? null, 'intent' => 'order'])

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
