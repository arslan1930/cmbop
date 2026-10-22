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

    /*
    | Recheck completed live URLs this many days after the last check.
    */
    'live_url_recheck_stale_days' => (int) env('ORDER_LIVE_URL_RECHECK_STALE_DAYS', 7),

    /*
    | Max completed placements to recheck per scheduled run.
    */
    'live_url_recheck_limit' => (int) env('ORDER_LIVE_URL_RECHECK_LIMIT', 40),

    /*
    | Completed placements with a failed live-URL check stay in Needs you
    | for this many days after the last check.
    */
    'live_url_down_window_days' => (int) env('ORDER_LIVE_URL_DOWN_WINDOW_DAYS', 90),

    /*
    | Optional 12-month link guarantee add-on. Advertiser pays a percent of
    | the placement; publishers pay nothing. Monthly HTTP + href checks.
    */
    'writing' => [
        'deadline_days' => (int) env('ORDER_WRITING_DEADLINE_DAYS', 5),
        'turnaround_text' => (string) env('ORDER_WRITING_TURNAROUND_TEXT', '2–3 working days'),
    ],

    'link_guarantee' => [
        'percent' => (float) env('ORDER_LINK_GUARANTEE_PERCENT', 10),
        'months' => (int) env('ORDER_LINK_GUARANTEE_MONTHS', 12),
        'restore_days' => (int) env('ORDER_LINK_GUARANTEE_RESTORE_DAYS', 7),
        'check_interval_days' => (int) env('ORDER_LINK_GUARANTEE_CHECK_DAYS', 28),
        'check_limit' => (int) env('ORDER_LINK_GUARANTEE_CHECK_LIMIT', 40),
    ],

];
