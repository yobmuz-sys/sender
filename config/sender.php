<?php

declare(strict_types=1);

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
    | Capability policy
    |---------------------------------------------------------------------------
    |
    | Capability subjects are reported as UNKNOWN until something actually
    | establishes their state. UNKNOWN is not a failure by itself; it becomes
    | one only for a subject listed as required here.
    |
    | Empty at this stage: no operational feature depends on these capabilities
    | yet, so an unverified capability must not fail the diagnostics command.
    |
    */

    'capabilities' => [

        'required' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('SENDER_REQUIRED_CAPABILITIES', '')))
        )),

        /*
        | A cron heartbeat older than this is treated as stale: cron has been
        | observed working before but is not demonstrably working now.
        */
        'cron' => [
            'stale_after_seconds' => (int) env('SENDER_CRON_STALE_AFTER_SECONDS', 900),
        ],

        /*
        | The database queue treats a job as abandoned once its reservation is
        | older than retry_after, and will hand it to another worker. That makes
        | retry_after a correctness constraint rather than a tuning knob: it must
        | exceed the longest a worker is permitted to run, or the same job can
        | execute twice concurrently.
        |
        | This margin is added to max_worker_runtime_seconds to produce the
        | minimum acceptable retry_after.
        */
        'queue' => [
            'reservation_margin_seconds' => (int) env('SENDER_QUEUE_RESERVATION_MARGIN_SECONDS', 60),

            /*
            | How many jobs one `sender:work` invocation will process.
            |
            | A cron-invoked worker must exit, so it cannot simply drain the
            | queue. Capping the batch bounds the wall-clock time regardless of
            | how deep the backlog is, which is what makes the command safe to
            | point a shared-hosting cron entry at.
            */
            'max_jobs_per_run' => (int) env('SENDER_QUEUE_MAX_JOBS_PER_RUN', 25),
        ],

        /*
        | SMTP is established by `sender:verify-smtp`, not inferred from
        | configuration. Credentials being present is not evidence that they
        | work, so the capability stays UNKNOWN until somebody verifies.
        */
        'smtp' => [
            // How long a recorded verification is treated as current.
            'fresh_after_seconds' => (int) env('SENDER_SMTP_FRESH_AFTER_SECONDS', 86400),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Subsystem flags
    |---------------------------------------------------------------------------
    |
    | One consolidated registry for operator control: safe mode, emergency
    | disablement and subsystem toggles are the same mechanism, not three.
    |
    | Only subsystems that exist today are listed. Flags are persisted so an
    | emergency stop survives a cache clear.
    |
    */

    'subsystems' => [
        // key => default enabled
        'cron' => true,
        'url_fetch' => true,
        'smtp' => true,
    ],

    /*
    |---------------------------------------------------------------------------
    | Pasted-text extraction
    |---------------------------------------------------------------------------
    |
    | How the worker reads a stored extraction. Processing is chunked rather
    | than whole-string so peak memory stays flat as the input ceiling rises,
    | and results are written in batches rather than accumulated.
    |
    | The input ceiling itself is `deployment_limits.max_text_input_bytes`. It
    | is deliberately not restated here: two sources of truth for one limit is
    | how a limit stops meaning anything.
    */
    'extraction' => [
        // Bytes read per pass. Sized well above one email address so the
        // overlap between chunks can never truncate a real candidate.
        'chunk_bytes' => (int) env('SENDER_EXTRACTION_CHUNK_BYTES', 64 * 1024),

        // Rows written per batch. Uses the shared deployment limit rather than
        // a value of its own.
        'batch_size' => (int) env('SENDER_DEPLOYMENT_LIMIT_MAX_JOB_BATCH_SIZE', 250),
    ],

    /*
    |---------------------------------------------------------------------------
    | Host requirements
    |---------------------------------------------------------------------------
    |
    | Prerequisite capabilities of the host itself. These are application
    | constants describing what the platform needs, not operator-tunable
    | ceilings: loosening them does not make a weak host strong.
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
    ],

    /*
    |---------------------------------------------------------------------------
    | Deployment limits
    |---------------------------------------------------------------------------
    |
    | Ceilings imposed by this installation, as distinct from:
    |
    |   requirements       what the host must provide
    |   deployment_limits  what this installation permits
    |   entitlements       what an account is granted   (built in a later stage)
    |   usage              what an account has consumed (built in a later stage)
    |
    | Stages 3-5 consume these limits. They are declared now so that no
    | consumer has to invent its own threshold.
    |
    */

    'deployment_limits' => [

        // Bytes accepted for a single uploaded file.
        'max_upload_bytes' => (int) env('SENDER_DEPLOYMENT_LIMIT_MAX_UPLOAD_BYTES', 10 * 1024 * 1024),

        /*
        | Bytes accepted for pasted text (page source, article body, list).
        |
        | Kept small deliberately. This is a shared-hosting installation with a
        | 240s worker budget, and a multi-megabyte POST has to be received, held
        | in memory, parsed and written to the database before anything else can
        | happen. Pasted content for a single list or article is normally a few
        | hundred kilobytes, so the ceiling costs nothing in practice.
        */
        'max_text_input_bytes' => (int) env('SENDER_DEPLOYMENT_LIMIT_MAX_TEXT_INPUT_BYTES', 1024 * 1024),

        /*
        | Number of seed URLs accepted by one extraction request.
        |
        | This is deliberately coupled to `max_worker_runtime_seconds`. A request
        | that seeds 1,000 URLs cannot finish inside a 240s budget at any
        | plausible per-URL cost, so the old default guaranteed a timeout and a
        | partial result rather than an honest failure. At a pessimistic two
        | seconds per fetch, 100 URLs fits with headroom to spare.
        |
        | Raise this only together with the worker runtime, and only if the host
        | can actually run a request that long.
        */
        'max_urls_per_request' => (int) env('SENDER_DEPLOYMENT_LIMIT_MAX_URLS_PER_REQUEST', 100),

        // Seconds a single cron/worker invocation may run before it must exit.
        'max_worker_runtime_seconds' => (int) env('SENDER_DEPLOYMENT_LIMIT_MAX_WORKER_RUNTIME_SECONDS', 240),

        // Records claimed by one job processing pass.
        'max_job_batch_size' => (int) env('SENDER_DEPLOYMENT_LIMIT_MAX_JOB_BATCH_SIZE', 250),

        // Attempts allowed before a job is marked permanently failed.
        'max_job_attempts' => (int) env('SENDER_DEPLOYMENT_LIMIT_MAX_JOB_ATTEMPTS', 3),

        // Bytes of user storage available per account.
        'max_storage_bytes' => (int) env('SENDER_DEPLOYMENT_LIMIT_MAX_STORAGE_BYTES', 512 * 1024 * 1024),

        // API requests allowed per hour per key.
        'max_api_requests_per_hour' => (int) env('SENDER_DEPLOYMENT_LIMIT_MAX_API_REQUESTS_PER_HOUR', 1000),

        // Recipients allowed in a single campaign send.
        'max_campaign_recipients' => (int) env('SENDER_DEPLOYMENT_LIMIT_MAX_CAMPAIGN_RECIPIENTS', 100000),
    ],
];
