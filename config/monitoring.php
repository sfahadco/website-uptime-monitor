<?php

return [
    // Seconds a single site gets to respond before the check counts as down.
    'timeout' => env('MONITOR_TIMEOUT', 10),

    // Websites checked concurrently by one queued job.
    'batch_size' => env('MONITOR_BATCH_SIZE', 50),

    // Seconds a batch job may run before the worker kills it. Must leave room
    // for `timeout` plus the pool's own overhead and the status writes.
    'job_timeout' => env('MONITOR_JOB_TIMEOUT', 120),

    // How many batch jobs `monitor:dispatch` pushes per queue round trip.
    'dispatch_chunk' => env('MONITOR_DISPATCH_CHUNK', 50),

    // Demo data volume.
    'seed' => [
        'clients' => env('MONITOR_SEED_CLIENTS', 300),
        'max_websites_per_client' => env('MONITOR_SEED_MAX_WEBSITES', 10),
    ],
];
