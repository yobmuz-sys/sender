<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * The canonical form of an address, and the only place one is produced.
 *
 * `contacts.normalized_email` is the column the whole audience layer is keyed on,
 * so how it is derived decides whether one person is one contact or two. The
 * derivation therefore lives in a type with a stated rationale rather than in a
 * `strtolower()` at each of the four places that would otherwise need it.
 *
 * Two decisions, both about not splitting one recipient into two:
 *
 *   - the local part is lower-cased. RFC 5321 permits it to be case-sensitive,
 *     and in principle `Jane@example.com` and `jane@example.com` could be two
 *     mailboxes. In practice every mainstream provider treats them as one, and
 *     leaving them distinct would put the same person on a list twice — which
 *     for this platform means two rows, two consent records and two chances to
 *     send to somebody who unsubscribed.
 *   - presentation is stripped, not just whitespace. Addresses are lifted out of
 *     scraped pages, mail headers and pasted spreadsheets, so they arrive
 *     surrounded by angle brackets and quotes. `<jane@example.com>` and
 *     `jane@example.com` are the same recipient.
 *
 * The domain is lower-cased and its trailing dot removed for the same reason: DNS
 * names are case-insensitive, and a trailing dot is the same name written
 * absolutely.
 *
 * Deliberately *not* canonicalised: display names, plus-addressing tags, dots in
 * local parts, and sub-addressing in any provider-specific form. Each is either
 * syntax this platform has said it does not support, or a provider-specific
 * convention that would require a maintained table of every provider's rules and
 * would risk merging two genuinely distinct mailboxes. Guessing here is how a
 * contact record becomes a lie.
 */
final readonly class EmailAddress
{
    private function __construct(
        public string $local,
        public string $domain,
    ) {}

    /**
     * Split an address, or null if it has no usable shape at all.
     *
     * Returns null for anything that is not an address *of a recognisable form*,
     * which is not the same question as {@see SyntaxValidator::isAcceptable()}.
     * A malformed candidate still needs somewhere to live so the report can show
     * it as confirmed invalid rather than dropping it silently — an address the
     * customer can see rejected is worth more than one that vanished.
     */
    public static function parse(string $candidate): ?self
    {
        $value = self::strip($candidate);

        if ($value === '') {
            return null;
        }

        // The last `@`, not the first: a local part may legally contain one only
        // inside quotes, which this platform does not accept, so a trailing
        // separator is the only honest reading of `a@b@c`.
        $at = strrpos($value, '@');

        if ($at === false || $at === 0 || $at === strlen($value) - 1) {
            return null;
        }

        $domain = rtrim(mb_strtolower(substr($value, $at + 1)), '.');

        if ($domain === '') {
            return null;
        }

        return new self(mb_strtolower(substr($value, 0, $at)), $domain);
    }

    /**
     * The canonical key for an address.
     *
     * Falls back to a lower-cased, whitespace-free reduction of the input when
     * the value cannot be split, so that a malformed address still has a stable
     * key. Two malformed candidates that reduce to the same string *are* the same
     * row, and that is correct: they would otherwise accumulate as duplicates on
     * every re-import of the same broken list.
     */
    public static function key(string $candidate): string
    {
        return self::parse($candidate)?->normalized() ?? mb_strtolower(self::strip($candidate));
    }

    public function normalized(): string
    {
        return $this->local.'@'.$this->domain;
    }

    /**
     * The form stored alongside the key.
     *
     * The address as the customer recognises it in their own list, so a report
     * shows what they pasted rather than a transformation of it.
     */
    public static function display(string $candidate): string
    {
        $value = self::strip($candidate);

        return $value === '' ? mb_strtolower($candidate) : $value;
    }

    /**
     * Remove the presentation an address carries on its way out of a page.
     *
     * Angle brackets, matching quotes, and whitespace including the non-breaking
     * space that a copied web page routinely contains. A trailing comma or
     * semicolon is left alone: it is more often part of a malformed address worth
     * reporting than decoration around a good one.
     */
    private static function strip(string $candidate): string
    {
        $value = trim($candidate);

        // U+00A0 and friends: a copied web page is full of them, and `trim()`
        // removes only ASCII whitespace.
        $value = (string) preg_replace('/[\p{Z}\s]+/u', '', $value);

        if (str_starts_with($value, '<') && str_ends_with($value, '>')) {
            $value = trim(substr($value, 1, -1));
        }

        if (strlen($value) > 1 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }
}
