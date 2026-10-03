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

        'url_fetch' => [
            // How long a recorded verification is treated as current.
            'fresh_after_seconds' => (int) env('SENDER_URL_FETCH_FRESH_AFTER_SECONDS', 86400),

            // Where `sender:verify-url` fetches when given no --url. A public
            // text page with no query string, so nothing secret is ever
            // requested or recorded.
            'verification_url' => (string) env('SENDER_URL_VERIFICATION_URL', 'https://example.com/'),
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
        // Recipient probing also depends on validation.smtp_probing below. This
        // flag is the operator's switch; that one is the deployment's ceiling.
        'smtp_validation' => true,
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
    | Recipient validation
    |---------------------------------------------------------------------------
    |
    | The platform's accuracy objective is narrow and stated here rather than
    | implied: maximise the precision of CONFIRMED_INVALID, and never classify
    | uncertain evidence as inactive. A timeout, a 4xx, a 252, a policy rejection,
    | a catch-all acceptance and an unreachable resolver are all UNKNOWN, and
    | UNKNOWN is excluded from sending by default. None of the switches below can
    | turn an ambiguous result into an invalid one — they decide how much work is
    | attempted and how long evidence stays current.
    |
    | `smtp_probing` is off by default and should stay off on shared hosting.
    | Outbound port 25 is blocked by most cPanel hosts precisely because
    | unsolicited outbound mail from a shared account gets the host suspended. A
    | refused connection yields UNKNOWN, never a dead address, so leaving it on
    | wastes worker time without improving any classification.
    |
    */
    'validation' => [
        /*
        | Whether to open an SMTP conversation with a recipient's own mail server.
        |
        | Off means the pipeline stops after the mail-route check and reports
        | VERIFICATION_BLOCKED. That is a truthful answer — this host cannot
        | confirm individual mailboxes — rather than a failed one.
        */
        'smtp_probing' => (bool) env('SENDER_VALIDATION_SMTP_PROBING', false),

        // Seconds allowed for one recipient check, connection through RCPT TO.
        // Far below the worker runtime, for the same reason the URL timeouts are.
        'smtp_timeout_seconds' => (int) env('SENDER_VALIDATION_SMTP_TIMEOUT_SECONDS', 5),

        /*
        | How long a domain's mail route is reused, and how long a catch-all
        | verdict is reused.
        |
        | Two windows rather than one because the two answers have different
        | lifetimes and different consequences when wrong. A route changes only
        | when DNS changes, so hours are safe. A catch-all verdict is negative
        | information — it says acceptance proves nothing — and a stale one either
        | discards a usable audience or keeps one that no longer exists, so it is
        | the shorter of the two even though the positive case is the expensive
        | one to rediscover.
        */
        'domain_cache_ttl_seconds' => (int) env('SENDER_VALIDATION_DOMAIN_CACHE_TTL_SECONDS', 21600),
        'catch_all_cache_ttl_seconds' => (int) env('SENDER_VALIDATION_CATCH_ALL_CACHE_TTL_SECONDS', 259200),

        /*
        | How long a mailbox result is reused.
        |
        | A mailbox that accepted an address yesterday says nothing about tomorrow:
        | the server can start refusing it at any moment, and a stale "active" is
        | what turns a healthy list into a burst of bounces. A week is therefore
        | the ceiling rather than the target, and re-running the same extraction
        | tomorrow re-probes rather than trusting a stored acceptance.
        */
        'mailbox_cache_ttl_seconds' => (int) env('SENDER_VALIDATION_MAILBOX_CACHE_TTL_SECONDS', 604800),

        /*
        | How long a confirmed-invalid result is reused.
        |
        | Longer, and deliberately asymmetric with the above. A mailbox that does
        | not exist is a stable fact, and re-probing it costs the receiving server
        | work in exchange for no new information. A month is not "permanent" —
        // the contact keeps an expiry and can be revalidated by an operator.
        */
        'invalid_cache_ttl_seconds' => (int) env('SENDER_VALIDATION_INVALID_CACHE_TTL_SECONDS', 2592000),

        /*
        | Rows of a validation pass committed per batch, and the ceiling on one
        | pass.
        |
        | Batched so a worker interruption leaves durable progress rather than a
        | half-written run. The pass ceiling is what makes a validation job
        | resumable rather than unbounded: past it the job re-queues itself, so
        | a 10,000-address list is ten bounded passes instead of one that
        | overruns the worker and is killed mid-way.
        */
        'batch_size' => (int) env('SENDER_VALIDATION_BATCH_SIZE', 50),
        'max_per_pass' => (int) env('SENDER_VALIDATION_MAX_PER_PASS', 150),
    ],

    /*
    |---------------------------------------------------------------------------
    | Per-user SMTP transports
    |---------------------------------------------------------------------------
    |
    | Configuration for tenant-owned and operator-assigned SMTP accounts. The
    | platform's own transactional transport is NOT configured here: it stays in
    | `config/mail.php` and the environment, so a customer's sending transport
    | can never become the mailer that resets passwords.
    |
    */
    'smtp' => [
        // Seconds a transport may take to connect and complete a handshake.
        // Kept far below the worker runtime for the same reason the URL
        // timeouts are: a transport that could occupy the whole worker budget
        // would break the queue's reservation invariant.
        'timeout_seconds' => (int) env('SENDER_SMTP_TIMEOUT_SECONDS', 10),

        // How long an account verification is treated as current. Past this the
        // account reports STALE and is not eligible to send, because a server
        // can change underneath a stored credential at any time.
        'verification_fresh_after_seconds' => (int) env('SENDER_SMTP_ACCOUNT_FRESH_AFTER_SECONDS', 86400),

        /*
        | Verification is rate limited, and the two actions have different limits
        | because they are not equally expensive: a connection probe opens a
        | socket and discards it, while a test message puts mail on the wire and
        | consumes the customer's provider quota. Without a bound, the second
        | would be a mail-sending endpoint reachable from a browser loop, and the
        | customer would be the one whose provider rate-limited them.
        */
        'verification_throttle' => [
            'connection' => (string) env('SENDER_SMTP_VERIFY_THROTTLE', '10,1'),
            'test_message' => (string) env('SENDER_SMTP_SEND_TEST_THROTTLE', '5,60'),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Campaign sending policy
    |---------------------------------------------------------------------------
    |
    | Stage 5C implements this; Stage 5A only fixes the defaults and the shape.
    |
    | The interval below is *this application's* conservative safety default for
    | an ordinary shared-hosting transport. It is not a Gmail requirement, not a
    | Yahoo requirement, and not derived from any provider's published figure —
    | there is no universal "best interval", because provider limits and
    | reputation vary. It exists so that a misconfigured transport cannot dump a
    | whole audience into a provider at once, and it must never be used as a way
    | of presenting a slowly ramped volume as something it is not.
    |
    | A provider's own limit always wins: when it is stricter than the default,
    | the provider's applies. Slowing is safe; exceeding a limit is not.
    |
    */
    'sending' => [
        'minimum_interval_seconds' => (int) env('SENDER_SENDING_MINIMUM_INTERVAL_SECONDS', 30),

        // Hard ceiling per invocation, so one campaign cannot hold the worker
        // open indefinitely. A worker processes many campaigns; this bounds one.
        'max_per_run' => (int) env('SENDER_SENDING_MAX_PER_RUN', 100),

        /*
        | Observed complaint rate thresholds, as WARN and BLOCK-READINESS bands.
        |
        | These are application policy readings, not a spam score. Nothing here
        | computes a universal reputation figure: complaint rates are observed
        | from provider feedback loops, which do not exist yet, so there is
        | deliberately no "spam score" to misread these as one.
        |
        | Once bounce and complaint processing lands, a rate above the warning
        | threshold is surfaced as a warning and above the block threshold it
        | pauses the affected traffic. Until then they are inert.
        */
        'complaint_rate' => [
            'warn_above' => (float) env('SENDER_COMPLAINT_WARN_ABOVE', 0.001),
            'block_above' => (float) env('SENDER_COMPLAINT_BLOCK_ABOVE', 0.003),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Campaign sending
    |---------------------------------------------------------------------------
    |
    | Ceilings for the sending engine. These are limits on *work per invocation*,
    | not targets: nothing here decides how much mail a campaign sends, only how
    | much one PHP process may attempt before it must exit and let cron come back.
    |
    | The pacing a customer configures lives on the campaign itself and is
    | reconciled against `sending.minimum_interval_seconds` at send time, so
    | neither file is authoritative on its own.
    |
    */
    'campaigns' => [

        // Attempts allowed for one recipient, including the first. Bounded so a
        // dead address cannot occupy the worker indefinitely; the delay between
        // them grows exponentially and is capped below.
        'max_attempts' => (int) env('SENDER_CAMPAIGN_MAX_ATTEMPTS', 4),

        // First backoff after a temporary failure, and its ceiling. Growth is
        // `base * 2^(attempt - 1)` because a provider issuing a 4xx is usually
        // saying the volume is too high, and retrying at the same pace is how a
        // throttle becomes a block.
        'retry_base_seconds' => (int) env('SENDER_CAMPAIGN_RETRY_BASE_SECONDS', 300),
        'retry_max_seconds' => (int) env('SENDER_CAMPAIGN_RETRY_MAX_SECONDS', 3600),

        // Longest a worker may hold a campaign or recipient claim before another
        // worker assumes it was abandoned. Comfortably longer than one submission,
        // which is bounded by the transport's own timeout.
        'claim_stale_after_seconds' => (int) env('SENDER_CAMPAIGN_CLAIM_STALE_SECONDS', 900),
    ],

    /*
    |---------------------------------------------------------------------------
    | Outbound URL fetching
    |---------------------------------------------------------------------------
    |
    | Network policy for the URL extraction workload. These are security
    | boundaries as much as they are limits: each one removes a way to turn the
    | platform into something that reaches places it should not.
    |
    | The timeouts are deliberately far below the worker runtime. A fetch that
    | could occupy the whole worker budget would make the queue's reservation
    | invariant impossible to keep, so the correct response to slow hosts is a
    | shorter HTTP timeout â€” never a longer worker.
    */
    'url_fetch' => [
        // Longest URL accepted, checked before parsing so a hostile string
        // cannot make the parser work hard first.
        'max_url_length' => (int) env('SENDER_URL_MAX_LENGTH', 2048),

        // Connection establishment. Short, because a host that cannot be
        // reached on the port is not going to become reachable by waiting.
        'connect_timeout_seconds' => (int) env('SENDER_URL_CONNECT_TIMEOUT_SECONDS', 5),

        // Whole request, including reading the body.
        'request_timeout_seconds' => (int) env('SENDER_URL_REQUEST_TIMEOUT_SECONDS', 10),

        // Redirect hops followed. Each hop is re-validated from scratch, so
        // this is a limit on work rather than a security control.
        'max_redirects' => (int) env('SENDER_URL_MAX_REDIRECTS', 3),

        // Bytes read from a response body before the fetch is abandoned. The
        // body is streamed to a temporary file, so this bounds disk rather than
        // memory.
        'max_response_bytes' => (int) env('SENDER_URL_MAX_RESPONSE_BYTES', 2 * 1024 * 1024),

        // Content types the extraction will read. Everything else is refused
        // without being read: this workload extracts addresses from text and is
        // not a general file downloader.
        'allowed_content_types' => [
            'text/html',
            'application/xhtml+xml',
            'text/plain',
        ],

        // Ports that may be connected to. Restricting to the two web ports
        // prevents the platform being used to probe internal services on
        // arbitrary ports.
        'allowed_ports' => [80, 443],
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
