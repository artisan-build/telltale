<?php

declare(strict_types=1);

return [
    'ingest' => [
        'max_body_bytes' => (int) env('TELLTALE_MAX_BODY_BYTES', 1_048_576),
        'max_events_per_batch' => (int) env('TELLTALE_MAX_EVENTS_PER_BATCH', 100),
        'ip_rate_per_minute' => (int) env('TELLTALE_IP_RATE_PER_MINUTE', 240),
        'app_rate_per_minute' => (int) env('TELLTALE_APP_RATE_PER_MINUTE', 120),
        'install_rate_per_minute' => (int) env('TELLTALE_INSTALL_RATE_PER_MINUTE', 60),
        'app_daily_event_cap' => (int) env('TELLTALE_APP_DAILY_EVENT_CAP', 100_000),
        'install_daily_event_cap' => (int) env('TELLTALE_INSTALL_DAILY_EVENT_CAP', 10_000),
    ],
    'storage' => [
        'session_inactivity_minutes' => (int) env('TELLTALE_SESSION_INACTIVITY_MINUTES', 30),
        'raw_event_retention_days' => (int) env('TELLTALE_RAW_EVENT_RETENTION_DAYS', 90),
    ],
];
