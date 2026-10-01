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
     * The credential-shaped words, kept as one fragment so the patterns below
     * cannot drift apart from each other.
     */
    private const CREDENTIAL_WORDS = 'pass(?:word|wd)?|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|credential|authorization';

    /**
     * Redact credential-shaped substrings inside a free-text message.
     *
     * Key-based redaction is not sufficient for text. A failure message
     * routinely quotes the configuration that caused it — "authentication
     * rejected for ops with password=hunter2" — and a key named `error` or
     * `message` gives the scanner nothing to match. Anything persisted beyond a
     * log line must therefore be scrubbed on content as well as on key.
     *
     * Several shapes are handled separately rather than in one pattern, because
     * the common ones each defeat a single regex in a different way:
     *
     *   - `password=hunter2`, `api_key: abc` — the ordinary case;
     *   - `scheme://user:pass@host` — a DSN, where the password has no key at
     *     all and appears very often in connection errors;
     *   - `{"password":"hunter2"}` — JSON, where the key is quoted, so a
     *     key-based pattern cannot see it;
     *   - `Authorization: Bearer <jwt>` — a single token whose value contains
     *     a space, so a `\S+` value stops at the wrong place and leaves the
     *     secret in the clear while appearing to have redacted it. That partial
     *     case is worse than no match at all, so it is handled on its own.
     *
     * Values are replaced, never just their first token, and the surrounding
     * prose is preserved so the message stays useful.
     *
     * This is a best-effort scrub of shaped credentials, not proof of
     * sanitisation: a secret embedded in plain prose ("my password is
     * hunter2") has no distinguishing shape and is not detected.
     */
    public static function redactText(string $text): string
    {
        $words = self::CREDENTIAL_WORDS;
        $redacted = self::REDACTED;

        // 1. Credentials in a URI. The password sits between the colon and the
        //    at-sign, with no key name to match on.
        $text = (string) preg_replace(
            '/\b([a-z][a-z0-9+.-]*:\/\/[^\s:@\/]*:)([^\s@\/]*)@/i',
            '$1'.$redacted.'@',
            $text,
        );

        // 2. An auth scheme followed by a token. The value contains a space, so
        //    it must be matched as a scheme plus token rather than as a
        //    whitespace-delimited value, which would stop after the scheme and
        //    leave the secret in the clear while appearing to have redacted it.
        $text = (string) preg_replace(
            '/\b(Bearer|Basic|Digest|Negotiate)\s+[A-Za-z0-9._\-\/+=]{4,}/i',
            '$1 '.$redacted,
            $text,
        );

        // 3. JSON, where the key carries its own quotes and so is invisible to
        //    an unquoted key pattern.
        $text = (string) preg_replace(
            '/("([^"]*(?:'.$words.')[^"]*)"\s*:\s*)"(?:\\\\.|[^"\\\\])*"/i',
            '$1"'.$redacted.'"',
            $text,
        );

        // 4. The ordinary `key=value` / `key: value` case. Both quote styles
        //    are consumed whole so a secret containing a space cannot survive.
        //    The lookaheads keep this rule away from values rule 2 already
        //    handled, so a redacted token is not rendered twice.
        return (string) preg_replace(
            '/\b([A-Za-z0-9_.-]*(?:'.$words.')[A-Za-z0-9_.-]*)(\s*[=:]\s*)'
                .'(?!(?:Bearer|Basic|Digest|Negotiate)\b)(?!\x5Bredacted\x5D)'
                .'("[^"]*"|\'[^\']*\'|[^\s,;)\]}]+)/i',
            '$1$2'.$redacted,
            $text,
        );
    }
}
