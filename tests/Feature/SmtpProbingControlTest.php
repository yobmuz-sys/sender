<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audience\MailRouteResolver;
use App\Domain\Audience\MailRouteStatus;
use App\Domain\Audience\RecipientProber;
use App\Domain\Audience\RecipientProbeResult;
use App\Domain\Audience\SmtpProbingPolicy;
use App\Domain\Audience\ValidationPipeline;
use App\Domain\Audience\ValidationReason;
use App\Domain\Audience\ValidationStatus;
use App\Domain\System\Entitlements\Entitlement;
use App\Domain\System\Enums\Subsystem;
use App\Domain\System\Flags\SubsystemFlagRegistry;
use App\Domain\Users\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Fakes\FakeMailRouteResolver;
use Tests\Feature\Fakes\RecordingProber;
use Tests\TestCase;

/**
 * Who decides whether the platform may ask a stranger's mail server a question.
 *
 * Two controls, and the property under test is that neither can be used to widen
 * the other's reach:
 *
 *     effective = deployment ceiling AND operator switch
 *
 * The deployment ceiling is the environment variable. The operator switch is a
 * persisted row in `system_settings`, and it exists so an incident can stop
 * outbound port 25 without editing a file and reloading the application — the
 * reason every other emergency control in this platform is database-backed.
 *
 * The composition is an AND rather than a preference, and that is the assertion
 * most worth protecting. An operator who enables the switch on a host where the
 * host has closed port 25 must be refused, not obeyed: the platform cannot tell
 * a mistake from an intention to try anyway, and "try anyway" is a decision for
 * the host, not for a browser session.
 *
 * The other property is that stopping the SMTP layer stops *only* the SMTP layer.
 * Syntax and mail-route validation are free, local or DNS-only, and an operator
 * turning off outbound conversations has not asked for confirmed-invalid verdicts
 * to disappear. They are the verdicts that cannot be wrong.
 */
class SmtpProbingControlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Off is the shipped state, so a test that forgets to state its position
        // is testing the default rather than an accident in the test suite.
        config()->set('sender.validation.smtp_probing', false);
    }

    public function test_probing_is_refused_when_the_deployment_forbids_it(): void
    {
        $this->operatorEnablesProbing();

        $policy = $this->policy();

        $this->assertFalse($policy->permittedByEnvironment());
        $this->assertFalse($policy->enabled());
        $this->assertStringContainsString('deployment', (string) $policy->blockedBecause());
    }

    public function test_an_operator_can_stop_probing_without_a_deployment(): void
    {
        config()->set('sender.validation.smtp_probing', true);

        $prober = RecordingProber::discriminating();
        $this->bindNetworkFakes($prober);

        $this->assertTrue($this->policy()->enabled(), 'precondition: both controls agree');

        $this->flags()->disable(Subsystem::SmtpValidation);

        $this->assertFalse($this->policy()->enabled());
        $this->assertStringContainsString('operator', (string) $this->policy()->blockedBecause());

        $result = $this->resolvePipeline()->validate('person@one.test', 1);

        $this->assertSame(ValidationStatus::Unknown, $result->outcome->status);
        $this->assertSame(ValidationReason::VerificationBlocked, $result->outcome->reason);
        $this->assertSame(
            [],
            $prober->probes,
            'a disabled subsystem must not open an SMTP conversation with anybody',
        );
    }

    public function test_the_switch_takes_effect_on_the_pipeline_the_container_builds(): void
    {
        config()->set('sender.validation.smtp_probing', true);

        $prober = RecordingProber::discriminating();
        $this->bindNetworkFakes($prober);

        $this->assertSame(
            ValidationStatus::LikelyActive,
            $this->resolvePipeline()->validate('person@one.test', 1)->outcome->status,
        );
        $this->assertNotSame([], $prober->probes);

        // The pipeline is a singleton, so this is the real risk the composition
        // has to survive: one process answering two different questions. Forcing
        // a fresh instance proves the decision is re-read per resolution rather
        // than captured once at boot.
        $this->flags()->disable(Subsystem::SmtpValidation);
        app()->forgetInstance(ValidationPipeline::class);

        $this->assertSame(
            ValidationStatus::Unknown,
            $this->resolvePipeline()->validate('second@one.test', 1)->outcome->status,
            'a pipeline resolved after the switch was thrown must not still probe',
        );
    }

    public function test_the_operator_cannot_widen_past_the_deployment_ceiling(): void
    {
        config()->set('sender.validation.smtp_probing', false);

        $prober = RecordingProber::discriminating();
        $this->bindNetworkFakes($prober);

        $this->operatorEnablesProbing();

        $this->resolvePipeline()->validate('person@one.test', 1);

        $this->assertSame(
            [],
            $prober->probes,
            'an enabled subsystem on a host that forbids it must stay silent',
        );
    }

    public function test_syntax_and_mail_route_validation_continue_while_probing_is_blocked(): void
    {
        config()->set('sender.validation.smtp_probing', false);

        $prober = RecordingProber::discriminating();
        $this->bindNetworkFakes($prober);

        $pipeline = $this->resolvePipeline();

        $malformed = $pipeline->validate('not an address at all', 1);
        $this->assertSame(ValidationStatus::ConfirmedInvalid, $malformed->outcome->status);
        $this->assertSame(ValidationReason::InvalidSyntax, $malformed->outcome->reason);

        $routeless = $pipeline->validate('person@nowhere.test', 1);
        $this->assertSame(ValidationStatus::ConfirmedInvalid, $routeless->outcome->status);
        $this->assertSame(ValidationReason::NoMailRoute, $routeless->outcome->reason);

        $routable = $pipeline->validate('person@one.test', 1);
        $this->assertSame(ValidationStatus::Unknown, $routable->outcome->status);
        $this->assertSame(ValidationReason::VerificationBlocked, $routable->outcome->reason);

        $this->assertSame(
            [],
            $prober->probes,
            'no layer of a blocked pipeline may still reach a mail server',
        );
    }

    public function test_the_switch_is_persisted_and_survives_a_cache_clear(): void
    {
        config()->set('sender.validation.smtp_probing', true);

        $this->flags()->disable(Subsystem::SmtpValidation);

        Cache::flush();
        app()->forgetInstance(SubsystemFlagRegistry::class);

        $this->assertFalse(
            $this->policy()->enabled(),
            'a kill switch that a cache clear silently resets is worse than no kill switch',
        );
    }

    public function test_entitlement_does_not_gate_the_switch(): void
    {
        config()->set('sender.validation.smtp_probing', true);

        // The application ships deny-by-default entitlement, so this test runs
        // with nothing entitled. A safety control over the host's outbound
        // traffic must not depend on a commercial decision, or an unentitled
        // installation would have no way to stop probing at all.
        $this->assertFalse(app(Entitlement::class)->allows('smtp_validation'));
        $this->assertTrue($this->policy()->enabled());
    }

    public function test_the_subsystems_page_explains_a_forbidden_deployment(): void
    {
        $admin = User::factory()->role(Role::SuperAdmin)->create();

        $this->actingAs($admin)
            ->get(route('admin.system.subsystems'))
            ->assertOk()
            ->assertSee('Recipient SMTP validation')
            ->assertSee('no host capability applies')
            ->assertSee('This deployment does not permit it');
    }

    public function test_a_permitted_deployment_offers_the_switch_normally(): void
    {
        config()->set('sender.validation.smtp_probing', true);

        $admin = User::factory()->role(Role::SuperAdmin)->create();

        $this->actingAs($admin)
            ->get(route('admin.system.subsystems'))
            ->assertOk()
            ->assertSee('Recipient SMTP validation')
            ->assertDontSee('This deployment does not permit it');
    }

    public function test_the_probe_still_answers_in_the_platform_vocabulary_when_blocked(): void
    {
        config()->set('sender.validation.smtp_probing', false);

        $prober = RecordingProber::always(RecipientProbeResult::replied(250, '5.1.1'));
        $this->bindNetworkFakes($prober);

        // The 5.1.1 reply would be a confirmed-invalid verdict. With the layer
        // off, the platform must not use evidence it was told not to collect.
        $this->assertSame(
            ValidationStatus::Unknown,
            $this->resolvePipeline()->validate('person@one.test', 1)->outcome->status,
        );
    }

    private function operatorEnablesProbing(): void
    {
        $this->flags()->enable(Subsystem::SmtpValidation);
    }

    private function policy(): SmtpProbingPolicy
    {
        return SmtpProbingPolicy::fromEnvironment($this->flags());
    }

    private function flags(): SubsystemFlagRegistry
    {
        return app(SubsystemFlagRegistry::class);
    }

    private function bindNetworkFakes(RecordingProber $prober): void
    {
        app()->instance(RecipientProber::class, $prober);
        app()->instance(MailRouteResolver::class, new FakeMailRouteResolver(
            statuses: ['nowhere.test' => MailRouteStatus::NoRoute],
            default: MailRouteStatus::HasRoute,
        ));
    }

    private function resolvePipeline(): ValidationPipeline
    {
        app()->forgetInstance(ValidationPipeline::class);

        return app(ValidationPipeline::class);
    }
}
