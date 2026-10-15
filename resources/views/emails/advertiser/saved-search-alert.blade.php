@component('mail::message')
# New sites match “{{ $searchName }}”

Hi {{ $firstName }},

New active listings match the saved catalog search you named {{ $searchName }}:

@foreach($siteNames as $name)
- {{ $name }}
@endforeach

Open the catalog to review them. Domain details follow the same reveal rules as the catalog.

@component('mail::button', ['url' => $catalogUrl])
Open this search
@endcomponent

You can turn off alerts or delete the search from [Saved searches]({{ $savedSearchesUrl }}).

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
