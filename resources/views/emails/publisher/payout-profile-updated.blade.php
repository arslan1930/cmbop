@component('mail::message')
# Payout details updated

Dear {{ $userName }},

Our support team has updated your locked payout details for **{{ strtoupper($method) }}**.

For security, publishers cannot change payout methods themselves after the first confirmation. If you did not request this change, contact us immediately at {{ $supportEmail }}.

@component('mail::button', ['url' => route('publisher.withdraw')])
View withdraw page
@endcomponent

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent
