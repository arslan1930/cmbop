@component('mail::message')
# Welcome, {{ $user->name }}!

Thanks for joining **{{ config('app.name', 'SEOLinkBuildings') }}**. Your account is ready — explore verified publishers and place your first order whenever you’re ready.

@component('mail::button', ['url' => $catalogUrl])
Browse Websites
@endcomponent

Or go straight to your [dashboard]({{ $dashboardUrl }}).

Thanks,<br>
{{ config('app.name') }}
@endcomponent
