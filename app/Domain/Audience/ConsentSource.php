<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * How a piece of consent was obtained.
 *
 * Each case is a claim about what the evidence *is*, and the wording is kept
 * precise enough that a customer reading it cannot reasonably mistake one for
 * another:
 *
 *   - `DoubleOptIn` is the strongest thing a platform can hold: the recipient
 *     proved they control the mailbox by clicking a link in a message.
 *   - `WebForm` and `Signup` are self-declared. A recipient can be misled by a
 *     form; that does not make the record false, it makes it weaker evidence.
 *   - `ImportedAttestation` is the operator's word and nothing more. A list
 *     bought from a broker is this, and the platform records it as such rather
 *     than as consent.
 *
 * `ImportedAttestation` deliberately does **not** produce
 * {@see ConsentStatus::Confirmed} on its own. The whole point of separating
 * consent records from a boolean is that "somebody told me this person opted in"
 * is not the same as having a record that they did. Recording it as an unknown
 * with an operator attestation attached is honest; recording it as confirmed is
 * not.
 */
enum ConsentSource: string
{
    case WebForm = 'web_form';

    case Signup = 'signup';

    case DoubleOptIn = 'double_opt_in';

    case ImportedAttestation = 'imported_with_user_attestation';

    case Manual = 'manual';

    /**
     * Whether a record from this source is `CONFIRMED` on its own.
     *
     * Three of the five. The two exceptions are the ones a customer is most
     * likely to reach for, which is exactly why they are excluded: an operator
     * ticking a box is a decision, not evidence.
     */
    public function confirmsConsent(): bool
    {
        return in_array($this, [
            self::WebForm,
            self::Signup,
            self::DoubleOptIn,
        ], true);
    }

    /**
     * How strong this evidence is, described without a score.
     */
    public function strengthLabel(): string
    {
        return match ($this) {
            self::DoubleOptIn => 'Recipient confirmed they control the mailbox.',
            self::WebForm, self::Signup => 'Recipient agreed by signing a form.',
            self::ImportedAttestation => 'You told us the recipient agreed. We hold no record from them.',
            self::Manual => 'Recorded by an operator.',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::WebForm => 'Website sign-up form',
            self::Signup => 'Signup page',
            self::DoubleOptIn => 'Double opt-in (confirmed link)',
            self::ImportedAttestation => 'Imported, with your attestation',
            self::Manual => 'Recorded manually',
        };
    }
}
