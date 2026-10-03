<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

/**
 * One identifier for one logical outbound message, generated once.
 *
 * This exists because of a mistake worth naming precisely. The `Message-ID` used to
 * be generated inside {@see SmtpMessageTransport::submit()}, which means it was
 * generated once per *attempt*: attempt 1 went out as `-a1@`, was throttled, and
 * attempt 2 went out as `-a2@`. Both were "the same message" to a person, and they
 * were two unrelated messages to every piece of infrastructure that correlates by
 * identifier — including the bounce and complaint feedback Stage 5D is going to
 * ingest. A hard bounce against attempt 1 would have found nothing, because by then
 * the address it referenced belonged to a message nobody could produce any more.
 *
 * So the identifier is generated here, once, and persisted on the recipient row
 * before anything is submitted. Every attempt for that recipient submits the same
 * identifier, which is what makes a bounce correlate to a logical message rather
 * than to whichever attempt happened to be running.
 *
 * Two rules the shape of the value encodes:
 *
 * - **It never contains the attempt number.** An identifier that changes on retry
 *   is not an identifier for a message; it is a log line wearing one.
 * - **It is random rather than derived.** It could have been a hash of campaign and
 *   contact, which would make it reproducible from data the platform already has.
 *   It is not, because a value that can be recomputed from a campaign id and a row
 *   id invites being treated as a handle on that row rather than as what it is: a
 *   string on the wire that a receiving server echoes back. Randomness here costs
 *   nothing — the value is stored anyway — and keeps the two apart.
 *
 * The uniqueness of the value is enforced by the database, not trusted from here.
 * Two hundred and thirty-four bits from `random_bytes` makes a collision not a
 * thing that happens, and a unique index means that if it somehow did, the failure
 * is a loud one rather than two recipients sharing an identifier forever.
 */
final class LogicalMessageId
{
    /**
     * A new identifier, for a recipient that does not have one yet.
     *
     * The domain is the platform's own host, so the identifier is one this
     * deployment controls and a receiver can attribute. `config('app.url')` is the
     * only place that knows it, and it is read here rather than passed in, so that
     * no caller can invent a domain and put another host's name on the wire.
     */
    public function generate(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        // A deployment with no `app.url` still has to send mail. An unparseable
        // value falls back to a literal, which keeps the header syntactically valid
        // rather than producing something like `campaign-abc@` that a receiving
        // server would reject — or, worse, quietly reinterpret.
        if (! is_string($host) || $host === '') {
            $host = 'localhost';
        }

        return 'campaign-'.bin2hex(random_bytes(12)).'@'.$host;
    }
}
