<?php

return [
    'nomination_number' => [
        // Tokens: {YYYY} year, {YY} two-digit year, {SEQ} counter for that year (zero padded).
        // Digits only on purpose: candidates type it on any keyboard layout.
        'format' => '{YYYY}{SEQ}',
        'sequence_digits' => 6,
    ],

    // Key of the national-ID blind index. Leave empty to derive it from APP_KEY.
    'blind_index_key' => env('RECRUITMENT_BLIND_INDEX_KEY'),
];
