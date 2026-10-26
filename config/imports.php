<?php

/*
 * Excel import of the legacy "بيانات المرشحين" sheet (analysis doc §09, §23, §24; D-040).
 *
 * Columns are found by their header text, never by position, so moved or extra columns do not break the
 * import. Headers are compared after Arabic normalization (أ/ا, ى/ي, ة/ه, harakat and punctuation
 * ignored). An exact match wins; otherwise the longest alias contained in the header.
 * The letters in the comments are the columns of the real template (header row 5, data from row 6); the
 * first alias of each field is that template's exact header.
 *
 * To support a renamed header, add it to the field's list below.
 */
return [
    'headers' => [
        'nominating_entity' => ['الجهة المرشحة', 'الجهة المرشِّحة', 'جهة الترشيح', 'الجهة'],          // B
        'job_position' => ['المسمى الوظيفي', 'المسمى', 'الوظيفة'],                                    // C
        'application_category' => ['الفئة', 'فئة الترشيح'],                                           // D
        'existing_employee_number' => ['الرقم الذاتي', 'الرقم الذاتي للموظف'],                        // E (optional)
        'full_name' => ['الاسم الثلاثي', 'الاسم الكامل', 'اسم المرشح', 'الاسم'],                       // F
        'mother_name' => ['اسم الأم', 'اسم الام'],                                                    // G
        'birth_place' => ['مكان الولادة', 'محل الولادة', 'مكان الميلاد'],                              // H
        'birth_date' => ['تاريخ الولادة', 'تاريخ الميلاد', 'المواليد'],                                // I
        'national_number' => ['الرقم الوطني'],                                                        // J
        'marital_status' => ['الحالة الاجتماعية'],                                                    // K
        'health' => ['الحالة الصحية/بالتفصيل', 'الحالة الصحية بالتفصيل', 'الحالة الصحية'],                                      // L
        'residence_district' => ['محافظة الإقامة', 'محافظة السكن', 'المحافظة'],                       // M
        'residence_place' => ['مكان الإقامة الحالية', 'مكان الإقامة الحالي', 'مكان الإقامة', 'مكان السكن', 'العنوان'],   // N
        'academic_qualification' => ['المؤهل العلمي', 'المؤهل'],                                      // O
        'specialization' => ['الاختصاص', 'التخصص'],                                                   // P
        'studying_years' => ['عدد السنين الدراسية', 'عدد السنوات الدراسية', 'سنوات الدراسة'],         // Q (optional)
        'quran_parts' => ['عدد الأجزاء المحفوظة من القرآن', 'الأجزاء المحفوظة', 'عدد الأجزاء'],       // R (optional)
        'other_government_entity' => ['هل تعمل مع جهة حكومية أخرى', 'العمل لدى جهة حكومية أخرى', 'يعمل لدى جهة حكومية أخرى'],   // S
        'overnight_work' => ['الالتزام بالمبيت والدوام بنسبة 50%', 'الالتزام بالمبيت', 'المبيت'],     // T
        'full_commitment' => ['التفرغ الكامل للعمل', 'التفرغ الكامل', 'التفرغ'],                     // U
        'phone_number' => ['رقم الهاتف', 'الهاتف', 'رقم الجوال', 'الجوال'],                           // V
        'notes' => ['ملاحظات', 'الملاحظات'],                                                          // W (optional)
    ],

    // Sheet to read: the first whose name contains this (normalised). Otherwise the sheet whose header
    // row matches the most columns.
    'sheet_name' => 'بيانات المرشحين',

    // The header row is searched for in the first rows of the sheet (titles may sit above it).
    'header_search_rows' => 20,

    // Upload and approval run inside the request. Measured: 2,000 rows take about 5 s to upload and 12 s
    // to approve (linear). Raise this only together with a queued approval (D-041).
    'max_rows' => (int) env('IMPORTS_MAX_ROWS', 2000),
    // The real legacy workbook is 16.5 MB (11 formula sheets besides the candidates sheet). Memory is guarded
    // by the unpacked size of the parts actually read (max_unpacked_megabytes), not by this.
    'max_file_kilobytes' => (int) env('IMPORTS_MAX_FILE_KILOBYTES', 30720),
    'max_unpacked_megabytes' => 100,   // chosen sheet + shared strings + styles, unpacked (the real file: about 6 MB)

    // Where original files are kept (never deleted: the batch's evidence).
    'disk' => env('IMPORTS_DISK', 'local'),
    'directory' => 'imports',
];
