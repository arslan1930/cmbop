<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operating company
    |--------------------------------------------------------------------------
    |
    | legal_name must be the exact Companies House spelling (TOPURLZ LTD or
    | TEQNO LTD). Leave it empty until that is confirmed. Do not put
    | "Partners with" in this value, and do not pair TEQNO LTD with company
    | number 16607074 (that number is TOPURLZ LTD).
    |
    | Address and support email below are already used on the public site.
    |
    */

    'legal_name' => env('COMPANY_LEGAL_NAME', ''),

    'registration_no' => env('COMPANY_REGISTRATION_NO', ''),

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
