<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Removes credential-shaped values from data before it is logged or reported.
 *
 * The platform will eventually handle SMTP passwords, API keys and campaign
 * secrets. Logging an exception context is the most common way those leak into
 * a log file, so redaction is applied centrally to every log channel rather
 * than relying on each call site to remember.
 */
final class SensitiveData
{
    /**
     * Keys whose values are never written to a log, matched case-insensitively
     * against the whole key name.
     */
    private const SENSITIVE_KEY_PATTERN = '/(pass(word|wd)?|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|credential|authorization|auth)/i';

    public const REDACTED = '[redacted]';

    /**
     * Recursively redact sensitive entries from an arbitrary payload.
     */
    public static function redact(mixed $data, int $depth = 0): mixed
    {
        // Guard against self-referencing structures and runaway payloads.
        if ($depth > 10) {
            return '[truncated]';
        }

        if (is_array($data)) {
            $result = [];

            foreach ($data as $key => $value) {
                $result[$key] = is_string($key) && self::isSensitiveKey($key)
                    ? self::REDACTED
                    : self::redact($value, $depth + 1);
            }

            return $result;
        }

        return $data;
    }

    public static function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match(self::SENSITIVE_KEY_PATTERN, $key);
    }

    /**
     * Redact credential-shaped substrings inside a free-text message.
     *
     * Key-based redaction is not sufficient for text. A failure message
     * routinely quotes the configuration that caused it — "authentication
     * rejected for ops with password=hunter2" — and a key named `error` or
     * `message` gives the scanner nothing to match. Anything persisted beyond a
     * log line must therefore be scrubbed on content as well as on key.
     *
     * Matches `key=value` and `key: value` where the key is credential-shaped,
     * leaving surrounding prose intact so the message stays useful.
     */
    public static function redactText(string $text): string
    {
        // The replacement is a literal, not a pattern, so it is inserted
        // verbatim; escaping it here would persist the backslashes.
        return (string) preg_replace(
            '/\b([A-Za-z0-9_.-]*(?:pass(?:word|wd)?|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|credential|authorization)[A-Za-z0-9_.-]*)(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;)\]}]+)/i',
            '$1$2'.self::REDACTED,
            $text,
        );
    }
}
