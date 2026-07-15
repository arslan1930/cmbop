<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Withdrawal platform fee (%)
    |--------------------------------------------------------------------------
    |
    | Single source of truth for publisher withdrawal fees. Keep publisher UI,
    | admin UI, and notification emails in sync via this value.
    |
    */
    'withdrawal_fee_percent' => (float) env('WITHDRAWAL_FEE_PERCENT', 0),
];
