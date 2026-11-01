<?php

return [
    // The first line of every exported report.
    'organization' => env('REPORTS_ORGANIZATION', 'نظام التوظيف والعقود'),

    // Dates and times printed on reports.
    'timezone' => env('REPORTS_TIMEZONE', 'Asia/Damascus'),

    // The most applications one exported list may hold: narrow the filters beyond that.
    'max_rows' => ['xlsx' => (int) env('REPORTS_MAX_XLSX_ROWS', 20000), 'pdf' => (int) env('REPORTS_MAX_PDF_ROWS', 2000)],
];
