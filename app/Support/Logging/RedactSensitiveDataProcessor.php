<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Support\SensitiveData;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Applies {@see SensitiveData} redaction to every outgoing log record.
 *
 * Registered in config/logging.php on each leaf channel, which is where the
 * stack channel collects processors from.
 */
final class RedactSensitiveDataProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: SensitiveData::redact($record->context),
            extra: SensitiveData::redact($record->extra),
        );
    }
}
