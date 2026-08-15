@component('mail::message')
@if($kind === 'problem')
# We reviewed your problem report

@if($resolved)
Thanks for telling us about **{{ $subjectLabel }}**. We have marked this report as resolved.
@else
Thanks for telling us about **{{ $subjectLabel }}**. We reviewed it and marked it as **{{ $item->status }}**.
@endif
@else
# We reviewed your suggestion

@if($resolved)
Thanks for the suggestion. We have accepted it and will take it from here.
@else
Thanks for the suggestion. We reviewed it and marked it as **{{ $item->status }}**.
@endif
@endif

@if($notes !== '')
**Note from our team:**  
{{ $notes }}
@endif

@component('mail::button', ['url' => $actionUrl])
Open {{ config('app.name') }}
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
