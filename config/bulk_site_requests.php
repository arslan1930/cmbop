<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SLA aging (admin / marketing bulk queue)
    |--------------------------------------------------------------------------
    |
    | Age is days since the request was created. Amber and red are inclusive
    | thresholds for the waiting pill. Overdue is the red threshold.
    |
    */
    'sla' => [
        'amber_days' => max(1, (int) env('BULK_SLA_AMBER_DAYS', 3)),
        'red_days' => max(1, (int) env('BULK_SLA_RED_DAYS', 7)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Publisher reminders
    |--------------------------------------------------------------------------
    |
    | Manual Remind is always available on rows waiting on the publisher.
    | Automatic reminders stay off unless BULK_PUBLISHER_REMINDER_AUTO=true.
    | The scheduler still registers the command; the command no-ops when
    | auto is disabled so a missed flag cannot mail anyone.
    |
    */
    'publisher_reminder' => [
        'auto_enabled' => filter_var(env('BULK_PUBLISHER_REMINDER_AUTO', false), FILTER_VALIDATE_BOOLEAN),
        'min_age_days' => max(1, (int) env('BULK_PUBLISHER_REMINDER_MIN_AGE_DAYS', 3)),
        'cooldown_days' => max(1, (int) env('BULK_PUBLISHER_REMINDER_COOLDOWN_DAYS', 7)),
    ],

];
