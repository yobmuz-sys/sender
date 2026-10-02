<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * The one entirely deterministic check in the pipeline.
 *
 * Everything else in validation depends on a third party answering honestly. This
 * does not: given the same address it always returns the same verdict, and that
 * makes it the only layer that can safely produce {@see ValidationStatus::ConfirmedInvalid}
 * on its own.
 *
 * The supported syntax is deliberately ASCII, and deliberately narrow within it.
 * It follows the Internet Message Format closely enough for anything a scraped or
 * pasted list contains, and does not attempt the exotic address forms RFC 5322
 * permits — obsolete source routes, comments, quoted local parts with embedded
 * whitespace. Those do not appear in extracted addresses, and a validator that
 * accepted them would have to reason about quoting and escaping to do it
 * correctly. Accepting one partially is worse than not accepting it, because the
 * failure would be silent: a quoted local part is matched structurally here and
 * then rejected by the control-character rule below, which would look like a
 * validator that accepts quoted addresses and does not.
 *
 * An address containing a non-ASCII byte is **not** called invalid. This platform
 * does not canonicalise internationalised addresses — that needs IDN support the
 * host requirements do not promise — so the honest result is `UNKNOWN`, not a
 * confident rejection of an address that may well be real and deliverable. That is
 * the accuracy rule applied to a limitation of this file rather than to the
 * recipient's mail server: never claim certainty the evidence does not support.
 *
 * The rules, stated so a caller can predict the verdict:
 *
 *   - exactly one `@`, not at either end
 *   - local part 1-64 characters, domain 1-253, address at most 254 (RFC 5321)
 *   - local part: dot-separated atoms of the RFC 5322 atext set, with no leading,
 *     trailing or doubled dot
 *   - domain: two or more labels of letters, digits and interior hyphens, each at
 *     most 63 characters, or a bracketed IPv4/IPv6 address literal
 *   - no whitespace or control characters anywhere
 *
 * A single-label domain such as `localhost` is refused: it is a host name, not a
 * domain, and mail addressed to one is not deliverable over SMTP.
 */
final class SyntaxValidator
{
    /**
     * RFC 5322 `atext`. The set of characters permitted in an unquoted atom.
     */
    private const ATEXT = "A-Za-z0-9!#$%&'*+\\-\\/=?^_`{|}~";

    /**
     * The same set as a character class.
     *
     * Held separately because {@see ATEXT} contains an apostrophe, and building
     * the class inline would terminate the pattern's own string literal.
     */
    private const ATOM_CLASS = '['.self::ATEXT.']';

    /**
     * The local part: a run of atoms joined by single dots.
     *
     * `+` rather than one character: an atom is itself a run of atext characters,
     * so `first` is a single atom and `[atext](?:\.[atext])*` would match only
     * `f.l`. Written on one line deliberately — a character class cannot span
     * lines, and a multiline version of this pattern compiled to an unusable
     * regular expression, which fails at the *first* address checked, silently,
     * as "this address is invalid".
     *
     * The dot rules — no leading, trailing or doubled dot — are enforced by
     * {@see isAcceptable()} rather than here, so that each is a separate
     * statement a reader can check against the rules listed above.
     */
    private const LOCAL = '(?<local>'.self::ATOM_CLASS.'+(?:\.'.self::ATOM_CLASS.'+)*)';

    /**
     * One domain label: 1-63 characters, letters, digits and interior hyphens.
     */
    private const LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';

    /**
     * The domain: a bracketed address literal, or two or more labels.
     *
     * At least two labels, because a single label is a host name rather than a
     * domain. `user@localhost` is not deliverable mail over SMTP, and accepting
     * it would put a permanently undeliverable address into a customer's list.
     */
    private const DOMAIN = '(?<domain>\[[0-9A-Fa-f:.]+\]|'.self::LABEL.'(?:\.'.self::LABEL.')+)';

    /**
     * The whole address, anchored, with no trailing-newline tolerance.
     *
     * `FILTER_VALIDATE_EMAIL` is not used: it is a black box whose exact
     * boundaries are undocumented, and a customer disputing a result deserves an
     * answer this file can state.
     */
    private const ADDRESS = '/^'.self::LOCAL.'@'.self::DOMAIN.'$/D';

    /**
     * Whether this platform is willing to call the given string an email address.
     *
     * A plain predicate, deliberately free of opinion about what to do with the
     * answer. Deciding the consequence is {@see check()}'s job, and keeping the
     * two separate is what stops a caller from treating "false" as "skip it" and
     * leaving a malformed address sitting unclassified in an audience.
     */
    public function isAcceptable(string $email): bool
    {
        $email = trim($email);

        if ($email === '' || strlen($email) > 254) {
            return false;
        }

        // Any control character or whitespace means this is not an address. It is
        // usually a sign the extractor matched across a line or tag boundary.
        if (preg_match('/[\x00-\x20\x7F]/', $email) === 1) {
            return false;
        }

        if (preg_match(self::ADDRESS, $email, $matches) !== 1) {
            return false;
        }

        // RFC 5321 length limits, checked on the parts rather than assumed from
        // the pattern: a pattern that permits any length is not a length check.
        $local = (string) $matches['local'];
        $domain = (string) $matches['domain'];

        if ($local === '' || strlen($local) > 64) {
            return false;
        }

        if ($domain === '' || strlen($domain) > 253) {
            return false;
        }

        // Label length. Kept out of the pattern because a bounded repetition
        // inside a repeated group is where a regex stops being readable, and 63 is
        // a three-line check stated plainly.
        if (! str_starts_with($domain, '[')) {
            foreach (explode('.', $domain) as $label) {
                if ($label === '' || strlen($label) > 63) {
                    return false;
                }
            }
        }

        // No leading, trailing or doubled dot in the local part. Stated here
        // rather than as a lookaround so each rule is a line a reader can check.
        return ! str_starts_with($local, '.')
            && ! str_ends_with($local, '.')
            && ! str_contains($local, '..');
    }

    /**
     * The verdict for one address.
     *
     * A rejected address is confirmed invalid, because there is nothing left to
     * check: no server can be asked about an address that cannot be addressed.
     * An accepted address returns `notValidated()` rather than an "active"
     * outcome, because passing a syntax check says nothing about whether the
     * mailbox exists.
     */
    public function check(string $email): ValidationOutcome
    {
        // An address this platform cannot express in ASCII is reported as
        // `UNKNOWN` rather than as invalid. Calling it invalid would be a
        // confident claim — that the mailbox does not exist — resting on nothing
        // more than this file's inability to canonicalise the address. It is
        // excluded from sending by default either way, which is what the accuracy
        // rule is protecting.
        if (preg_match('/[\x80-\xFF]/', $email) === 1) {
            return ValidationOutcome::unknown(ValidationReason::InternationalisedAddress);
        }

        return $this->isAcceptable($email)
            ? ValidationOutcome::notValidated()
            : ValidationOutcome::invalidSyntax();
    }
}
