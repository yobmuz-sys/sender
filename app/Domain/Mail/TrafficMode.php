<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * What kind of traffic a sending decision is about.
 *
 * The distinction exists because "is this transport healthy?" and "may this
 * transport send a bulk marketing campaign?" are two different questions with
 * two different answers, and answering only the second one loses real
 * information.
 *
 * The concrete case is authentication. Google's current requirements make SPF or
 * DKIM a condition of reaching a personal Gmail mailbox at all, but make
 * SPF *and* DKIM *and* DMARC with alignment a condition on bulk senders
 * exceeding a volume threshold. Yahoo's requirements follow the same shape.
 *
 * So a domain with no DMARC record can be in exactly this state:
 *
 *     SMTP technical status     READY
 *     DMARC                     MISSING
 *     Bulk marketing readiness  BLOCKED
 *
 * All three are true simultaneously. Reporting only the last one as "SMTP:
 * BROKEN" would be wrong — the transport works, and something that does work
 * has a separate, unrelated gap. Reporting only the first would hand a customer
 * a green light for a campaign their provider will reject.
 *
 * Both answers are reported. Which question is being asked is stated by this
 * enum rather than inferred by the reader.
 */
enum TrafficMode: string
{
    /**
     * Ordinary transactional mail to an account that asked to receive it.
     *
     * One recipient at a time, no acquisition or engagement marketing, no
     * bulk volume. Authenticated and correctly addressed mail is expected to be
     * accepted; a missing DMARC record is a warning, not a block, because a
     * domain at this volume is not required to publish one.
     */
    case Transactional = 'transactional';

    /**
     * Bulk marketing and subscription mail to a purchased, scraped or consented
     * audience.
     *
     * The mode providers apply their bulk-sender requirements to: full SPF, DKIM
     * and DMARC with alignment, TLS, valid forward and reverse DNS, one-click
     * unsubscribe, and a complaint rate held below their threshold.
     *
     * The audience requirements are enforced by the audience layer
     * (Stage 5B) and the delivery layer (Stage 5D). This enum names the mode so
     * the authentication findings can be reported at the strength that mode
     * actually requires.
     */
    case BulkMarketing = 'bulk_marketing';

    public function label(): string
    {
        return match ($this) {
            self::Transactional => 'Transactional',
            self::BulkMarketing => 'Bulk marketing',
        };
    }

    /**
     * What a customer is deciding, in the words they would use.
     */
    public function question(): string
    {
        return match ($this) {
            self::Transactional => 'May this account send ordinary transactional messages?',
            self::BulkMarketing => 'May this account send bulk marketing messages?',
        };
    }

    /**
     * Whether a missing authentication record of this kind is disqualifying.
     *
     * Only DMARC is treated this way, and only in bulk mode. SPF and DKIM are
     * reported as warnings in both modes, because the platform cannot observe
     * whether the final message is authenticated — that depends on the provider
     * that signs it, and this application never sees the headers.
     *
     * DMARC is different: the *record* is on the sending domain and is directly
     * checkable, it is a bulk-sender requirement rather than a volume-scaled one,
     * and a domain cannot adopt a policy for mail already in flight. So its
     * absence is a definite fact that blocks the mode that requires it.
     */
    public function requiresDmarc(): bool
    {
        return $this === self::BulkMarketing;
    }

    /**
     * A one-line explanation of what this mode additionally requires.
     */
    public function requirement(): string
    {
        return match ($this) {
            self::Transactional => 'SPF or DKIM, TLS, and correct addressing.',
            self::BulkMarketing => 'SPF, DKIM and DMARC with alignment, TLS, forward and reverse DNS, '
                .'one-click unsubscribe, and a low complaint rate.',
        };
    }
}
