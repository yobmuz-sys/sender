<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Audience\SuppressionList;
use App\Domain\Audience\UnsubscribeLink;
use App\Domain\Mail\CampaignMessage;
use App\Domain\Templates\MessageRenderer;
use App\Models\Contact;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Sends one message to one recipient, and records exactly what happened.
 *
 * Every send in a campaign goes through here, which is what makes three properties
 * true everywhere rather than somewhere:
 *
 *   - **The message comes from the campaign's snapshot**, never from the template.
 *     The template may have been edited four times since launch; this campaign
 *     sends what it froze.
 *   - **Suppression is re-checked immediately before submission.** The audience
 *     snapshot may be hours or weeks old, and somebody can unsubscribe in between.
 *     Checking only at snapshot time would mean the next campaign in the queue
 *     overtakes an unsubscribe the moment it is clicked — the one failure this
 *     platform considers unacceptable, because it costs somebody a message they
 *     asked not to receive.
 *   - **Values are escaped, never interpreted.** The renderer substitutes a
 *     whitelist and escapes into HTML; nothing a recipient's name contains can
 *     become markup, and no body is ever compiled.
 *
 * A failure to send is recorded, not thrown, because the runner has to decide
 * whether to stop the campaign or move on to the next recipient, and that decision
 * needs the outcome in hand.
 */
class CampaignSender
{
    public function __construct(
        private readonly MessageTransport $transport,
        private readonly MessageRenderer $renderer,
        private readonly UnsubscribeLink $links,
        private readonly SuppressionList $suppressions,
        private readonly RetryPolicy $retries,
        private readonly LogicalMessageId $messageIds,
    ) {}

    /**
     * Attempt one send, and leave the recipient row and the attempt history saying
     * what happened.
     */
    public function send(Campaign $campaign, CampaignRecipient $recipient): AttemptResult
    {
        $startedAt = now();
        $attemptNumber = ((int) $recipient->attempts) + 1;

        $contact = $recipient->contact;

        if (! $contact instanceof Contact) {
            // Deleted between the snapshot and now. There is nothing to say to
            // them, and no decision was made — which is why this is `skipped` and
            // not `blocked`.
            $message = 'The contact was deleted before their turn came up.';

            $recipient->markNotAttempted(AttemptResult::Skipped, $message);

            return $this->write(
                $recipient,
                $attemptNumber,
                $startedAt,
                AttemptResult::Skipped,
                null,
                $message,
            );
        }

        if ($this->suppressions->isSuppressed($contact)) {
            $message = 'This contact asked not to be contacted before their message was due.';

            $recipient->markNotAttempted(AttemptResult::Blocked, $message);

            return $this->write(
                $recipient,
                $attemptNumber,
                $startedAt,
                AttemptResult::Blocked,
                null,
                'Blocked before submission: '.$message,
            );
        }

        try {
            $outcome = $this->submit($campaign, $recipient, $contact);
        } catch (Throwable $exception) {
            // A throw from here is a fault in this platform rather than a server's
            // opinion, so it is recorded as a transport failure and stops the
            // campaign: continuing after an exception of unknown origin would be
            // sending the rest of the list through an engine that is broken.
            $recipient->markUnsent(AttemptResult::TransportFailure, $exception->getMessage());

            return $this->write(
                $recipient,
                $attemptNumber,
                $startedAt,
                AttemptResult::TransportFailure,
                null,
                $exception->getMessage(),
            );
        }

        $result = $outcome->attemptResult();

        if ($result === AttemptResult::Accepted) {
            $recipient->markSent($outcome->messageId);

            return $this->write(
                $recipient,
                $attemptNumber,
                $startedAt,
                $result,
                null,
                null,
                $outcome->messageId,
                $recipient->message_id,
            );
        }

        $message = (string) $outcome->detail;

        $recipient->markUnsent(
            $result,
            $message,
            $outcome->code,
            $this->retries->delayFor($result, $attemptNumber),
            $outcome->messageId,
        );

        return $this->write(
            $recipient,
            $attemptNumber,
            $startedAt,
            $result,
            $outcome->code,
            $message,
            $outcome->messageId,
            $recipient->message_id,
        );
    }

    /**
     * Render and submit, using only the campaign's frozen content.
     *
     * The identifier is resolved here, before the transport is called, so that what
     * goes on the wire is a value the database already holds. Assigning it inside
     * the transport would mean a submission could carry an identifier that a crash
     * moments later would erase, and a bounce for that message would then correlate
     * to nothing at all.
     */
    private function submit(Campaign $campaign, CampaignRecipient $recipient, Contact $contact): TransportResult
    {
        $account = $campaign->smtpAccount;

        if ($account === null) {
            // The transport was deleted after launch. Refused rather than
            // substituted: this campaign sends through one named account or not at
            // all, and quietly using another would mean a customer's campaign left
            // from an address they did not choose and did not check.
            throw new RuntimeException(
                'The transport this campaign was launched with no longer exists, so there is nothing to send through.',
            );
        }

        $transport = $account->transport();
        $message = $this->message($campaign, $contact);
        $from = (string) ($transport->permittedFromAddress() ?? $account->from_address);

        return $this->transport->submit(
            $transport,
            $message,
            $from,
            (string) $contact->email,
            $this->links->url($contact),
            $recipient->ensureMessageId($this->messageIds),
        );
    }

    /**
     * Compose this recipient's message from the campaign's snapshot.
     *
     * All three placeholders are supplied explicitly, including `first_name` as an
     * empty string when no name is known. Leaving it unset would make the renderer
     * leave the placeholder in place, and a recipient's inbox is the last place an
     * unresolved `{{first_name}}` should appear. The contact model holds no name —
     * a validated address proves a mailbox exists, not a person's name — so an
     * empty value is the honest one.
     */
    public function message(Campaign $campaign, Contact $contact): CampaignMessage
    {
        $values = [
            'email' => (string) $contact->email,
            'first_name' => '',
            'unsubscribe_url' => $this->links->url($contact),
        ];

        return CampaignMessage::compose(
            $this->renderer->substitute((string) $campaign->subject_snapshot, $values),
            $this->renderer->substitute((string) $campaign->html_body_snapshot, $values),
            $this->renderer->substitute((string) $campaign->text_body_snapshot, $values, false),
        );
    }

    /**
     * Write the attempt row.
     *
     * After the recipient row has been updated, never before: a crash between the
     * two then leaves a recipient whose state is already correct and an attempt row
     * missing, rather than an attempt claiming a send the recipient row denies.
     * Missing history is recoverable by re-running; a history row that lies about
     * what happened to a person is not.
     */
    private function write(
        CampaignRecipient $recipient,
        int $attemptNumber,
        Carbon $startedAt,
        AttemptResult $result,
        ?string $code = null,
        ?string $detail = null,
        ?string $providerMessageId = null,
        ?string $submittedMessageId = null,
    ): AttemptResult {
        DeliveryAttempt::query()->create([
            'campaign_recipient_id' => $recipient->id,
            'attempt_number' => $attemptNumber,
            'started_at' => $startedAt,
            'finished_at' => now(),
            'result' => $result->value,
            'smtp_code' => $code,
            'smtp_response' => $detail,
            'provider_message_id' => $providerMessageId,

            // What this attempt put on the wire, as opposed to what the server
            // called it, and passed in rather than read off the recipient because the
            // recipient has one even when nothing was ever sent — a blocked recipient
            // is assigned at launch like any other. Repeating it here would make
            // every attempt row look like a submission, including the ones recorded
            // precisely because nothing was submitted.
            //
            // Null for a deleted contact, a suppressed one, or a fault inside this
            // platform: no message was submitted, so there was no identifier to
            // record. The same value on every attempt that did submit, which is the
            // property Stage 5D's correlation rests on.
            'message_id' => $submittedMessageId,
        ]);

        return $result;
    }
}
