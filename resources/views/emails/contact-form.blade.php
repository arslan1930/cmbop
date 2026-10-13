<x-mail::message>
# New contact form message

**Name:** {{ $senderName }}  
**Email:** {{ $senderEmail }}

{{ $body }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
