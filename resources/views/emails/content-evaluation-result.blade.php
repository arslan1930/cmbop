@component('mail::message')
# @if($approved)
Article approved for publication
@else
Article update
@endif

Hello {{ $firstName }},

@if($approved)
Your article **{{ $submission->title ?: $submission->original_filename }}** is approved. You can select websites and place an order from your Content Library.
@else
Your article **{{ $submission->title ?: $submission->original_filename }}** was saved to your Content Library. Please open the article report there for details, then resubmit if needed.
@endif

@component('mail::button', ['url' => $libraryUrl])
@if($approved)
Open Content Library
@else
View article report
@endif
@endcomponent

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
