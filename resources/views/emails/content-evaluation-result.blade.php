@component('mail::message')
# @if($approved)
Article approved for publication
@else
Article needs changes
@endif

Hello {{ $firstName }},

@if($approved)
Your article **{{ $submission->title ?: $submission->original_filename }}** is approved. You can select websites and place an order from your Content Library.
@else
Your article **{{ $submission->title ?: $submission->original_filename }}** was saved to your Content Library, but it needs changes before you can order.

@if(!empty($result['message']))
**Reason:** {{ $result['message'] }}
@endif

@php
    $terms = $result['matched_terms'] ?? ($result['report']['matched_terms'] ?? []);
    $blockedUrls = $result['blocked_urls'] ?? ($result['report']['blocked_urls'] ?? []);
    $hints = $result['report']['fix_hints'] ?? [];
@endphp

@if(is_array($terms) && count($terms))
**Terms to remove or rewrite:** {{ implode(', ', array_slice($terms, 0, 12)) }}
@endif

@if(is_array($blockedUrls) && count($blockedUrls))
**Blocked links to remove:** {{ implode(', ', array_slice($blockedUrls, 0, 5)) }}
@endif

@if(is_array($hints) && count($hints))
@foreach($hints as $hint)
- {{ $hint }}
@endforeach
@endif

Open the article in Content Library to see highlighted text and links in the preview, then edit and resubmit.
@endif

@component('mail::button', ['url' => $libraryUrl])
@if($approved)
Open Content Library
@else
Fix article
@endif
@endcomponent

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
