<?php

declare(strict_types=1);

namespace Tests\Feature\Fakes;

use App\Domain\Audience\RecipientProber;
use App\Domain\Audience\RecipientProbeResult;

/**
 * A mail server that replies with whatever the test tells it to, and counts.
 *
 * The counting is the point of this class existing. Every guarantee in the
 * validation pipeline that is about *how much* network work is done — one MX
 * lookup per window, one catch-all probe per window, one recipient check per
 * mailbox until its evidence expires — is invisible without an instrument that
 * counts calls, and a test that merely asserts the final classification would
 * pass just as happily against a pipeline that asked the same question a thousand
 * times.
 *
 * The reply is chosen by exact address where a mapping is given and by a single
 * default otherwise, so a test can say "this domain rejects everything" or
 * "this one address is missing" without knowing anything about SMTP.
 */
final class RecordingProber implements RecipientProber
{
    /**
     * @var list<string> Every probe made, as "host|address".
     */
    public array $probes = [];

    /**
     * @param  array<string, RecipientProbeResult>|null  $byAddress  Replies keyed
     *                                                               by the exact address probed. An address with no entry falls back to
     *                                                               `$default`.
     * @param  array<string, RecipientProbeResult>  $byHost  Replies keyed by
     *                                                       mail host, tried before `$default`.
     * @param  RecipientProbeResult|null  $synthetic  Reply for any address that
     *                                                cannot exist — anything the catch-all detector invents. Needed
     *                                                because a server that accepts everything makes every mailbox at the
     *                                                domain uncheckable, which is a real answer but hides the mailbox
     *                                                layer from any test that is about the mailbox layer.
     */
    public function __construct(
        public array $byAddress = [],
        public array $byHost = [],
        private ?RecipientProbeResult $default = null,
        private ?RecipientProbeResult $synthetic = null,
    ) {
        $this->default ??= RecipientProbeResult::replied(250);
    }

    public static function always(RecipientProbeResult $result): self
    {
        return new self(default: $result);
    }

    /**
     * A domain that rejects imaginary mailboxes and accepts real ones.
     */
    public static function discriminating(): self
    {
        return new self(
            default: RecipientProbeResult::replied(250),
            synthetic: RecipientProbeResult::replied(550, '5.1.1'),
        );
    }

    public function probe(string $host, string $address): RecipientProbeResult
    {
        $this->probes[] = $host.'|'.$address;

        return $this->byAddress[$address]
            ?? $this->byHost[$host]
            ?? ($this->synthetic !== null && str_starts_with($address, 'sender-check-') ? $this->synthetic : null)
            ?? $this->default;
    }

    /**
     * How many catch-all probes were made, across every domain.
     */
    public function syntheticProbes(): int
    {
        return count(array_filter(
            $this->probes,
            static fn (string $probe): bool => str_contains($probe, '|sender-check-'),
        ));
    }

    /**
     * How many times a given address was asked about.
     */
    public function timesProbed(string $address): int
    {
        return count(array_filter(
            $this->probes,
            static fn (string $probe): bool => str_ends_with($probe, '|'.$address),
        ));
    }

    /**
     * Every address asked about, in order, without the host prefix.
     *
     * @return list<string>
     */
    public function addressesProbed(): array
    {
        return array_map(
            static fn (string $probe): string => substr($probe, strpos($probe, '|') + 1),
            $this->probes,
        );
    }
}
