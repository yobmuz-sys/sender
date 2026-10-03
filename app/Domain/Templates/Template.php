<?php

declare(strict_types=1);

namespace App\Domain\Templates;

use App\Models\User;
use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reusable message content belonging to one tenant.
 *
 * A template is a name, a subject, an HTML body and a plain-text body. It is not a
 * view, it is not compiled, and nothing here is ever evaluated — the content is
 * stored exactly as the customer wrote it and read back as a string.
 *
 * `version` is what makes this record safe to edit. A campaign does not read a
 * template while it runs; it copies this content when it starts and records the
 * version it copied. Bumping the counter on every content change is what makes
 * "which content did that campaign send?" answerable later, so editing a template
 * can never rewrite the history of a message already on its way to a recipient.
 *
 * {@see applyContent()} is the only supported way to change the content, because
 * that is where the counter and the derived status are maintained. Assigning to
 * `html_body` directly would be a smaller change and would quietly break both.
 *
 * @property int $version
 * @property TemplateStatus $status
 */
class Template extends Model
{
    use HasFactory;

    /**
     * The fields whose change makes this a different message.
     *
     * Public because the controller validates and saves exactly these, and a
     * second list somewhere else would eventually fall out of step with this one —
     * a field that is versioned but never saved is worse than one that is neither.
     *
     * @var list<string>
     */
    public const CONTENT_ATTRIBUTES = [
        'name',
        'subject',
        'preheader',
        'html_body',
        'text_body',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'subject',
        'preheader',
        'html_body',
        'text_body',
    ];

    protected static function newFactory(): TemplateFactory
    {
        return TemplateFactory::new();
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => TemplateStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Apply edited content, versioning it when the message itself changed.
     *
     * The version moves when any of name, subject, preheader or either body
     * differs — all of them are what a recipient is shown or sent, so a changed
     * name is as much a different message as a changed body.
     */
    public function applyContent(array $attributes): void
    {
        $changed = false;

        foreach (self::CONTENT_ATTRIBUTES as $attribute) {
            if ($this->readable($attributes[$attribute] ?? null) !== $this->readable($this->{$attribute})) {
                $changed = true;
                break;
            }
        }

        $this->fill($attributes);

        if ($changed) {
            $this->version = ((int) $this->version) + 1;
        }

        // Recomputed here rather than left to a caller, so a row cannot be saved
        // with a status its own contents contradict.
        $this->status = $this->effectiveStatus();
    }

    /**
     * What is wrong with this template, in words a customer can act on.
     *
     * An empty list is the pass condition. It is computed from the row rather than
     * declared at save time, so it cannot be stale, and it is the same question the
     * campaign stage will ask before it starts sending.
     *
     * @return list<string>
     */
    public function blockers(MessageRenderer $renderer): array
    {
        $blockers = [];

        if (trim((string) $this->subject) === '') {
            $blockers[] = 'The subject line is empty, so the message has nothing to show in a recipient\'s inbox list.';
        }

        if (trim((string) $this->html_body) === '') {
            $blockers[] = 'The HTML message is empty.';
        }

        if (trim((string) $this->text_body) === '') {
            $blockers[] = 'The plain-text message is empty. Some recipients cannot read HTML, and they would receive nothing.';
        }

        foreach ($renderer->unknownTokens(implode(' ', [
            (string) $this->subject,
            (string) $this->html_body,
            (string) $this->text_body,
        ])) as $token) {
            $blockers[] = 'The placeholder {{'.$token.'}} is not one this platform fills in, so it would be sent to recipients as written.';
        }

        return $blockers;
    }

    /**
     * Recompute the status from the contents.
     *
     * A stored status is a cache; this is the truth. Same reasoning as a transport's
     * effective status: the row cannot avoid being checked by not being looked at.
     */
    public function effectiveStatus(MessageRenderer $renderer = new MessageRenderer): TemplateStatus
    {
        return $this->blockers($renderer) === []
            ? TemplateStatus::Ready
            : TemplateStatus::Draft;
    }

    /**
     * The content a campaign copies when it starts.
     *
     * Deliberately a plain array of strings and an integer. A campaign stores this
     * and sends from its own copy, so a template edited afterwards cannot change a
     * message already in flight — and the recorded version is what makes the copy
     * identifiable later.
     *
     * @return array{name: string, subject: string, preheader: string|null, html_body: string, text_body: string, version: int}
     */
    public function snapshot(): array
    {
        return [
            'name' => (string) $this->name,
            'subject' => (string) $this->subject,
            'preheader' => $this->preheader,
            'html_body' => (string) $this->html_body,
            'text_body' => (string) $this->text_body,
            'version' => (int) $this->version,
        ];
    }

    /**
     * Restrict to one tenant's templates.
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Read an attribute for comparison, with null and empty treated the same.
     */
    private function readable(mixed $value): string
    {
        return is_string($value) ? trim($value) : trim((string) $value);
    }
}
