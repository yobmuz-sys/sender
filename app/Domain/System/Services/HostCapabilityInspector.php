<?php

declare(strict_types=1);

namespace App\Domain\System\Services;

use App\Domain\System\CapabilityCheck;
use App\Domain\System\Contracts\HostInspector;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Enums\DeploymentLimit;
use App\Domain\System\HostCapabilityReport;
use App\Domain\System\HostEnvironment;
use App\Support\Bytes;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Inspects whether the current host can run the Sender platform.
 *
 * The inspector only reports what it can actually measure. Checks that need an
 * external dependency the platform does not require (DNS resolution, outbound
 * SMTP reachability) are deliberately absent until the feature that depends on
 * them is implemented, so the report never claims a capability is untested.
 */
final class HostCapabilityInspector implements HostInspector
{
    /**
     * Storage and cache drivers that assume a service outside the shared
     * hosting stack. Selecting one turns a working install into a broken one,
     * so it is surfaced as a configuration problem rather than a host problem.
     *
     * @var list<string>
     */
    /**
     * Mailers that discard messages. Correct in development, wrong anywhere
     * a user is waiting for a password reset link.
     *
     * @var list<string>
     */
    private const NON_DELIVERING_MAILERS = ['log', 'null', 'array', 'failover'];

    private const EXTERNAL_DRIVERS = ['redis', 'memcached', 'dynamodb', 'apc'];

    private readonly HostEnvironment $environment;

    public function __construct(?HostEnvironment $environment = null)
    {
        $this->environment = $environment ?? HostEnvironment::current();
    }

    public function inspect(): HostCapabilityReport
    {
        $checks = [
            ...$this->runtimeChecks(),
            ...$this->databaseChecks(),
            ...$this->filesystemChecks(),
            ...$this->driverChecks(),
            ...$this->securityChecks(),
        ];

        return HostCapabilityReport::fromChecks($checks);
    }

    /**
     * Which capability an extension is evidence for.
     *
     * Deliberately empty. A loaded extension is a prerequisite, not proof:
     * reporting SMTP as READY because OpenSSL is present would claim a
     * capability nobody has tested, which is precisely how an unverified
     * dependency becomes an assumed one.
     *
     * A subject is assigned only when the check measures that capability's
     * actual prerequisites. URL fetching and SMTP therefore report UNKNOWN
     * until the code that performs them exists to be measured against.
     *
     * @var array<string, CapabilitySubject>
     */
    private const EXTENSION_SUBJECTS = [];

    private function extensionSubject(string $extension): ?CapabilitySubject
    {
        return self::EXTENSION_SUBJECTS[$extension] ?? null;
    }

    /**
     * @return list<CapabilityCheck>
     */
    private function runtimeChecks(): array
    {
        $checks = [];

        $minimum = (string) config('sender.requirements.php_minimum', '8.2.0');

        $checks[] = version_compare($this->environment->phpVersion, $minimum, '>=')
            ? CapabilityCheck::ready('PHP runtime', $this->environment->phpVersion." (minimum {$minimum})")
            : CapabilityCheck::unavailable(
                'PHP runtime',
                $this->environment->phpVersion." is below the required {$minimum}",
                ["Select a PHP version of at least {$minimum} in cPanel -> MultiPHP Manager."],
            );

        foreach ((array) config('sender.requirements.extensions', []) as $extension) {
            $subject = $this->extensionSubject((string) $extension);

            $checks[] = extension_loaded((string) $extension)
                ? CapabilityCheck::ready('ext: '.$extension, subject: $subject)
                : CapabilityCheck::unavailable(
                    'ext: '.$extension,
                    'not loaded',
                    ["Enable the '{$extension}' extension in cPanel -> Select PHP Version."],
                    $subject,
                );
        }

        $memoryLimit = Bytes::fromIni($this->environment->memoryLimit);
        $requiredMemory = (int) config('sender.requirements.memory_limit_bytes', 268435456);

        $checks[] = $memoryLimit < 0
            ? CapabilityCheck::degraded('memory_limit', 'unlimited', ['Cap memory_limit so runaway jobs can be interrupted.'])
            : $this->compare(
                'memory_limit',
                $memoryLimit,
                $requiredMemory,
                '256M',
                ['Raise memory_limit in cPanel -> Select PHP Version -> Options.'],
            );

        $executionTime = $this->environment->maxExecutionTimeSeconds;
        $requiredExecutionTime = (int) config('sender.requirements.max_execution_time_seconds', 60);

        // A value of 0 means "unlimited", which is the normal value under the
        // CLI SAPI and is expected for cron-driven processing.
        $checks[] = $executionTime === 0
            ? CapabilityCheck::ready('max_execution_time', 'unlimited')
            : $this->compare(
                'max_execution_time',
                $executionTime,
                $requiredExecutionTime,
                '60s',
                ['Raise max_execution_time in cPanel -> Select PHP Version -> Options.'],
                static fn (int $seconds): string => $seconds.'s',
            );

        $requiredUpload = DeploymentLimit::MaxUploadBytes->value();

        $checks[] = $this->compare(
            'upload_max_filesize',
            $this->environment->maxUploadBytes,
            $requiredUpload,
            DeploymentLimit::MaxUploadBytes->humanValue(),
            ['Raise upload_max_filesize/post_max_size, or lower the SENDER_DEPLOYMENT_LIMIT_MAX_UPLOAD_BYTES limit.'],
        );

        $checks[] = $this->compare(
            'post_max_size',
            $this->environment->maxPostBytes,
            $requiredUpload,
            DeploymentLimit::MaxUploadBytes->humanValue(),
            ['post_max_size must be at least as large as upload_max_filesize.'],
        );

        return $checks;
    }

    /**
     * @return list<CapabilityCheck>
     */
    private function databaseChecks(): array
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();

            return [
                CapabilityCheck::ready('database', sprintf(
                    '%s %s',
                    $connection->getDriverName(),
                    $this->databaseVersion($connection),
                )),
            ];
        } catch (Throwable) {
            return [
                CapabilityCheck::unavailable(
                    'database',
                    'connection failed',
                    [
                        'Verify DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD.',
                        'Grant the database user access to the application database in cPanel -> MySQL Databases.',
                    ],
                ),
            ];
        }
    }

    /**
     * Read the server version, tolerating drivers whose dialect differs.
     *
     * The version is informational only, so an unsupported dialect degrades to
     * "unknown" instead of reporting the database as unavailable.
     */
    private function databaseVersion(Connection $connection): string
    {
        $queries = [
            'mysql' => 'select version() as version',
            'mariadb' => 'select version() as version',
            'pgsql' => 'show server_version',
            'sqlsrv' => 'select @@version as version',
            'sqlite' => 'select sqlite_version() as version',
        ];

        try {
            $query = $queries[$connection->getDriverName()] ?? 'select 1 as version';

            return trim((string) $connection->selectOne($query)?->version);
        } catch (Throwable) {
            return 'version unknown';
        }
    }

    /**
     * @return list<CapabilityCheck>
     */
    private function filesystemChecks(): array
    {
        $checks = [];

        $paths = [
            'storage' => storage_path(),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        foreach ($paths as $label => $path) {
            $checks[] = is_dir($path) && is_writable($path)
                ? CapabilityCheck::ready("writable: {$label}", subject: CapabilitySubject::Storage)
                : CapabilityCheck::unavailable(
                    "writable: {$label}",
                    is_dir($path) ? 'directory is not writable' : 'directory is missing',
                    ["chmod 775 {$label} and confirm the owner is the PHP user."],
                    CapabilitySubject::Storage,
                );
        }

        $minimumFree = (int) config('sender.requirements.min_free_disk_bytes', 536870912);
        $free = @disk_free_space(base_path());

        $checks[] = $free === false
            ? CapabilityCheck::degraded('free disk space', 'unknown', ['Ask the host for the disk quota of the account.'], CapabilitySubject::Storage)
            : $this->compare(
                'free disk space',
                (int) $free,
                $minimumFree,
                '512M',
                ['Extraction and campaign storage share this quota, so 512M is the practical floor.'],
                subject: CapabilitySubject::Storage,
            );

        return $checks;
    }

    /**
     * @return list<CapabilityCheck>
     */
    private function driverChecks(): array
    {
        $checks = [];

        $drivers = [
            'session' => (string) config('session.driver'),
            'queue' => (string) config('queue.default'),
            'cache' => (string) config('cache.default'),
        ];

        foreach ($drivers as $label => $driver) {
            // Sessions and cache run inside a request; only the queue backs
            // asynchronous processing, so only it informs the queue capability.
            $subject = $label === 'queue' ? CapabilitySubject::Queue : null;

            $checks[] = in_array($driver, self::EXTERNAL_DRIVERS, true)
                ? CapabilityCheck::unavailable(
                    "{$label} driver",
                    $driver,
                    ["Sender requires no external services. Use the 'database' driver for {$label}."],
                    $subject,
                )
                : CapabilityCheck::ready("{$label} driver", $driver, subject: $subject);
        }

        // The database queue decides a job was abandoned by comparing the age of
        // its reservation against retry_after, and will hand it to a second
        // worker. A reservation window shorter than the permitted runtime is
        // therefore not a tuning problem: it permits the same job to execute
        // twice at once. Reported as UNAVAILABLE because it is a definite
        // misconfiguration rather than reduced headroom.
        if ((string) config('queue.default') === 'database') {
            $checks[] = $this->queueReservationCheck();
        }

        $checks[] = $this->mailerCheck();

        $checks[] = $this->recipientValidationCheck();

        return $checks;
    }

    /**
     * Report whether recipient validation is switched on, and why it might be.
     *
     * Stage 5B's validation pipeline speaks SMTP to other people's mail servers
     * on port 25, which shared hosting very often blocks. That is not visible
     * anywhere else: the queue is Ready, the mailer is configured, the URL fetcher
     * works, and every task still comes back `UNKNOWN` with the reason
     * `verification_blocked`. An operator who cannot see the switch will read that
     * as "the addresses are fine, the platform is not trying hard enough".
     *
     * Deliberately does not open a socket. The diagnose command runs on a timer
     * in front of an operator, and the platform's whole argument for leaving
     * probing off by default is that it should not be doing unsolicited SMTP to
     * third parties. It reports the configuration and tells the operator how to
     * find out what the host permits.
     *
     * Untagged, for the same reason as the transactional mailer check: a
     * configuration switch is not a measurement, and the SMTP capability remains
     * established only by `sender:verify-smtp`.
     */
    private function recipientValidationCheck(): CapabilityCheck
    {
        $enabled = (bool) config('sender.validation.smtp_probing', false);

        if (! $enabled) {
            return CapabilityCheck::degraded(
                'recipient validation probing',
                'off',
                [
                    'Addresses will be reported as UNKNOWN with the reason "verification blocked".',
                    'That is the honest answer: the platform is not allowed to ask a mail server whether a mailbox exists.',
                    'It never reports them as inactive, and it never reports them as active either.',
                    'To turn it on, ask your host whether outbound port 25 is permitted, then set SENDER_VALIDATION_SMTP_PROBING=true.',
                ],
            );
        }

        return CapabilityCheck::ready(
            'recipient validation probing',
            sprintf('on (%.1fs per server)', (float) config('sender.validation.smtp_timeout_seconds', 5)),
        );
    }

    /**
     * Report which transport transactional mail is configured to use.
     *
     * Deliberately untagged, so it does not become the SMTP capability. A
     * non-delivering mailer is a configuration state, not a measurement of
     * whether SMTP works, and the capability is established only by running
     * `sender:verify-smtp`. Reported as DEGRADED rather than UNAVAILABLE because
     * the `log` mailer is correct in development, and a check that failed every
     * developer machine and every test run would be ignored everywhere.
     *
     * It is still worth surfacing loudly: password reset silently does nothing
     * under this mailer, which is exactly the kind of failure that reaches
     * production unnoticed.
     */
    private function mailerCheck(): CapabilityCheck
    {
        $mailer = (string) config('mail.default');

        return in_array($mailer, self::NON_DELIVERING_MAILERS, true)
            ? CapabilityCheck::degraded(
                'transactional mailer',
                $mailer,
                [
                    sprintf("Transactional mail is configured to use the '%s' mailer, which discards messages.", $mailer),
                    'Password reset and any other notification will appear to succeed but reach nobody.',
                    'Set MAIL_MAILER to smtp and configure MAIL_HOST, MAIL_PORT and credentials.',
                ],
            )
            : CapabilityCheck::ready('transactional mailer', $mailer);
    }

    /**
     * Verify the queue reservation window cannot undercut the worker runtime.
     */
    private function queueReservationCheck(): CapabilityCheck
    {
        $name = 'queue reservation window';

        $runtime = DeploymentLimit::MaxWorkerRuntimeSeconds->value();
        $margin = (int) config('sender.capabilities.queue.reservation_margin_seconds', 60);
        $required = $runtime + $margin;
        $configured = (int) config('queue.connections.database.retry_after', 0);

        if ($configured >= $required) {
            return CapabilityCheck::ready(
                $name,
                sprintf('%ds (worker runtime %ds + %ds margin)', $configured, $runtime, $margin),
                subject: CapabilitySubject::Queue,
            );
        }

        return CapabilityCheck::unavailable(
            $name,
            sprintf('%ds is shorter than the %ds a worker may run for', $configured, $required),
            [
                sprintf(
                    'Set DB_QUEUE_RETRY_AFTER to at least %d (worker runtime %ds plus a %ds margin).',
                    $required,
                    $runtime,
                    $margin,
                ),
                'A reservation window shorter than the worker runtime lets a second worker pick up a job that is still running.',
                sprintf(
                    'Alternatively lower SENDER_DEPLOYMENT_LIMIT_MAX_WORKER_RUNTIME_SECONDS to %d.',
                    max(0, $configured - $margin),
                ),
            ],
            CapabilitySubject::Queue,
        );
    }

    /**
     * @return list<CapabilityCheck>
     */
    private function securityChecks(): array
    {
        $checks = [];

        $checks[] = (string) config('app.key') !== ''
            ? CapabilityCheck::ready('application key')
            : CapabilityCheck::unavailable(
                'application key',
                'APP_KEY is empty',
                ['Run: php artisan key:generate'],
            );

        $isProduction = config('app.env') === 'production';

        $checks[] = ! $isProduction || config('app.debug') === false
            ? CapabilityCheck::ready('debug mode off')
            : CapabilityCheck::unavailable(
                'debug mode off',
                'APP_DEBUG is enabled in production',
                ['Set APP_DEBUG=false. Debug output can leak credentials and stack traces.'],
            );

        return $checks;
    }

    /**
     * Compare a measured value against a configured minimum.
     *
     * A value below the minimum is DEGRADED rather than UNAVAILABLE: the host
     * still works, it just works with less headroom, and the platform is built
     * to stay inside that headroom through the configured limits.
     *
     * @param  list<string>  $remedies
     * @param  (callable(int): string)|null  $format
     * @param  CapabilitySubject|null  $subject  The capability this check is evidence for.
     */
    private function compare(
        string $name,
        int $measured,
        int $required,
        string $humanRequired,
        array $remedies = [],
        ?callable $format = null,
        ?CapabilitySubject $subject = null,
    ): CapabilityCheck {
        $format ??= static fn (int $value): string => Bytes::humanize($value);

        if ($measured >= $required) {
            return CapabilityCheck::ready($name, $format($measured), subject: $subject);
        }

        return CapabilityCheck::degraded(
            $name,
            sprintf('%s (needs %s)', $format($measured), $humanRequired),
            $remedies !== [] ? $remedies : ["Raise {$name} to at least {$humanRequired} in cPanel -> Select PHP Version -> Options."],
            $subject,
        );
    }
}
