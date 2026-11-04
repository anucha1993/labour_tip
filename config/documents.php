<?php

/*
 * คลังเอกสาร: limits for "download as zip". The zip is streamed straight from storage (R2 or local)
 * to the browser, so memory stays flat; these caps bound how long one request may keep a worker busy.
 */
return [
    // Most files one zip may contain. Larger selections must be split.
    'zip_max_files' => (int) env('DOCS_ZIP_MAX_FILES', 500),

    // Most data (MB, uncompressed) one zip may contain.
    'zip_max_mb' => (int) env('DOCS_ZIP_MAX_MB', 1024),

    // How many zips may be streamed at the same time on this server (each one streams files from R2).
    'zip_concurrent' => (int) env('DOCS_ZIP_CONCURRENT', 2),
];
