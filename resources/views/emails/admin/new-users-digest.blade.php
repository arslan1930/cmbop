@component('mail::message')
# {{ $count === 1 ? '1 new user registered' : $count.' new users registered' }}

Hello {{ $adminFirstName }},

These accounts signed up since the last digest:

@component('mail::table')
| Name | Email | Role | Registered |
|------|-------|------|------------|
@foreach($rows as $row)
| {{ $row['name'] }} | {{ $row['email'] }} | {{ $row['role'] }} | {{ $row['registered'] }} |
@endforeach
@endcomponent

@component('mail::button', ['url' => $ctaUrl])
Open Audiences
@endcomponent

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
