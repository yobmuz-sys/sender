<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * What validation concluded about one address.
 *
 * The accuracy rule this platform commits to is narrow and deliberate:
 *
 *     maximise the precision of CONFIRMED_INVALID
 *     never classify uncertain evidence as inactive
 *
 * Precision on `CONFIRMED_INVALID` is the only thing worth optimising, because
 * that classification is the one that removes an address from sending. A false
 * positive there silently discards a real recipient; a false *negative* merely
 * sends one message that bounces. So the burden of proof sits entirely on the
 * invalid classification, and `UNKNOWN` is a legitimate answer rather than a
 * failure to finish the job.
 *
 * The four outcomes are not a confidence scale and must not be read as one. They
 * are four different *kinds* of answer:
 *
 *     CONFIRMED_INVALID  deterministic evidence that the address cannot receive
 *     LIKELY_ACTIVE      meaningful positive evidence that it can
 *     UNKNOWN            the evidence was ambiguous, blocked, temporary or absent
 *     RISKY              excluded by policy, pending evidence the platform has
 *
 * `LIKELY_ACTIVE` is named for what it is. A recipient server accepting an
 * address during RCPT establishes that the address was not refused at that
 * moment; it does not establish that a message will be delivered, and the UI
 * never says "active" without this qualifier.
 */
enum ValidationStatus: string
{
    case ConfirmedInvalid = 'confirmed_invalid';

    case LikelyActive = 'likely_active';

    case Unknown = 'unknown';

    case Risky = 'risky';

    /**
     * Short label for a person with no technical knowledge.
     */
    public function label(): string
    {
        return match ($this) {
            self::ConfirmedInvalid => 'Inactive — confirmed',
            self::LikelyActive => 'Likely active',
            self::Unknown => 'Unknown',
            self::Risky => 'Protected / risky',
        };
    }

    /**
     * One line a nontechnical user can act on.
     *
     * Deliberately says what was observed rather than what it implies. "The
     * recipient server accepted this address" is checkable against the evidence;
     * "this mailbox exists" is a claim the platform cannot make.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::ConfirmedInvalid => 'The recipient server reported that this mailbox does not exist.',
            self::LikelyActive => 'The recipient server accepted this address.',
            self::Unknown => 'The recipient server did not provide enough information to confirm the mailbox.',
            self::Risky => 'This address is excluded by policy until there is more evidence about it.',
        };
    }

    /**
     * Whether this status, on its own, makes the address eligible to send to.
     *
     * Only `LIKELY_ACTIVE`. `UNKNOWN` is excluded by default: refusing to send to
     * an address we could not verify costs a recipient we might have reached, and
     * accepting the risk of sending to one we could not costs a bounce, a
     * complaint or a reputation signal the provider then holds against the whole
     * account. For a platform used by someone with no technical knowledge, the
     * second failure is the more expensive one.
     *
     * Suppression and consent are applied separately and always win — see
     * {@see AudienceEligibility}.
     */
    public function isSendEligible(): bool
    {
        return $this === self::LikelyActive;
    }

    /**
     * The badge tone. One mapping so a status never means two things.
     */
    public function tone(): string
    {
        return match ($this) {
            self::ConfirmedInvalid => 'rose',
            self::LikelyActive => 'emerald',
            self::Unknown => 'slate',
            self::Risky => 'amber',
        };
    }

    /**
     * Whether this outcome may be cached for longer than a single validation run.
     *
     * `CONFIRMED_INVALID` is cacheable for longer than `LIKELY_ACTIVE`, and the
     * asymmetry is deliberate. A mailbox that does not exist is a stable fact;
     * a mailbox that accepted an address yesterday says nothing about tomorrow,
     * because the server can start rejecting it at any moment and a stale
     * "active" is what turns a healthy list into a burst of bounces.
     */
    public function cacheForSeconds(int $activeTtl, int $invalidTtl): int
    {
        return match ($this) {
            self::ConfirmedInvalid, self::Risky => $invalidTtl,
            self::LikelyActive, self::Unknown => $activeTtl,
        };
    }

    /**
     * Every status, for a report that must count all four.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::LikelyActive,
            self::ConfirmedInvalid,
            self::Unknown,
            self::Risky,
        ];
    }
}
