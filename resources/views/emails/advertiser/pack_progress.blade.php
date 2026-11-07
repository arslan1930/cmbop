@component('mail::message')
# {{ $complete ? $pack->label().' complete' : $pack->label().' progress' }}

@if($complete)
All articles in your pack on **{{ $siteName }}** are done ({{ $published }} of {{ $packSize }} published).
@else
An article in your pack on **{{ $siteName }}** was published.

**{{ $published }} of {{ $packSize }} published**
@endif

@include('emails.partials.placement-urls', [
    'placements' => $placements ?? [],
    'missingLabel' => 'Not published yet',
    'urlHeading' => 'Live article URL',
])

@component('mail::button', ['url' => $ordersUrl])
View pack
@endcomponent

@include('emails.partials.mail-support', ['supportLine' => $supportLine ?? null, 'intent' => 'order'])

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
