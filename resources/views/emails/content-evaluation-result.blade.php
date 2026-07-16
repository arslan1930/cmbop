@component('mail::message')
# @if($approved)
Article approved for publication
@else
Article evaluation update
@endif

Hello {{ $firstName }},

@if($approved)
Great news — your article **{{ $submission->title ?: $submission->original_filename }}** passed uniqueness, quality, and compliance checks. You can now select websites and place an order from your Content Library.
@else
We evaluated **{{ $submission->title ?: $submission->original_filename }}**. It was saved to your Content Library, but it is not ready for ordering yet.

{{ $result['message'] ?? 'Please review the feedback and resubmit an improved .docx version.' }}
@endif

## Evaluation scores

| Check | Score |
|---|---|
| **Uniqueness** | {{ (int) ($result['uniqueness_score'] ?? $submission->uniqueness_score) }}% (minimum 50%) |
| **Quality** | {{ (int) ($result['quality_score'] ?? $submission->quality_score) }}% |
| **Status** | {{ ucfirst(str_replace('_', ' ', $submission->moderation_status)) }} |

@component('mail::button', ['url' => $libraryUrl])
@if($approved)
Open Content Library
@else
Review & resubmit
@endif
@endcomponent

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
