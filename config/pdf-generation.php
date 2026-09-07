<?php

return [
    'queue' => env('PDF_GENERATION_QUEUE', 'pdf'),
    'job_timeout_seconds' => (int) env('PDF_GENERATION_JOB_TIMEOUT', 900),
    'download_retention_minutes' => (int) env('PDF_GENERATION_DOWNLOAD_RETENTION_MINUTES', 60),
    'record_retention_days' => (int) env('PDF_GENERATION_RECORD_RETENTION_DAYS', 7),
];
