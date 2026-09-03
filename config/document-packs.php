<?php

return [
    'qpdf_binary' => env('QPDF_BINARY', 'qpdf'),
    'upload_disk' => env('DOCUMENT_PACK_DISK', 'local'),
    'max_upload_kilobytes' => (int) env('DOCUMENT_PACK_MAX_UPLOAD_KB', 25600),
    'process_timeout_seconds' => (int) env('DOCUMENT_PACK_PROCESS_TIMEOUT', 60),
    'legal_page_pdf' => env('LEGAL_PAGE_PDF', resource_path('documents/legal/full-legal-page.pdf')),
    'generated_pdf_cleanup' => [
        'root' => storage_path('app'),
        'output_retention_hours' => (int) env('GENERATED_PDF_OUTPUT_RETENTION_HOURS', 24),
        'download_retention_minutes' => (int) env('GENERATED_PDF_DOWNLOAD_RETENTION_MINUTES', 60),
        'temp_retention_hours' => (int) env('GENERATED_PDF_TEMP_RETENTION_HOURS', 24),
    ],
];
