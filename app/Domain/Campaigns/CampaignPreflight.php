<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Audience\AudienceEligibility;
use App\Domain\Mail\DeliveryReadiness;
use App\Domain\Mail\SmtpAccount;
use App\Domain\Mail\SmtpAccountStatus;
use App\Domain\Mail\TrafficMode;
use App\Domain\Templates\MessageRenderer;
use App\Domain\Templates\PersonalisationToken;
use App\Domain\Templates\Template;
use Illuminate\Support\Collection;

/**
 * Everything that has to be true before a campaign may send, answered once.
 *
 * This is the service the whole product's honesty rests on, and it exists as one
 * class for one reason: **the page and the send must not disagree.** A campaign
 * builder that rendered its own inline `if` statements would produce a list of
 * checks the start action does not enforce — or worse, enforces a *different*
 * list. So the create page, the detail page and {@see CampaignLauncher} all call
 * this, and the launch path calls it again on the server at the moment of
 * starting, because a result rendered in a browser is a claim, not a decision.
 *
 * The vocabulary is the platform's existing PASS / WARN / BLOCK / UNKNOWN, and
 * UNKNOWN is never treated as a pass. A question this platform cannot answer is
 * reported as unanswered rather than resolved in the customer's favour, which is
 * the same rule {@see DeliveryReadiness} follows and for the
 * same reason.
 *
 * Two things it deliberately does not do:
 *
 *   - It does not score. No percentage, no "87/100, ready to send". Every figure
 *     inbox placement depends on is one this application cannot observe.
 *   - It does not offer an alternative transport. A campaign names one transport;
 *     if that transport is failing, the answer is that this campaign cannot send,
 *     not that another account could be tried instead. Rotation to work around a
 *     provider's judgement is not a feature this platform has, and a preflight
 *     that quietly presented a second account would be where it started.
 */
class CampaignPreflight
{
    /**
     * The floor this application will not go below, whatever a campaign asks for.
     */
    private const MINIMUM_INTERVAL_SECONDS = 30;

    /**
     * Ceilings on what a campaign may request, so one campaign cannot occupy a
     * worker indefinitely or pace itself into a provider's rate limit.
     */
    private const MAXIMUM_INTERVAL_SECONDS = 3600;

    private const MINIMUM_BATCH_SIZE = 1;

    private const MAXIMUM_BATCH_SIZE = 100;

    public function __construct(
        private readonly DeliveryReadiness $readiness,
        private readonly AudienceEligibility $eligibility,
        private readonly MessageRenderer $renderer,
    ) {}

    /**
     * Answer every check for one campaign.
     *
     * @return Collection<int, PreflightCheck>
     */
    public function checksFor(Campaign $campaign): Collection
    {
        return collect([
            $this->named($campaign),
            $this->template($campaign),
            $this->templateReady($campaign),
            $this->placeholders($campaign),
            $this->unsubscribe($campaign),
            $this->transport($campaign),
            $this->transportVerified($campaign),
            $this->senderIdentity($campaign),
            $this->transportReadiness($campaign),
            $this->audience($campaign),
            $this->suppression($campaign),
            $this->consent($campaign),
            $this->interval($campaign),
            $this->batchSize($campaign),
        ]);
    }

    /**
     * The whole report, for a page to render.
     */
    public function reportFor(Campaign $campaign): CampaignPreflightReport
    {
        return CampaignPreflightReport::fromChecks($this->checksFor($campaign));
    }

    /**
     * The interval this campaign will actually be paced at.
     *
     * Not the configured value: the floor is applied here, where the send is
     * paced, rather than only in a form rule — because a campaign can be changed
     * by more than one route, and a value only checked in a controller is a value
     * that can be wrong.
     */
    public function effectiveIntervalSeconds(Campaign $campaign): int
    {
        return max(
            self::MINIMUM_INTERVAL_SECONDS,
            (int) config('sender.sending.minimum_interval_seconds', self::MINIMUM_INTERVAL_SECONDS),
            (int) $campaign->rate_interval_seconds,
        );
    }

    /**
     * How many messages one worker run may send for this campaign.
     *
     * The smallest of three ceilings, because all three are ceilings: what the
     * customer configured, what the deployment allows per run, and what keeps one
     * campaign inside a worker budget.
     */
    public function effectiveBatchSize(Campaign $campaign): int
    {
        return (int) max(self::MINIMUM_BATCH_SIZE, min(
            (int) $campaign->worker_batch_size,
            (int) config('sender.sending.max_per_run', self::MAXIMUM_BATCH_SIZE),
            self::MAXIMUM_BATCH_SIZE,
        ));
    }

    private function named(Campaign $campaign): PreflightCheck
    {
        return trim((string) $campaign->name) === ''
            ? PreflightCheck::block(
                'campaign.name',
                'Campaign name',
                'The campaign has no name, so there is nothing to recognise it by on this page or in the history.',
            )
            : PreflightCheck::pass('campaign.name', 'Campaign name', 'The campaign is named, so it can be found and reported on.');
    }

    private function template(Campaign $campaign): PreflightCheck
    {
        if ($campaign->hasLaunched()) {
            // A launched campaign answers with its frozen copy. Reading the
            // template now would report on content this campaign will never send.
            return PreflightCheck::pass(
                'campaign.template',
                'Message template',
                'This campaign is already frozen at version '.$campaign->template_version
                    .'. Later edits to the template cannot change what it sends.',
            );
        }

        $template = $campaign->template;

        if (! $template instanceof Template) {
            return PreflightCheck::block(
                'campaign.template',
                'Message template',
                'No template is selected. A campaign sends the words a template holds, so it needs one.',
            );
        }

        if ((int) $template->user_id !== (int) $campaign->user_id) {
            // Should be unreachable: the controller scopes the lookup. Reported
            // rather than ignored, because if it ever happens it is a bug that
            // would otherwise put one tenant's content into another's mail.
            return PreflightCheck::block(
                'campaign.template',
                'Message template',
                'The selected template does not belong to this account.',
            );
        }

        return PreflightCheck::pass(
            'campaign.template',
            'Message template',
            'Using "'.$template->name.'" at version '.$template->version.'.',
        );
    }

    private function templateReady(Campaign $campaign): PreflightCheck
    {
        $template = $campaign->template;

        if (! $template instanceof Template || $campaign->hasLaunched()) {
            return PreflightCheck::pass(
                'campaign.template.ready',
                'Template completeness',
                'The message content is held by the campaign itself and cannot change while it sends.',
            );
        }

        $blockers = $template->blockers($this->renderer);

        return $blockers === []
            ? PreflightCheck::pass(
                'campaign.template.ready',
                'Template completeness',
                'The template holds a subject and both message bodies.',
            )
            : PreflightCheck::block(
                'campaign.template.ready',
                'Template completeness',
                implode(' ', $blockers),
            );
    }

    private function placeholders(Campaign $campaign): PreflightCheck
    {
        $template = $campaign->template;

        $content = $campaign->hasLaunched() || ! $template instanceof Template
            ? $this->snapshotContent($campaign)
            : $template->subject.' '.$template->html_body;

        $unknown = $this->renderer->unknownTokens($content);

        return $unknown === []
            ? PreflightCheck::pass(
                'campaign.template.placeholders',
                'Personalisation fields',
                'Every placeholder in the message is one this platform fills in.',
            )
            : PreflightCheck::block(
                'campaign.template.placeholders',
                'Personalisation fields',
                'The placeholder '.implode(', ', array_map(
                    static fn (string $token): string => '{{'.$token.'}}',
                    $unknown,
                )).' is not supported, and would be sent to recipients exactly as written.',
            );
    }

    private function unsubscribe(Campaign $campaign): PreflightCheck
    {
        $template = $campaign->template;

        $content = $campaign->hasLaunched() || ! $template instanceof Template
            ? $this->snapshotContent($campaign)
            : $template->html_body.' '.$template->text_body;

        $token = PersonalisationToken::UnsubscribeUrl->placeholder();

        return str_contains($content, $token)
            ? PreflightCheck::pass(
                'campaign.unsubscribe',
                'Unsubscribe mechanism',
                'The message includes '.$token.', which is replaced per recipient with their own signed link.',
            )
            : PreflightCheck::block(
                'campaign.unsubscribe',
                'Unsubscribe mechanism',
                'The message has no '.$token.'. A campaign that cannot tell a recipient how to stop being '
                    .'contacted must not be sent, and the platform supplies that link per recipient.',
            );
    }

    private function transport(Campaign $campaign): PreflightCheck
    {
        $account = $campaign->smtpAccount;

        if (! $account instanceof SmtpAccount) {
            return PreflightCheck::block(
                'campaign.transport',
                'Sending transport',
                'No sending transport is selected. A campaign sends through one named account and never '
                    .'another, so it needs one.',
            );
        }

        // The account must belong to this tenant. An operator-assigned transport
        // passes because its `user_id` *is* the tenant; anything else is refused,
        // and refused rather than silently re-pointed at one that works.
        if ((int) $account->user_id !== (int) $campaign->user_id) {
            return PreflightCheck::block(
                'campaign.transport',
                'Sending transport',
                'The selected transport belongs to another account.',
            );
        }

        return PreflightCheck::pass(
            'campaign.transport',
            'Sending transport',
            'Sending as '.$account->label.' through '.$account->host.'.',
        );
    }

    private function transportVerified(Campaign $campaign): PreflightCheck
    {
        $account = $campaign->smtpAccount;

        if (! $account instanceof SmtpAccount) {
            return PreflightCheck::unknown(
                'campaign.transport.verified',
                'Transport verification',
                'No transport is selected, so there is nothing to have verified.',
            );
        }

        return match ($account->effectiveStatus()) {
            SmtpAccountStatus::Ready => PreflightCheck::pass(
                'campaign.transport.verified',
                'Transport verification',
                'This transport was verified and the result is still current.',
            ),
            SmtpAccountStatus::Stale => PreflightCheck::block(
                'campaign.transport.verified',
                'Transport verification',
                'The verification has expired. Verify it again before sending; a server can change underneath '
                    .'a stored credential at any time.',
            ),
            SmtpAccountStatus::Unverified => PreflightCheck::block(
                'campaign.transport.verified',
                'Transport verification',
                'This transport has never been verified. Send it a test message and confirm it arrives first.',
            ),
            SmtpAccountStatus::Disabled => PreflightCheck::block(
                'campaign.transport.verified',
                'Transport verification',
                'This transport is switched off because the server refused it.',
            ),
            default => PreflightCheck::block(
                'campaign.transport.verified',
                'Transport verification',
                'The last attempt to use this transport failed. Fix it, or choose another — this campaign will '
                    .'not quietly switch transports for you.',
            ),
        };
    }

    private function senderIdentity(Campaign $campaign): PreflightCheck
    {
        $account = $campaign->smtpAccount;

        if (! $account instanceof SmtpAccount) {
            return PreflightCheck::unknown(
                'campaign.transport.identity',
                'Sender identity',
                'No transport is selected, so there is no sender identity to check.',
            );
        }

        $from = trim((string) $account->from_address);

        if ($from === '') {
            return PreflightCheck::block(
                'campaign.transport.identity',
                'Sender identity',
                'This transport has no From address, so messages would have no sender.',
            );
        }

        // The same rule the transport form enforces, re-checked here because the
        // consequence differs: there it is a field the customer fills in, and here
        // it is mail leaving under somebody else's identity.
        if ($account->auth_mode->requiresSecret() && $account->username !== null
            && mb_strtolower((string) $account->username) !== mb_strtolower($from)) {
            return PreflightCheck::block(
                'campaign.transport.identity',
                'Sender identity',
                'The From address is not the address this transport authenticated as. Sending as an address '
                    .'the transport did not authenticate for is not permitted.',
            );
        }

        return PreflightCheck::pass(
            'campaign.transport.identity',
            'Sender identity',
            'Messages will be sent as '.$from.', which is the identity this transport authenticates as.',
        );
    }

    private function transportReadiness(Campaign $campaign): PreflightCheck
    {
        $account = $campaign->smtpAccount;

        if (! $account instanceof SmtpAccount) {
            return PreflightCheck::unknown(
                'campaign.transport.readiness',
                'Deliverability readiness',
                'No transport is selected.',
            );
        }

        $report = $this->readiness->for($account);

        $blockers = $report->blockers(TrafficMode::BulkMarketing);
        $warnings = $report->warnings(TrafficMode::BulkMarketing);

        if ($blockers !== []) {
            return PreflightCheck::block(
                'campaign.transport.readiness',
                'Deliverability readiness',
                $blockers[0]->detail.' Fix this before sending a campaign.',
            );
        }

        if ($warnings !== []) {
            return PreflightCheck::warn(
                'campaign.transport.readiness',
                'Deliverability readiness',
                'Ready to send, with '.$warnings[0]->detail,
            );
        }

        return PreflightCheck::pass(
            'campaign.transport.readiness',
            'Deliverability readiness',
            $report->verdict(TrafficMode::BulkMarketing),
        );
    }

    private function audience(Campaign $campaign): PreflightCheck
    {
        if ($campaign->list_id === null) {
            return PreflightCheck::block(
                'campaign.audience',
                'Audience',
                'No list is selected. A campaign sends to a frozen copy of one list\'s eligible contacts.',
            );
        }

        $list = $campaign->list;

        if ($list === null) {
            return PreflightCheck::block(
                'campaign.audience',
                'Audience',
                'The selected list no longer exists. Choose another.',
            );
        }

        $eligible = $this->eligibility->countFor((int) $campaign->user_id, $list);

        return $eligible === 0
            ? PreflightCheck::block(
                'campaign.audience',
                'Audience',
                'No contact on "'.$list->name.'" can be contacted right now. Each one must be likely active, '
                    .'must have evidence they agreed, and must not have asked not to be contacted.',
            )
            : PreflightCheck::pass(
                'campaign.audience',
                'Audience',
                number_format($eligible).' contact'.($eligible === 1 ? '' : 's')
                    .' on "'.$list->name.'" can be contacted right now.',
            );
    }

    private function suppression(Campaign $campaign): PreflightCheck
    {
        if ($campaign->list_id === null || $campaign->list === null) {
            return PreflightCheck::unknown(
                'campaign.suppression',
                'Suppression',
                'No list is selected, so there is nothing to exclude.',
            );
        }

        $suppressed = (int) $this->counts($campaign)['suppressed'];

        return $suppressed === 0
            ? PreflightCheck::pass(
                'campaign.suppression',
                'Suppression',
                'Nobody on this list has asked not to be contacted.',
            )
            : PreflightCheck::warn(
                'campaign.suppression',
                'Suppression',
                number_format($suppressed).' contact'.($suppressed === 1 ? '' : 's')
                    .' on this list have asked not to be contacted and will be excluded. They stay on the '
                    .'list; this campaign will not send to them.',
            );
    }

    private function consent(Campaign $campaign): PreflightCheck
    {
        if ($campaign->list_id === null || $campaign->list === null) {
            return PreflightCheck::unknown(
                'campaign.consent',
                'Consent evidence',
                'No list is selected, so there is nobody whose consent to check.',
            );
        }

        $without = (int) $this->counts($campaign)['no_consent'];

        return $without === 0
            ? PreflightCheck::pass(
                'campaign.consent',
                'Consent evidence',
                'Every contactable person on this list has a record that they agreed to be contacted.',
            )
            : PreflightCheck::warn(
                'campaign.consent',
                'Consent evidence',
                number_format($without).' contact'.($without === 1 ? '' : 's')
                    .' on this list have no evidence of having agreed, and will be excluded. '
                    .'"You told us they agreed" is recorded but does not make anybody contactable.',
            );
    }

    private function interval(Campaign $campaign): PreflightCheck
    {
        $configured = (int) $campaign->rate_interval_seconds;

        if ($configured < self::MINIMUM_INTERVAL_SECONDS) {
            return PreflightCheck::warn(
                'campaign.rate',
                'Minimum send interval',
                'Raised to '.$this->effectiveIntervalSeconds($campaign).' seconds. This installation does not '
                    .'send faster than that, whatever a campaign asks for.',
            );
        }

        if ($configured > self::MAXIMUM_INTERVAL_SECONDS) {
            return PreflightCheck::block(
                'campaign.rate',
                'Minimum send interval',
                'An interval above '.self::MAXIMUM_INTERVAL_SECONDS.' seconds is refused: a campaign paced that '
                    .'slowly is indistinguishable from one that is not running.',
            );
        }

        return PreflightCheck::pass(
            'campaign.rate',
            'Minimum send interval',
            'At most one message every '.$this->effectiveIntervalSeconds($campaign).' seconds, and only when '
                .'the hosting scheduler gives the worker an opportunity to run.',
        );
    }

    private function batchSize(Campaign $campaign): PreflightCheck
    {
        $configured = (int) $campaign->worker_batch_size;

        if ($configured < self::MINIMUM_BATCH_SIZE) {
            return PreflightCheck::block(
                'campaign.batch',
                'Messages per worker run',
                'A worker run must be allowed at least one message, or the campaign can never progress.',
            );
        }

        $effective = $this->effectiveBatchSize($campaign);

        return $configured > $effective
            ? PreflightCheck::warn(
                'campaign.batch',
                'Messages per worker run',
                'Capped at '.$effective.' per run, which is what this installation allows one worker to send '
                    .'before it must exit.',
            )
            : PreflightCheck::pass(
                'campaign.batch',
                'Messages per worker run',
                'Up to '.$effective.' message'.($effective === 1 ? '' : 's')
                    .' per worker run, bounded by how long the worker may run for.',
            );
    }

    /**
     * The audience breakdown, counted once per report rather than once per check.
     *
     * Suppression and consent both need it, and two identical grouped queries in
     * one request is the kind of duplication that later diverges — one check reading
     * a different figure from the other.
     *
     * @return array<string, int>
     */
    private function counts(Campaign $campaign): array
    {
        return $this->eligibility->breakdownFor((int) $campaign->user_id, $campaign->list);
    }

    /**
     * Everything a campaign will send, as one searchable string.
     */
    private function snapshotContent(Campaign $campaign): string
    {
        return implode(' ', [
            (string) $campaign->subject_snapshot,
            (string) $campaign->preheader_snapshot,
            (string) $campaign->html_body_snapshot,
            (string) $campaign->text_body_snapshot,
        ]);
    }
}
