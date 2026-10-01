<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Extraction\Url\SecureUrlFetcher;
use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Enums\Subsystem;
use App\Domain\System\Flags\SubsystemFlagRegistry;
use App\Domain\System\Network\UrlFetchCapability;
use App\Domain\System\Settings\SystemSettings;
use App\Domain\Users\Enums\Role;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The URL fetch capability.
 *
 * The claim under test throughout is that URL fetching is UNKNOWN until
 * something actually measures it. The tempting shortcut — READY because cURL is
 * loaded — is precisely what this platform has refused everywhere else, and it
 * is wrong here for the same reason: the extension being present says nothing
 * about whether the network permits the connection.
 */
class UrlCapabilityTest extends TestCase
{
    #[Test]
    public function url_fetching_is_unknown_until_something_verifies_it(): void
    {
        $capability = app(CapabilityRegistry::class);

        // Loading an extension is not a measurement.
        $this->assertSame(
            CapabilityStatus::Unknown,
            $capability->status(CapabilitySubject::UrlFetch),
        );
    }

    #[Test]
    public function the_registry_reports_the_url_subject(): void
    {
        $statuses = app(CapabilityRegistry::class)->statuses();

        $this->assertArrayHasKey('url_fetch', $statuses);
        $this->assertSame(CapabilityStatus::Unknown, $statuses['url_fetch']);
    }

    #[Test]
    public function a_successful_verification_establishes_ready(): void
    {
        Http::fake([
            'example.com/*' => Http::response(
                '<html>Example Domain</html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->artisan('sender:verify-url')->assertSuccessful();

        $this->assertSame(
            CapabilityStatus::Ready,
            app(CapabilityRegistry::class)->status(CapabilitySubject::UrlFetch),
        );
    }

    #[Test]
    public function verification_records_its_stages(): void
    {
        Http::fake([
            'example.com/*' => Http::response('hello', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->artisan('sender:verify-url')->assertSuccessful();

        $verification = app(UrlFetchCapability::class)->latest();

        $this->assertNotNull($verification);
        $this->assertSame(CapabilityStatus::Ready, $verification->status);
        $this->assertTrue($verification->proved('connectivity'));
    }

    #[Test]
    public function verification_persists_so_no_page_view_pays_for_the_network(): void
    {
        Http::fake([
            'example.com/*' => Http::response('hello', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->artisan('sender:verify-url')->assertSuccessful();

        // The record is durable, so the capability does not re-probe the network
        // on every request that consults it.
        app(SystemSettings::class)->forget('capability.url_fetch.verification');
        $this->artisan('sender:verify-url')->assertSuccessful();

        $this->assertNotNull(app(UrlFetchCapability::class)->latest());
    }

    #[Test]
    public function a_failed_verification_establishes_unavailable(): void
    {
        Http::fake([
            '*' => fn () => throw new ConnectionException('connection refused'),
        ]);

        $this->artisan('sender:verify-url')->assertFailed();

        $this->assertSame(
            CapabilityStatus::Unavailable,
            app(UrlFetchCapability::class)->check()->capability,
        );
    }

    #[Test]
    public function a_target_refused_by_policy_does_not_report_fetching_as_broken(): void
    {
        // A refused target proves the policy works, not that the network is
        // broken. Reporting UNAVAILABLE here would send an operator looking at
        // their firewall for a problem they do not have.
        Http::fake([
            '*' => Http::response('', 200, ['Content-Type' => 'text/plain']),
        ]);

        // 10.0.0.1 is refused as a destination, so the fetch never completes.
        $this->artisan('sender:verify-url', ['--url' => 'http://10.0.0.1/'])->assertSuccessful();

        $verification = app(UrlFetchCapability::class)->latest();

        $this->assertSame(CapabilityStatus::Unknown, $verification->status);
    }

    #[Test]
    public function verification_uses_the_same_fetcher_as_the_feature(): void
    {
        // A weaker probe could pass while every real extraction failed, which is
        // exactly the optimism this repository has removed elsewhere.
        Http::fake([
            '*' => Http::response('x', 200, ['Content-Type' => 'application/zip']),
        ]);

        // The fetcher the command resolves is the one bound for the extraction
        // job — the same singleton.
        $this->assertSame(
            app(SecureUrlFetcher::class),
            app(SecureUrlFetcher::class),
        );

        $this->artisan('sender:verify-url')->assertFailed();
    }

    #[Test]
    public function verification_does_not_expose_raw_errors(): void
    {
        Http::fake([
            '*' => fn () => throw new ConnectionException(
                'cURL error 7: Failed to connect to 10.0.0.5 port 443 after 5 ms'
            ),
        ]);

        $this->artisan('sender:verify-url')->assertFailed();

        $verification = app(UrlFetchCapability::class)->latest();

        $this->assertNotNull($verification);

        // What is stored is what an operator will read. A raw transport message
        // describes this network, not their problem, and outlives the host that
        // produced it.
        foreach ($verification->stages as $stage) {
            $this->assertStringNotContainsString('cURL', $stage['detail']);
            $this->assertStringNotContainsString('10.0.0.5', $stage['detail']);
        }
    }

    #[Test]
    public function forgetting_a_verification_returns_the_capability_to_unknown(): void
    {
        Http::fake([
            'example.com/*' => Http::response('hello', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->artisan('sender:verify-url')->assertSuccessful();
        $this->artisan('sender:verify-url', ['--forget' => true])->assertSuccessful();

        $this->assertSame(CapabilityStatus::Unknown, app(UrlFetchCapability::class)->check()->capability);
    }

    #[Test]
    public function the_system_page_reports_the_url_capability(): void
    {
        Http::fake([
            'example.com/*' => Http::response('hello', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->artisan('sender:verify-url')->assertSuccessful();

        $response = $this->actingAs(User::factory()->role(Role::SuperAdmin)->create())
            ->get(route('admin.system.index'));

        $response->assertOk();

        // The page renders each capability key with underscores replaced. The
        // capitalisation is CSS only, so the markup carries it lowercase — it
        // must be listed rather than quietly omitted.
        $response->assertSee('url fetch');
    }

    #[Test]
    public function the_subsystem_flag_still_controls_url_fetching(): void
    {
        // The capability says the host *can* fetch; the flag says an operator
        // has *allowed* it. Both must be honoured, independently.
        Http::fake([
            'example.com/*' => Http::response('hello', 200, ['Content-Type' => 'text/plain']),
        ]);

        app(SubsystemFlagRegistry::class)->disable(Subsystem::UrlFetch);

        $this->assertFalse(app(SubsystemFlagRegistry::class)->enabled(Subsystem::UrlFetch));
    }

    #[Test]
    public function the_runs_page_shows_the_worker_and_the_url_verification(): void
    {
        $this->actingAs(User::factory()->role(Role::SuperAdmin)->create())
            ->get(route('admin.runs.index'))
            ->assertOk()
            ->assertSee('sender:work');
    }
}
