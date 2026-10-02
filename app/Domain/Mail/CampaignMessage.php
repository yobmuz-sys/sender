<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use Illuminate\Support\Str;

/**
 * One campaign message, before it is sent.
 *
 * A value object, not a record. There is no table and no campaign engine behind
 * it yet — Stage 5C owns sending, and Stage 5A owns only the shape. It exists
 * because the two decisions it encodes are the ones most often got wrong later,
 * and getting them wrong is invisible until messages reach mailboxes:
 *
 *   1. **Plain text is derived, not optional-by-luck.** HTML-only input produces
 *      a text part automatically, which the sender may then review and edit.
 *      Marketing mail sent as HTML with no alternative is a deliverability
 *      handicap, and a cosmetic one a caller would never notice was being taken.
 *   2. **The sender is constrained by the transport.** The From address comes
 *      from {@see SenderIdentityPolicy}, not from free text, so a composed
 *      message cannot be built with a From its transport never authenticated as.
 *
 * {@see parts()} is what decides the MIME structure, so the choice is made once
 * and stated rather than being rediscovered at send time.
 */
final readonly class CampaignMessage
{
    public function __construct(
        public string $subject,
        public ?string $html = null,
        public ?string $text = null,
    ) {}

    /**
     * Build a message, generating a plain-text alternative when only HTML is given.
     *
     * The generated text is a *starting point*: it is offered for review, not
     * silently sent. A mechanical conversion of a designed HTML email is legible
     * but not good, and pretending otherwise would hide the difference.
     */
    public static function compose(string $subject, ?string $html = null, ?string $text = null): self
    {
        return new self(
            subject: $subject,
            html: filled($html) ? $html : null,
            text: filled($text) ? $text : self::plainTextFrom($html),
        );
    }

    /**
     * The MIME structure this message sends as.
     *
     * `alternative` whenever both parts exist, which is what a client uses to
     * offer the recipient a choice. `plain` or `html` when only one does.
     */
    public function structure(): string
    {
        return match (true) {
            $this->html !== null && $this->text !== null => 'alternative',
            $this->html !== null => 'html',
            $this->text !== null => 'plain',
            default => 'none',
        };
    }

    /**
     * @return array<int, array{contentType: string, body: string}>
     */
    public function parts(): array
    {
        return match ($this->structure()) {
            'alternative' => [
                ['contentType' => 'text/plain', 'body' => (string) $this->text],
                ['contentType' => 'text/html', 'body' => (string) $this->html],
            ],
            'html' => [
                ['contentType' => 'text/html', 'body' => (string) $this->html],
            ],
            'plain' => [
                ['contentType' => 'text/plain', 'body' => (string) $this->text],
            ],
            default => [],
        };
    }

    public function isEmpty(): bool
    {
        return $this->structure() === 'none';
    }

    /**
     * Whether the message carries the body parts a campaign is expected to carry.
     *
     * Distinct from `isEmpty()`: this is the preflight question, and it is what
     * a sending policy would check before a campaign is allowed to start.
     */
    public function hasDeliverableBody(): bool
    {
        return $this->structure() !== 'none';
    }

    /**
     * Whether a plain-text alternative is present.
     */
    public function hasPlainTextAlternative(): bool
    {
        return $this->text !== null && trim($this->text) !== '';
    }

    /**
     * Convert HTML into a legible plain-text starting point.
     *
     * Deliberately not a full HTML-to-text renderer: it breaks out of block
     * elements, drops scripts and styles, decodes entities, and collapses runs of
     * whitespace. That produces something a recipient can read on a text-only
     * client, which is the whole requirement — and the reason the result is
     * presented for editing rather than treated as finished copy.
     */
    public static function plainTextFrom(?string $html): ?string
    {
        if (blank($html)) {
            return null;
        }

        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $html);
        $text = preg_replace('#<br\s*/?>#i', "\n", (string) $text);
        $text = preg_replace('#</(p|div|tr|li|h[1-6]|blockquote|section|article)\s*>#i', "\n\n", (string) $text);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Non-breaking and other Unicode spaces become ordinary ones. PHP's
        // trim() does not remove U+00A0, so a body of nothing but `&nbsp;`
        // would otherwise survive as a "text part" consisting of invisible
        // characters — passing a preflight check while being unreadable.
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]/u', ' ', (string) $text);

        $text = preg_replace("/[ \t]+\n/", "\n", (string) $text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);

        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }

    /**
     * Shorten a text body for a preview, without cutting mid-word.
     */
    public function preview(int $characters = 140): string
    {
        $body = $this->text ?? $this->plainTextFrom($this->html) ?? '';

        return Str::limit(preg_replace('/\s+/', ' ', $body) ?? '', $characters);
    }
}
