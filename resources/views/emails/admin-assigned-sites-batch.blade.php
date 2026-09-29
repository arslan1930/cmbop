@component('mail::message')
# {{ $count }} websites are waiting for your acceptance

Hi {{ $publisherName }},

Our team added {{ $count }} {{ $count === 1 ? 'website' : 'websites' }} to your account. Please review them and accept each one so they appear in your My Sites list.

@foreach($domains as $domain)
- {{ $domain }}
@endforeach
@if($extra > 0)
- and {{ $extra }} more
@endif

After you accept:
- The sites show under My Sites
- Staff review each listing. The Verified badge is a separate TXT step
- Catalog Activate is not automatic

@component('mail::button', ['url' => $acceptUrl])
Review & accept
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
