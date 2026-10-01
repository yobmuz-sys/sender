<?php

declare(strict_types=1);

use App\Domain\System\Enums\Capability;

return [

    /*
    |---------------------------------------------------------------------------
    | Diagnostics
    |---------------------------------------------------------------------------
    |
    | The host capability inspector answers "can this machine run the platform?".
    | The result is always computed on demand; the flag below only controls who
    | is allowed to read it, because the report contains host-level detail.
    |
    */

    'diagnostics' => [
        'enabled' => (bool) env('SENDER_DIAGNOSTICS_ENABLED', false),
    ],

    /*
    |---------------------------------------------------------------------------
    | Resource limits
    |---------------------------------------------------------------------------
    |
    | Every hard boundary in the application is declared here so that business
    | logic never contains a magic number. Values are overridable per deployment
    | through environment variables, which is what makes the same codebase
    | usable on a small shared-hosting plan and on a larger one.
    |
    | Stages 4 and 5 (extraction and SMTP campaigns) will consume these limits.
    | They are declared now so that no consumer has to invent its own.
    |
    */

    'limits' => [

        // Bytes accepted for a single uploaded file.
        'max_upload_bytes' => (int) env('SENDER_LIMIT_MAX_UPLOAD_BYTES', 10 * 1024 * 1024),

        // Bytes accepted for pasted text (page source, article body, list).
        'max_text_input_bytes' => (int) env('SENDER_LIMIT_MAX_TEXT_INPUT_BYTES', 2 * 1024 * 1024),

        // Number of seed URLs accepted by one extraction request.
        'max_urls_per_request' => (int) env('SENDER_LIMIT_MAX_URLS_PER_REQUEST', 1000),

        // Seconds a single cron/worker invocation may run before it must exit.
        'max_worker_runtime_seconds' => (int) env('SENDER_LIMIT_MAX_WORKER_RUNTIME_SECONDS', 240),

        // Records claimed by one job processing pass.
        'max_job_batch_size' => (int) env('SENDER_LIMIT_MAX_JOB_BATCH_SIZE', 250),

        // Attempts allowed before a job is marked permanently failed.
        'max_job_attempts' => (int) env('SENDER_LIMIT_MAX_JOB_ATTEMPTS', 3),

        // Bytes of user storage available per account.
        'max_storage_bytes' => (int) env('SENDER_LIMIT_MAX_STORAGE_BYTES', 512 * 1024 * 1024),

        // API requests allowed per hour per key.
        'max_api_requests_per_hour' => (int) env('SENDER_LIMIT_MAX_API_REQUESTS_PER_HOUR', 1000),

        // Recipients allowed in a single campaign send.
        'max_campaign_recipients' => (int) env('SENDER_LIMIT_MAX_CAMPAIGN_RECIPIENTS', 100000),
    ],

    /*
    |---------------------------------------------------------------------------
    | Minimum host requirements
    |---------------------------------------------------------------------------
    |
    | Thresholds the capability inspector compares against. They are separate
    | from the limits above: these describe the machine, not the account plan.
    |
    */

    'requirements' => [
        'php_minimum' => '8.2.0',

        'extensions' => [
            'curl',
            'dom',
            'fileinfo',
            'json',
            'mbstring',
            'openssl',
            'pdo_mysql',
            'tokenizer',
            'xml',
            'zip',
        ],

        'memory_limit_bytes' => 256 * 1024 * 1024,

        'max_execution_time_seconds' => 60,

        'min_free_disk_bytes' => 512 * 1024 * 1024,

        // Capability reported when a required dependency is missing.
        'unavailable_status' => Capability::Unavailable->value,
    ],
];
