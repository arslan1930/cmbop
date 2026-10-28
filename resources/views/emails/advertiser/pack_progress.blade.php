@component('mail::message')
# {{ $complete ? $pack->label().' complete' : $pack->label().' progress' }}

@if($complete)
All articles in your pack on **{{ $siteName }}** are done ({{ $published }} of {{ $packSize }} published).
@else
An article in your pack on **{{ $siteName }}** was published.

**{{ $published }} of {{ $packSize }} published**
@endif

Reference: {{ $pack->reference_code }}

@component('mail::button', ['url' => $ordersUrl])
View pack
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
