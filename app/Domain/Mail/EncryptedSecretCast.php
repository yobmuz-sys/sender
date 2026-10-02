<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

/**
 * Encrypts an SMTP credential on the way into the column and decrypts it on the
 * way out, as an {@see SmtpSecret}.
 *
 * Applied as an Eloquent cast rather than called from a service, so the
 * protection cannot be bypassed by writing to the model a different way — a
 * `forceFill`, a factory, a future admin action all go through the same two
 * methods.
 *
 * @implements CastsAttributes<SmtpSecret|null, string|SmtpSecret|null>
 */
final class EncryptedSecretCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): SmtpSecret
    {
        if ($value === null || $value === '') {
            return SmtpSecret::empty();
        }

        try {
            return new SmtpSecret(decrypt((string) $value));
        } catch (DecryptException) {
            // The application key changed, or the row predates this cast.
            //
            // Reporting an absent secret is the safe reading. The alternative —
            // surfacing the failure — would mean the account appears to have a
            // password it cannot produce, and the first send fails against the
            // server for a reason that has nothing to do with the server.
            return SmtpSecret::empty();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // A wrapper is stored by revealing it first, so re-saving an unchanged
        // account encrypts the same plaintext rather than the ciphertext of the
        // previous ciphertext.
        if ($value instanceof SmtpSecret) {
            return $value->isPresent() ? encrypt((string) $value->reveal()) : null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return encrypt((string) $value);
    }
}
