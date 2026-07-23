@component('mail::message')
# Your sites were added to Pending sites

Hi {{ $publisherName }},

We’ve added **{{ $createdCount }}** website(s) to your **Pending sites** from your bulk request.

Open them and finish any remaining details (description, niches, link type, turnaround, publication time, example URL). They stay hidden from advertisers until you finish and our team approves.

@component('mail::button', ['url' => $completeUrl])
Open Pending sites
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
