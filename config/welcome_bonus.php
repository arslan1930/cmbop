<?php

return [

    /*
    | Advertiser welcome credit granted on first qualifying signup.
    | Admin can disable this from Promotions Center without a deploy.
    */
    'amount' => 20.00,

    /*
    | Hard ceiling for admin-set and stored amounts. Grants and wallet
    | credits never exceed this even if the settings row is corrupt.
    */
    'max_amount' => 500.00,

    'enabled_default' => true,

    'cookie_name' => 'slb_welcome_claimed',

    /*
    | Minutes the browser cookie lasts after a successful claim.
    | 365 days — same browser cannot collect the bonus again after clearing nothing.
    */
    'cookie_minutes' => 525600,

    /*
    | Cloudflare edge CIDRs. CF-Connecting-IP is only trusted when REMOTE_ADDR
    | is in this list — otherwise anyone hitting origin can spoof CF headers
    | and collect another €20 per fake IP.
    | Source: https://www.cloudflare.com/ips-v4 and /ips-v6 (2026-08-15).
    */
    'cloudflare_cidrs' => [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],

];
