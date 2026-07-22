<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto-approve after live URL
    |--------------------------------------------------------------------------
    |
    | After the publisher submits a live URL, the advertiser has this many
    | hours to approve or request changes. If they take no action, the order
    | is auto-completed and the publisher is paid.
    |
    */
    'auto_approve_hours' => (int) env('ORDER_AUTO_APPROVE_HOURS', 72),

    /*
    | Send one reminder when this many hours remain before auto-approve.
    | Set to 0 to disable reminders.
    */
    'auto_approve_reminder_hours_before' => (int) env('ORDER_AUTO_APPROVE_REMINDER_HOURS_BEFORE', 24),

    /*
    | When true, skip auto-approve if the live URL health check failed
    | (live_url_check_ok = false). Null/unknown checks are still allowed.
    */
    'auto_approve_require_live_url_ok' => filter_var(
        env('ORDER_AUTO_APPROVE_REQUIRE_LIVE_URL_OK', true),
        FILTER_VALIDATE_BOOL
    ),

];
