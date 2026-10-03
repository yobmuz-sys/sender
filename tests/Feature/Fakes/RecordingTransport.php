<?php

declare(strict_types=1);

namespace Tests\Feature\Fakes;

use App\Domain\Campaigns\MessageTransport;
use App\Domain\Campaigns\TransportResult;
use App\Domain\Mail\CampaignMessage;
use App\Domain\Mail\DeliveryOutcome;
use App\Domain\Mail\SmtpTransportDefinition;

/**
 * A transport that records what it was asked to send and answers what the test
 * told it to.
 *
 * Exists so the campaign engine can be tested without a mail server. The
 * alternative — pointing a test at a real SMTP host — produces a suite that is
 * slow, needs credentials and is skipped whenever the network is unavailable,
 * which means the sending logic is precisely the part that stops being tested.
 *
 * What it records is the *submitted* message: the rendered subject, both bodies,
 * the From, the recipient and the unsubscribe URL. That is what lets a test prove
 * the campaign sent its frozen snapshot rather than a template edited since, and
 * that the recipient's own link went in rather than a shared one.
 */
final class RecordingTransport implements MessageTransport
{
    /**
     * @var list<array{recipient: string, from: string, subject: string, html: string|null, text: string|null, unsubscribe: string, host: string}>
     */
    public array $submitted = [];

    /**
     * Queued outcomes, consumed in order. The last one repeats, so a test can say
     * "the next two fail and then everything is accepted" without counting.
     *
     * @var list<array{outcome: DeliveryOutcome, code: string|null, detail: string|null}>
     */
    private array $answers = [];

    public function __construct()
    {
        $this->answers = [['outcome' => DeliveryOutcome::Accepted, 'code' => null, 'detail' => null]];
    }

    /**
     * Answer every submission from now on with something other than acceptance.
     *
     * The fallback, not the next one: a test that wants "the next two attempts are
     * throttled" says that with {@see self::answerNext()} twice, and a test that
     * wants "this transport is refusing everything" says it here once.
     */
    public function answering(DeliveryOutcome $outcome, ?string $code = null, ?string $detail = null): self
    {
        $this->answers = [array_merge(['outcome' => $outcome, 'code' => $code, 'detail' => $detail], $this->answers)];

        return $this;
    }

    /**
     * Answer exactly one submission with something other than acceptance.
     */
    public function answerNext(DeliveryOutcome $outcome, ?string $code = null, ?string $detail = null): self
    {
        array_unshift($this->answers, ['outcome' => $outcome, 'code' => $code, 'detail' => $detail]);

        return $this;
    }

    public function submit(
        SmtpTransportDefinition $transport,
        CampaignMessage $message,
        string $from,
        string $recipient,
        string $unsubscribeUrl,
    ): TransportResult {
        $this->submitted[] = [
            'recipient' => $recipient,
            'from' => $from,
            'subject' => $message->subject,
            'html' => $message->html,
            'text' => $message->text,
            'unsubscribe' => $unsubscribeUrl,
            'host' => $transport->host,
        ];

        $answer = array_shift($this->answers) ?? ['outcome' => DeliveryOutcome::Accepted, 'code' => null, 'detail' => null];

        return TransportResult::failed(
            $answer['outcome'],
            'test-message-'.(count($this->submitted)).'@example.test',
            $answer['code'],
            $answer['detail'],
        );
    }

    public function submissions(): int
    {
        return count($this->submitted);
    }
}
