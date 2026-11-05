<?php

return [
    // The header of every exported report (as in the legacy workbook): two lines above the report's title.
    'organization' => env('REPORTS_ORGANIZATION', 'وزارة الدفاع السورية'),
    'department' => env('REPORTS_DEPARTMENT', 'إدارة التوجيه المعنوي  |  فرع القوة البشرية ــ قسم التوظيف'),

    // An image (PNG or JPG) printed at the start of the header, e.g. storage/app/reports/logo.png. None by default.
    'logo' => env('REPORTS_LOGO'),

    // The list for شعبة التجنيد: what its "الجهة العسكرية" column says for every row.
    'military_entity' => env('REPORTS_MILITARY_ENTITY', 'التوجيه المعنوي'),

    // Dates and times printed on reports, and the days and months a report counts by.
    'timezone' => env('REPORTS_TIMEZONE', 'Asia/Damascus'),

    // The most rows one exported table may hold: narrow the filters beyond that.
    'max_rows' => ['xlsx' => (int) env('REPORTS_MAX_XLSX_ROWS', 20000), 'pdf' => (int) env('REPORTS_MAX_PDF_ROWS', 2000)],
];
