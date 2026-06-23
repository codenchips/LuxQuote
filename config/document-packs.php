<?php

return [
    'qpdf_binary' => env('QPDF_BINARY', 'qpdf'),
    'upload_disk' => env('DOCUMENT_PACK_DISK', 'local'),
    'max_upload_kilobytes' => (int) env('DOCUMENT_PACK_MAX_UPLOAD_KB', 25600),
    'process_timeout_seconds' => (int) env('DOCUMENT_PACK_PROCESS_TIMEOUT', 60),
];
