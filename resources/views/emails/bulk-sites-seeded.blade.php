@component('mail::message')
# Your websites are ready to finish

Hi {{ $publisherName }},

We added **{{ $createdCount }}** website(s) to your account from your bulk request.

Please complete the remaining details for each site (description, niches, link type, turnaround, publication time, example URL). When you finish a site, it goes to our team for approval — it will not appear in the catalog until approved and activated.

@component('mail::button', ['url' => $completeUrl])
Complete website details
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
