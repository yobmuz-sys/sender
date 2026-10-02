<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\UnsubscribeToken;
use Illuminate\Support\Str;

/**
 * Issues and resolves the link that lets one recipient stop being contacted.
 *
 * Four properties, each of which is a requirement rather than a preference:
 *
 *   - **Opaque.** The token is 32 bytes of cryptographic randomness and the
 *     stored column is its SHA-256 digest, so nothing in the URL or in a leaked
 *     backup identifies a recipient. A signed URL would be tamper-proof but
 *     decodable, which is the wrong trade for the one link this platform puts
 *     into strangers' inboxes.
 *   - **Immediate.** Suppression is written synchronously, with no queue in the
 *     path. An unsubscribe a worker has not got to yet is an unsubscribe that
 *     will occasionally be overtaken by the next campaign, and the cost of that
 *     is a message to somebody who asked not to receive one.
 *   - **Idempotent.** Clicking twice, clicking an old link twice, or clicking
 *     after the address was already suppressed all end in the same state, and
 *     none of them is an error the recipient is shown.
 *   - **Unauthenticated.** A recipient has no account. Requiring one would make
 *     the link useless, and requiring one it does not have is the single most
 *     common reason an unsubscribe requirement is quietly not met.
 */
class UnsubscribeLink
{
    /**
     * Mint a link for one contact and return the token to place in the message.
     *
     * @return string The token, which is never stored and cannot be recovered.
     */
    public function issue(Contact $contact): string
    {
        $token = Str::random(64);

        UnsubscribeToken::query()->create([
            'user_id' => $contact->user_id,
            'contact_id' => $contact->id,
            'token_hash' => $this->hash($token),
            'created_at' => now(),
        ]);

        return $token;
    }

    /**
     * The contact a token acts on, or null if it does not resolve.
     *
     * A lookup by digest only. There is no path here that accepts a contact
     * identifier, so no caller — including the controller — can unsubscribe
     * somebody by guessing an id.
     */
    public function resolve(string $token): ?Contact
    {
        if (strlen($token) < 32) {
            return null;
        }

        $row = UnsubscribeToken::query()
            ->where('token_hash', $this->hash($token))
            ->first();

        if ($row === null) {
            return null;
        }

        // The contact is re-read through the token's own tenant rather than
        // trusted from the row, so a token whose contact has been reassigned
        // cannot act on the new owner.
        return Contact::query()
            ->whereKey($row->contact_id)
            ->where('user_id', $row->user_id)
            ->first();
    }

    /**
     * Act on an unsubscribe, and say what was done.
     *
     * @return bool Whether the recipient was unsubscribed by this call. False
     *              means the token did not resolve, or the address was already
     *              suppressed — both of which the caller reports as success.
     */
    public function unsubscribe(string $token, SuppressionList $suppressions, ConsentLedger $consents): bool
    {
        $contact = $this->resolve($token);

        if ($contact === null) {
            return false;
        }

        if ($suppressions->isSuppressed($contact)) {
            // Already suppressed, by any means. The recipient's outcome is
            // already recorded, and re-recording it would be theatre.
            return false;
        }

        $suppressions->suppress(
            $contact,
            SuppressionReason::Unsubscribed,
            source: 'unsubscribe',
        );

        // Consent is withdrawn as well as suppressed. The two are separate
        // records answering separate questions, and leaving the consent record
        // standing would make the history say this recipient had agreed to be
        // contacted when they had since asked not to be.
        $consents->withdraw($contact);

        // Suppression already applies to every list and every future campaign —
        // that is what the tenant-scoped row means — so nothing is removed from
        // any list here. Deleting memberships would make the exclusion invisible
        // on the list the customer can actually see, which is the last place it
        // should be.
        return true;
    }

    /**
     * Whether an address is on any of a tenant's lists.
     *
     * Exposed for the unsubscribe page's wording, which has to tell the
     * recipient that they will stop being contacted *everywhere* rather than
     * from one campaign. That promise is kept by the tenant-scoped suppression
     * row, not by anything this class does.
     */
    public function isOnAnyList(Contact $contact): bool
    {
        return ContactListMembership::query()
            ->where('contact_id', $contact->id)
            ->where('user_id', $contact->user_id)
            ->exists();
    }

    /**
     * SHA-256, hex-encoded.
     *
     * Plain SHA-256 rather than a slow KDF, and deliberately so: there is no
     * secret to brute-force here. The input is 32 bytes of CSPRNG output, so the
     * only attack available is guessing a value with no structure, which any
     * hash handles. A slow KDF would cost real CPU on every link click to
     * protect against an attack that cannot succeed.
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
