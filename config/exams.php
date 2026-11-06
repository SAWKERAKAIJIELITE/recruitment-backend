<?php

// Exam sessions (D-073 to D-075).
return [
    // Candidates reach the exam only from these networks: addresses or CIDR ranges, comma-separated (the venue's
    // halls). None: refused everywhere except in the local and testing environments (D-077).
    'candidate_networks' => array_values(array_filter(array_map('trim', explode(',', (string) env('EXAMS_CANDIDATE_NETWORKS', ''))))),

    // A candidate may start from the session's start until this many minutes later; then absent (owner: 15).
    'late_minutes' => (int) env('EXAMS_LATE_MINUTES', 15),

    // Candidates may log in this many minutes before their session starts (to read the instructions).
    'login_early_minutes' => (int) env('EXAMS_LOGIN_EARLY_MINUTES', 60),

    // A candidate's token lasts this long; every exam request still checks the attempt's own deadline.
    'candidate_token_minutes' => (int) env('EXAMS_CANDIDATE_TOKEN_MINUTES', 360),

    // The most one extension may add (for everyone, or for one attempt).
    'max_extension_minutes' => 240,
];
