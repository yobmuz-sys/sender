<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * What is known about a recipient's permission to be contacted.
 *
 * Three states, and the middle one is the point. A boolean `consented` on a
 * contact cannot distinguish "the recipient signed up" from "somebody pasted this
 * list" from "somebody typed this in by hand", and those three situations call for
 * entirely different decisions.
 *
 *     Confirmed  there is recorded evidence the recipient agreed
 *     Unknown    nothing is known either way
 *     Withdrawn  the recipient asked to stop, or a recorded consent was revoked
 *
 * `Unknown` is the default and the overwhelmingly common case, because a scraped
 * or purchased list has no consent attached to it. That is not a defect in the
 * data — it is the truth about it — and a platform that quietly set it to
 * `Confirmed` would be manufacturing permission from nothing.
 *
 * What `Confirmed` means is narrower than it looks. It means *this platform holds
 * a record that the recipient agreed to receive mail from this sender*. It is not
 * a legal conclusion, and no method here asserts one — a double opt-in is stronger
 * evidence than a self-declared checkbox, and both are recorded as what they are.
 */
enum ConsentStatus: string
{
    case Confirmed = 'confirmed';

    case Unknown = 'unknown';

    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Consent confirmed',
            self::Unknown => 'Consent not recorded',
            self::Withdrawn => 'Consent withdrawn',
        };
    }

    /**
     * Plain wording for a person who is not a lawyer and does not want to be.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::Confirmed => 'We hold a record that this person agreed to receive email from you.',
            self::Unknown => 'We hold no record of this person agreeing to receive email. '
                .'The address may still be perfectly valid — it simply has no permission recorded.',
            self::Withdrawn => 'This person asked not to be contacted, or a recorded agreement was revoked.',
        };
    }

    /**
     * Whether this status alone permits sending.
     *
     * Only `Confirmed`. Suppression is applied separately and always wins, so a
     * withdrawn consent is already excluded twice over — once here and once by the
     * suppression record it creates.
     */
    public function isSendEligible(): bool
    {
        return $this === self::Confirmed;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Confirmed => 'emerald',
            self::Unknown => 'slate',
            self::Withdrawn => 'rose',
        };
    }
}
