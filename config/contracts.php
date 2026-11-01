<?php

return [
    // A contract ends soon (and its reminder goes out) this many days before its end date (owner: two weeks).
    'reminder_days' => (int) env('CONTRACTS_REMINDER_DAYS', 14),
];
