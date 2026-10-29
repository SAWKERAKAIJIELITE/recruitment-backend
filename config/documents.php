<?php

/*
 * Documents of people and applications (D-057): ID card images, certificates, CVs, official documents, contracts.
 * Files are kept on a private disk under generated names and are never served from a public URL.
 */
return [
    'disk' => env('DOCUMENTS_DISK', 'local'),
    'directory' => 'documents',

    'max_file_kilobytes' => (int) env('DOCUMENTS_MAX_FILE_KILOBYTES', 10240),

    // Accepted files: the type detected from the content must be one of these, with a matching extension.
    'types' => [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ],
];
