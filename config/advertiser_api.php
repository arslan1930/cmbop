<?php

return [
    'rate_per_minute' => (int) env('ADVERTISER_API_RATE_PER_MINUTE', 60),
    'max_webhooks' => 10,
    'events' => [
        'order.created',
        'order.accepted',
        'order.rejected',
        'order.published',
        'order.cancelled',
        'webhook.test',
    ],
];
