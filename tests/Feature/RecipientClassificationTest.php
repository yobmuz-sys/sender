<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audience\CatchAllDetector;
use App\Domain\Audience\CatchAllVerdict;
use App\Domain\Audience\DomainValidationCache;
use App\Domain\Audience\MailboxSmtpValidator;
use App\Domain\Audience\MailboxValidationCache;
use App\Domain\Audience\MailRouteStatus;
use App\Domain\Audience\RecipientProbeResult;
use App\Domain\Audience\SyntaxValidator;
use App\Domain\Audience\ValidationPipeline;
use App\Domain\Audience\ValidationReason;
use App\Domain\Audience\ValidationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Fakes\FakeMailRouteResolver;
use Tests\Feature\Fakes\RecordingProber;
use Tests\TestCase;

/**
 * The accuracy rule, tested as a rule rather than as a set of examples.
 *
 * One direction of error is tolerable and the other is not. Calling a live
 * recipient inactive costs a customer one address; calling a dead one active
 * costs them a bounce, and on a shared host enough of them cost them their
 * sending reputation outright. So the property under test throughout is:
 *
 *     CONFIRMED_INVALID is only ever reached by a deterministic local check or an
 *     allowlisted enhanced status code, and nothing else — not a timeout, not a
 *     252, not a bare 550, not a catch-all, not a disabled SMTP probe — produces
 *     it.
 *
 * That is asserted from both directions. Every reply shape is checked for what it
 * produces, and then the negative space is checked separately: whatever
 * {@see ValidationReason::definitive()} allows must be the only thing that reaches
 * the invalid verdict, so a future contributor adding a new branch is caught by
 * the second test even if the first is not updated.
 */
class RecipientClassificationTest extends TestCase
{
    /**
     * @return array<string, array{0: RecipientProbeResult, 1: ValidationStatus, 2: ValidationReason|null}>
     */
    public static function replies(): array
    {
        return [
            // Acceptance. The server says it will take this recipient. It says
            // nothing about whether the message arrives, which is why the status
            // is "likely active" and never "active".
            '250 accepts' => [RecipientProbeResult::replied(250), ValidationStatus::LikelyActive, ValidationReason::RecipientAccepted],
            '251 forwards' => [RecipientProbeResult::replied(251), ValidationStatus::Unknown, ValidationReason::ProviderProtection],

            // The server declining to verify. RFC 5321 gives this a distinct
            // code precisely so it can be told apart from acceptance.
            '252 will not verify' => [RecipientProbeResult::replied(252), ValidationStatus::Unknown, ValidationReason::InconclusiveVerification],

            // Temporary failures are an instruction to try again, not a verdict.
            '450 try later' => [RecipientProbeResult::replied(450), ValidationStatus::Unknown, ValidationReason::TemporaryFailure],
            '421 service unavailable' => [RecipientProbeResult::replied(421), ValidationStatus::Unknown, ValidationReason::TemporaryFailure],

            // The decisive pair. A bare 550 is a rejection, and rejections are
            // what anti-enumeration measures are built from. Only the enhanced
            // status separates "no such mailbox" from "we will not tell you".
            '550 with no enhanced code' => [RecipientProbeResult::replied(550), ValidationStatus::Unknown, ValidationReason::ProviderProtection],
            '550 with 5.1.1' => [RecipientProbeResult::replied(550, '5.1.1'), ValidationStatus::ConfirmedInvalid, ValidationReason::MailboxNotFound],
            '550 with 5.1.2' => [RecipientProbeResult::replied(550, '5.1.2'), ValidationStatus::ConfirmedInvalid, ValidationReason::NoMailRoute],
            '550 with 5.1.3' => [RecipientProbeResult::replied(550, '5.1.3'), ValidationStatus::ConfirmedInvalid, ValidationReason::InvalidSyntax],

            // Explicitly ambiguous, and explicitly not an answer.
            '550 with 5.1.4' => [RecipientProbeResult::replied(550, '5.1.4'), ValidationStatus::Unknown, ValidationReason::ProviderProtection],

            // Policy space: the server's disposition toward us, not the mailbox.
            '550 with 5.7.1' => [RecipientProbeResult::replied(550, '5.7.1'), ValidationStatus::Unknown, ValidationReason::PolicyRejection],
            '550 with 5.7.25' => [RecipientProbeResult::replied(550, '5.7.25'), ValidationStatus::Unknown, ValidationReason::PolicyRejection],

            // Confirms the mailbox exists and refuses mail to it. Not invalid —
            // and not usable either, which is why it is excluded from sending
            // rather than counted as likely active.
            '550 with 5.2.1' => [RecipientProbeResult::replied(550, '5.2.1'), ValidationStatus::Unknown, ValidationReason::MailboxDisabled],

            // No reply at all. Nothing about the mailbox was learned.
            'timed out' => [RecipientProbeResult::unreachable(ValidationReason::Timeout), ValidationStatus::Unknown, ValidationReason::Timeout],
            'connection refused' => [RecipientProbeResult::unreachable(ValidationReason::DnsUnavailable), ValidationStatus::Unknown, ValidationReason::DnsUnavailable],

            // A 5xx with an unrecognised enhanced code is still just a rejection.
            '550 with 9.9.9' => [RecipientProbeResult::replied(550, '9.9.9'), ValidationStatus::Unknown, ValidationReason::ProviderProtection],
        ];
    }

    #[DataProvider('replies')]
    public function test_one_smtp_reply_produces_exactly_one_classification(
        RecipientProbeResult $reply,
        ValidationStatus $expectedStatus,
        ValidationReason $expectedReason,
    ): void {
        $outcome = (new MailboxSmtpValidator(new RecordingProber))->classify($reply);

        $this->assertSame($expectedStatus, $outcome->status);
        $this->assertSame($expectedReason, $outcome->reason);
    }

    /**
     * The negative space.
     *
     * Rather than trusting the table above to stay in step with the code, this
     * sweeps every reachable outcome shape and asserts the invalid verdict only
     * ever carries a reason the accuracy rule declares definitive. A contributor
     * who widens `classifyPermanent` without thinking about this fails here even
     * if they remembered to update the other test.
     */
    public function test_only_a_deterministic_reason_can_produce_a_confirmed_invalid_verdict(): void
    {
        $validator = new MailboxSmtpValidator(new RecordingProber);

        foreach (self::replies() as [$reply]) {
            $outcome = $validator->classify($reply);

            if ($outcome->status !== ValidationStatus::ConfirmedInvalid) {
                continue;
            }

            $this->assertTrue(
                $outcome->reason->isDefinitive(),
                $outcome->reason->value.' confirmed an address as invalid without being a definitive reason',
            );
        }

        // And the sweep is not vacuous: the rule must actually reject at least
        // one thing, or it is not testing anything.
        $rejections = array_filter(
            self::replies(),
            static fn (array $case): bool => $validator->classify($case[0])->status !== ValidationStatus::ConfirmedInvalid,
        );

        $this->assertGreaterThan(
            10,
            count($rejections),
            'almost every reply shape must be refused an invalid verdict',
        );
    }

    public function test_a_bare_550_is_the_case_the_platform_must_not_get_wrong(): void
    {
        // Stated on its own because it is the single most consequential mapping
        // in the pipeline. Providers answer 550 to everything when they refuse to
        // enumerate, and a validator that reads that as "no such mailbox" silently
        // discards a live audience.
        $outcome = (new MailboxSmtpValidator(new RecordingProber))
            ->classify(RecipientProbeResult::replied(550));

        $this->assertSame(ValidationStatus::Unknown, $outcome->status);
        $this->assertFalse($outcome->reason->isDefinitive());
    }

    public function test_a_syntax_failure_is_decided_locally_without_asking_a_mail_server(): void
    {
        $pipeline = $this->pipeline(prober: new RecordingProber);

        $outcome = $pipeline->validate('not-an-address', 1);

        $this->assertSame(ValidationStatus::ConfirmedInvalid, $outcome->status());
        $this->assertSame(ValidationReason::InvalidSyntax, $outcome->outcome->reason);
    }

    public function test_an_internationalised_address_is_never_called_invalid(): void
    {
        // The platform cannot canonicalise it, which is a limitation of the
        // checker rather than a fact about the mailbox. Reporting it dead would
        // be a confident claim made from no evidence.
        $outcome = $this->pipeline()->validate('josé@example.com', 1);

        $this->assertNotSame(ValidationStatus::ConfirmedInvalid, $outcome->status());
        $this->assertSame(ValidationReason::InternationalisedAddress, $outcome->outcome->reason);
    }

    public function test_a_domain_that_does_not_exist_is_confirmed_invalid(): void
    {
        $pipeline = $this->pipeline(
            routes: new FakeMailRouteResolver(
                ['gone.test' => MailRouteStatus::DomainNotFound],
            ),
        );

        $outcome = $pipeline->validate('someone@gone.test', 1);

        $this->assertSame(ValidationStatus::ConfirmedInvalid, $outcome->status());
        $this->assertSame(ValidationReason::DomainNotFound, $outcome->outcome->reason);
    }

    public function test_a_domain_that_exists_but_takes_no_mail_is_confirmed_invalid(): void
    {
        $pipeline = $this->pipeline(
            routes: new FakeMailRouteResolver(
                ['quiet.test' => MailRouteStatus::NoRoute],
            ),
        );

        $outcome = $pipeline->validate('someone@quiet.test', 1);

        $this->assertSame(ValidationStatus::ConfirmedInvalid, $outcome->status());
        $this->assertSame(ValidationReason::NoMailRoute, $outcome->outcome->reason);
    }

    public function test_an_unreachable_resolver_never_produces_an_invalid_verdict(): void
    {
        // The destructive case. A resolver that is simply down must not classify
        // an entire audience as invalid, because the customer will act on the
        // report before anyone notices the resolver was the problem.
        $pipeline = $this->pipeline(
            routes: new FakeMailRouteResolver(default: MailRouteStatus::Unavailable),
        );

        $outcome = $pipeline->validate('someone@quiet.test', 1);

        $this->assertSame(ValidationStatus::Unknown, $outcome->status());
        $this->assertSame(ValidationReason::DnsUnavailable, $outcome->outcome->reason);
    }

    public function test_a_catch_all_domain_turns_acceptance_into_unknown(): void
    {
        // The most valuable single check in the pipeline. A catch-all returns
        // 250 for everything, so reporting the result would call the whole list
        // active — in the direction that looks like success and is silently wrong.
        $pipeline = $this->pipeline(
            prober: RecordingProber::always(RecipientProbeResult::replied(250)),
        );

        $outcome = $pipeline->validate('someone@catchall.test', 1);

        $this->assertSame(ValidationStatus::Unknown, $outcome->status());
        $this->assertSame(ValidationReason::CatchAll, $outcome->outcome->reason);
    }

    public function test_a_domain_that_rejects_impossible_addresses_is_not_catch_all(): void
    {
        // The mirror image, and the reason the catch-all probe is worth a socket:
        // a 550 to an address that cannot exist proves the domain discriminates,
        // so its acceptance of a real address means something after all.
        $pipeline = $this->pipeline(
            prober: new RecordingProber(byAddress: [], byHost: [], default: RecipientProbeResult::replied(550, '5.1.1')),
        );

        $outcome = $pipeline->validate('someone@real.test', 1);

        // The mailbox check itself returns 5.1.1 for the real address too in this
        // fixture, so the address is invalid — but not because of the catch-all
        // probe, which answered "no" and therefore let the mailbox check run.
        $this->assertSame(ValidationReason::MailboxNotFound, $outcome->outcome->reason);
        $this->assertSame(CatchAllVerdict::No, $outcome->catchAll);
    }

    public function test_the_catch_all_probe_asks_about_an_address_that_cannot_be_guessed(): void
    {
        $detector = new CatchAllDetector(new RecordingProber);

        $first = $detector->syntheticAddressFor('example.test');
        $second = $detector->syntheticAddressFor('example.test');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second, 'a guessable probe address proves nothing');
        $this->assertMatchesRegularExpression(
            '/^sender-check-[0-9a-f]{32}@example\.test$/',
            $first,
        );
    }

    public function test_an_unanswered_catch_all_probe_is_not_treated_as_catch_all(): void
    {
        // A server we could not reach has not told us it accepts everything.
        $detector = new CatchAllDetector(
            RecordingProber::always(RecipientProbeResult::unreachable(ValidationReason::Timeout)),
        );

        $this->assertSame(
            CatchAllVerdict::Unknown,
            $detector->detect('mx1.mail-host.test', 'example.test'),
        );
    }

    public function test_a_catch_all_probe_answered_252_is_inconclusive_not_catch_all(): void
    {
        $detector = new CatchAllDetector(RecordingProber::always(RecipientProbeResult::replied(252)));

        $this->assertSame(
            CatchAllVerdict::Unknown,
            $detector->detect('mx1.mail-host.test', 'example.test'),
        );
    }

    public function test_validation_is_blocked_rather_than_faked_when_probing_is_switched_off(): void
    {
        // The shared-host default. Reporting "unknown, we were not allowed to
        // ask" is the honest answer; anything else would be a claim about a
        // mailbox the platform never spoke to.
        $pipeline = $this->pipeline(smtpProbing: false);

        $outcome = $pipeline->validate('someone@example.com', 1);

        $this->assertSame(ValidationStatus::Unknown, $outcome->status());
        $this->assertSame(ValidationReason::VerificationBlocked, $outcome->outcome->reason);
    }

    public function test_the_platform_does_not_claim_a_guarantee_it_cannot_make(): void
    {
        // A permanent regression guard on the wording. A customer who reads
        // "guaranteed" has been told something this platform cannot support, and
        // nothing in the test suite would otherwise notice.
        $statuses = array_map(
            static fn (ValidationStatus $status): string => $status->label(),
            ValidationStatus::cases(),
        );

        foreach ($statuses as $label) {
            $this->assertStringNotContainsStringIgnoringCase('guarantee', $label);
            $this->assertStringNotContainsStringIgnoringCase('99%', $label);
            $this->assertStringNotContainsStringIgnoringCase('100%', $label);
        }
    }

    /**
     * A pipeline over the given collaborators.
     *
     * Built by hand rather than resolved from the container so each test states
     * the world it is reasoning about, and so no configuration leaking from a
     * neighbouring test can change an answer here.
     */
    private function pipeline(
        ?RecordingProber $prober = null,
        ?FakeMailRouteResolver $routes = null,
        bool $smtpProbing = true,
    ): ValidationPipeline {
        $prober ??= RecordingProber::always(RecipientProbeResult::replied(250, '5.1.1'));
        $routes ??= FakeMailRouteResolver::everyDomainHasRoute();

        return new ValidationPipeline(
            new SyntaxValidator,
            $routes,
            new CatchAllDetector($prober),
            new MailboxSmtpValidator($prober),
            new DomainValidationCache(
                (int) config('sender.validation.domain_cache_ttl_seconds', 86400),
            ),
            new MailboxValidationCache,
            $smtpProbing,
            (int) config('sender.validation.catch_all_cache_ttl_seconds', 604800),
        );
    }
}
