<?php

declare(strict_types=1);

namespace App\Domain\Templates;

/**
 * Substitutes placeholders in customer-authored message content.
 *
 * A template is a string a customer wrote, not a view the platform compiles. That
 * distinction is the whole reason this class exists, so it is worth stating what is
 * deliberately absent: there is no `eval`, no `Blade::compileString`, no
 * `include`, and no path from a template body to the application's own source.
 *
 * The rules are:
 *
 *  - only the placeholders in {@see PersonalisationToken} are ever replaced;
 *  - anything else shaped like a placeholder is left exactly as written, so the
 *    customer sees it rather than the recipient;
 *  - supplied values are HTML-escaped when substituted into an HTML body, because
 *    a recipient's name is data and data does not get to become markup;
 *  - unknown placeholders are *reported*, not silently tolerated, so a template
 *    can be refused before a campaign is built on it.
 *
 * `{{$name}}`, `{!! $name !!}` and `@php` are not placeholders this renderer
 * recognises, which is exactly the point: they are inert text, and
 * {@see containsExecutableSyntax()} exists so a template containing them is
 * refused rather than quietly sent.
 */
final class MessageRenderer
{
    /**
     * A placeholder, in either spaced or unspaced form.
     */
    private const TOKEN_PATTERN = '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/';

    /**
     * Replacements in order: renderers fail in a browser, not in a server log.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const DANGEROUS_MARKUP = [
        // Scripts, with their contents. A script whose body survives but whose tag
        // does not is harmless; a body left behind as text would not be.
        '/<script\b[^>]*>.*?<\/script>/is' => '',
        '/<script\b[^>]*\/?>/i' => '',
        '/<style\b[^>]*>.*?<\/style>/is' => '',
        '/<style\b[^>]*\/?>/i' => '',
        // Embedded frames and objects have no business in email and are the other
        // way to run something inside a preview.
        '/<(iframe|object|embed|frame|frameset)\b[^>]*>.*?<\/\1>/is' => '',
        '/<(iframe|object|embed|frame|frameset)\b[^>]*\/?>/i' => '',
        // Inline event handlers and script URLs.
        '/\son[a-z]+\s*=\s*"[^"]*"/i' => '',
        '/\son[a-z]+\s*=\s*\'[^\']*\'/i' => '',
        '/\son[a-z]+\s*=\s*[^\s>]+/i' => '',
        '/\s(srcdoc|sandbox)\s*=\s*"[^"]*"/i' => '',
        '/(href|src|action)\s*=\s*"javascript:[^"]*"/i' => '',
    ];

    /**
     * Syntax that would only ever be an attempt to run code.
     *
     * @var list<string>
     */
    private const EXECUTABLE_SYNTAX = [
        '<?php',
        '<?=',
        '{!!',
        '@php',
        '@inject',
        '@include',
        '@extends',
        '@section',
        '@if(',
        '@foreach(',
    ];

    /**
     * Replace every supported placeholder that has a value.
     *
     * A supported placeholder with no value is left in place. During a preview that
     * is exactly what the customer wants to see — where each field will land —
     * whereas blanking it would hide a mistake like a misspelt token.
     *
     * @param  array<string, string|null>  $values  keyed by {@see PersonalisationToken} value
     */
    public function substitute(string $body, array $values = [], bool $escapeValues = true): string
    {
        return (string) preg_replace_callback(
            self::TOKEN_PATTERN,
            function (array $matches) use ($values, $escapeValues): string {
                $token = PersonalisationToken::tryFrom(strtolower($matches[1]));

                if ($token === null) {
                    return $matches[0];
                }

                $value = $values[$token->value] ?? null;

                if ($value === null) {
                    return $matches[0];
                }

                return $escapeValues
                    ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    : $value;
            },
            $body,
        );
    }

    /**
     * Placeholders in the body that this renderer does not support.
     *
     * @return list<string>
     */
    public function unknownTokens(string $body): array
    {
        preg_match_all(self::TOKEN_PATTERN, $body, $matches);

        $unknown = [];

        foreach ($matches[1] ?? [] as $name) {
            $name = strtolower($name);

            if (PersonalisationToken::tryFrom($name) === null && ! in_array($name, $unknown, true)) {
                $unknown[] = $name;
            }
        }

        return $unknown;
    }

    /**
     * Supported placeholders the body actually uses.
     *
     * @return list<PersonalisationToken>
     */
    public function tokensUsed(string $body): array
    {
        preg_match_all(self::TOKEN_PATTERN, $body, $matches);

        $used = [];

        foreach ($matches[1] ?? [] as $name) {
            $token = PersonalisationToken::tryFrom(strtolower($name));

            if ($token !== null && ! in_array($token, $used, true)) {
                $used[] = $token;
            }
        }

        return $used;
    }

    /**
     * Strip anything that could execute, for display.
     *
     * This is defence in depth, not a guarantee, and the reason it is not written
     * to look like one: a regex cannot parse HTML, and a customer who finds a way
     * around a denylist should still be contained by something structural. The
     * guarantee is the sandboxed frame the preview is rendered in — scripts do not
     * run there regardless of what the markup says. This pass exists so the stored
     * body shown in a preview is also readable, and so a script never reaches the
     * frame in the first place.
     */
    public function forPreview(string $html): string
    {
        $cleaned = $html;

        foreach (self::DANGEROUS_MARKUP as $pattern => $replacement) {
            $cleaned = (string) preg_replace($pattern, $replacement, $cleaned);
        }

        return $cleaned;
    }

    /**
     * Whether the content contains an attempt to execute code.
     */
    public function containsExecutableSyntax(string $content): bool
    {
        foreach (self::EXECUTABLE_SYNTAX as $needle) {
            if (stripos($content, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
