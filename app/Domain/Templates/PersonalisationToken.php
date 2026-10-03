<?php

declare(strict_types=1);

namespace App\Domain\Templates;

/**
 * The placeholders a message body may contain.
 *
 * An enum rather than a free string because the set is the security boundary, not
 * a convenience: substitution walks a whitelist, so a placeholder that is not in
 * here is not a feature that has not been built yet, it is text that will be sent
 * to a recipient exactly as written.
 *
 * `{{first_name}}` is worth its own comment. The customer's contacts do not carry
 * one — a validated address proves a mailbox exists, not a person's name — so this
 * resolves to an empty string unless a name is supplied for that send. An enum case
 * with a documented "usually empty" is honest; a token that silently leaks another
 * tenant's contact name is not.
 */
enum PersonalisationToken: string
{
    case Email = 'email';

    case FirstName = 'first_name';

    case UnsubscribeUrl = 'unsubscribe_url';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Recipient email address',
            self::FirstName => 'Recipient first name',
            self::UnsubscribeUrl => 'Unsubscribe link',
        };
    }

    /**
     * What the sender supplies, and when.
     *
     * @return array<string, string>
     */
    public function description(): string
    {
        return match ($this) {
            self::Email => 'The address the message is being sent to. Supplied for every send.',
            self::FirstName => 'The recipient\'s first name, if one is known. Left empty when it is not.',
            self::UnsubscribeUrl => 'The signed unsubscribe link for that recipient. Supplied for every send, and required by the campaign checks.',
        };
    }

    /**
     * The literal a customer types into their message.
     */
    public function placeholder(): string
    {
        return '{{'.$this->value.'}}';
    }

    /**
     * An example value, used to show what a placeholder will look like once sent.
     */
    public function sample(): string
    {
        return match ($this) {
            self::Email => 'ada@example.com',
            self::FirstName => 'Ada',
            self::UnsubscribeUrl => 'https://sender.example.com/unsubscribe/…',
        };
    }

    /**
     * @return list<string>
     */
    public static function placeholders(): array
    {
        return array_map(static fn (self $token): string => $token->placeholder(), self::cases());
    }
}
