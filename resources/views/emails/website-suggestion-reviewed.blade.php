@component('mail::message')
@if($accepted)
# We will try to add {{ $siteName }}

Thanks for suggesting **{{ $siteName }}**. We accepted the request and will work on adding it to the catalog when it fits the marketplace.
@else
# Update on {{ $siteName }}

Thanks for suggesting **{{ $siteName }}**. We reviewed it and marked it as **{{ $suggestion->status }}**.
@endif

@if($notes !== '')
**Note from our team:**  
{{ $notes }}
@endif

@component('mail::button', ['url' => $actionUrl])
Open the catalog
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
