<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operating company
    |--------------------------------------------------------------------------
    |
    | legal_name must be the exact Companies House spelling (TEQNO LTD).
    | Do not put "Partners with" in this value. 16607074 is a leftover
    | TOPURLZ LTD number — never pair it with Teqno Ltd.
    |
    | Address and support email below are already used on the public site.
    |
    */

    'legal_name' => env('COMPANY_LEGAL_NAME', 'Teqno Ltd'),

    'registration_no' => env('COMPANY_REGISTRATION_NO', '17392540'),

    'address' => [
        'street' => '20 Wenlock Road',
        'locality' => 'London',
        'region' => 'England',
        'postal_code' => 'N1 7GU',
        'country' => 'GB',
    ],

    'support_email' => env('COMPANY_SUPPORT_EMAIL', 'support@seolinkbuildings.com'),

    'description' => 'SEOLinkBuildings is a European guest post and link building marketplace with verified publishers.',

];
