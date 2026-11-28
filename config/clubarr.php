<?php

return [
    'version' => '0.7.0-beta',

    // Seconds Clubarr waits for a media server.
    'http_timeout' => (int) env('CLUBARR_HTTP_TIMEOUT', 15),

    // Where the one-time setup token is kept until the first admin exists.
    'setup_token_path' => env('CLUBARR_SETUP_TOKEN_PATH', storage_path('app/setup-token')),

    // Days before access ends that the member is told.
    'expiry_warning_days' => (int) env('CLUBARR_EXPIRY_WARNING_DAYS', 3),

    // Where the nightly database copies go (default: "backups" next to the database).
    'backup_path' => env('CLUBARR_BACKUP_PATH'),
];
