@component('mail::message')
@if(!empty($advertiserCompleted))
Hi {{ $greetingName }},

We're happy to let you know that your order #{{ $order->order_number }} is now complete, and all links are live. You can find the full summary of your published links in the attached document.

We hope you're pleased with the results!

<strong>Ready to place another order?</strong>

Boost your online presence even further by securing additional high-quality links. Click below to get started on your next order and stay ahead of the competition:

@component('mail::button', ['url' => $catalogUrl])
Place a new order
@endcomponent

Thank you for choosing us, and we look forward to working with you again soon!
@else
# {{ $heading }}

Hello {{ $firstName }},

{{ $copy }}

@if(!empty($reviewReady) || ($changeKind === 'status' && in_array($newValue ?? '', ['review', 'completed'], true)))
@include('emails.partials.placement-urls', [
    'placements' => $placements ?? [],
    'missingLabel' => 'Not submitted yet',
    'urlHeading' => 'Live article URL',
])
@endif

## Status

@include('emails.partials.summary-table', ['rows' => [
    ['label' => 'Previous', 'value' => $previousLabel],
    ['label' => 'New', 'value' => $newLabel],
    ['label' => 'Updated', 'value' => $updatedAt],
]])

## Order summary

@include('emails.partials.summary-table', ['rows' => $summaryRows ?? []])

@component('mail::button', ['url' => $ctaUrl])
{{ $ctaLabel }}
@endcomponent

@include('emails.partials.mail-support', ['supportLine' => $supportLine ?? null, 'intent' => 'order'])

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endif
@endcomponent
