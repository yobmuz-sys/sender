<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use Stringable;

/**
 * A decrypted SMTP credential, held only for as long as it is needed.
 *
 * Wrapping the secret in its own type rather than returning a bare string is what
 * makes the storage rule enforceable rather than merely intended. The value is
 * encrypted on the way into the column and wrapped here on the way out, so:
 *
 *   - a raw query, a database dump or a replica never contains a plaintext
 *     mailbox password
 *   - `$account->toArray()` contains ciphertext, because the model hides the
 *     attribute and this object has no `__toString`
 *   - the only way to obtain the plaintext is to call {@see reveal()}, and that
 *     call appears in the diff
 *
 * The cost is that reading the value needs an explicit act. That is the intent: a
 * secret that can be read without noticing being read is a secret that ends up in
 * a log.
 *
 * Deliberately not `Stringable`. A `__toString` would make the secret printable
 * by accident — an exception message built from an object holding it, an
 * `dd()`, a `Str::dump` — which is precisely what this type exists to prevent.
 */
final class SmtpSecret
{
    public function __construct(private readonly ?string $plain) {}

    public static function empty(): self
    {
        return new self(null);
    }

    /**
     * The plaintext. Every call site is a place a reviewer must confirm.
     */
    public function reveal(): ?string
    {
        return $this->plain;
    }

    public function isPresent(): bool
    {
        return $this->plain !== null && $this->plain !== '';
    }
}
