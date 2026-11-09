<?php

return [
    // A contract ends soon (and its reminder goes out) this many days before its end date (owner: two weeks).
    'reminder_days' => (int) env('CONTRACTS_REMINDER_DAYS', 14),

    // A vacancies file covers this many months from its first day (owner: three months; D-088).
    'vacancy_months' => (int) env('CONTRACTS_VACANCY_MONTHS', 3),
    // Vacancies files are kept on a private disk under generated names, like imports.
    'vacancies_disk' => env('CONTRACTS_VACANCIES_DISK', 'local'),
    'vacancies_directory' => 'vacancies',
    'vacancies_max_file_kilobytes' => (int) env('CONTRACTS_VACANCIES_MAX_FILE_KILOBYTES', 5120),
];
