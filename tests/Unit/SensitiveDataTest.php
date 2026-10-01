<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SensitiveData;
use PHPUnit\Framework\TestCase;

class SensitiveDataTest extends TestCase
{
    public function test_it_redacts_credential_shaped_keys(): void
    {
        $redacted = SensitiveData::redact([
            'email' => 'ops@example.com',
            'password' => 'hunter2',
            'smtp_password' => 'smtp-secret',
            'api_key' => 'ak_live_123',
            'Authorization' => 'Bearer abc',
        ]);

        $this->assertSame('ops@example.com', $redacted['email']);
        $this->assertSame(SensitiveData::REDACTED, $redacted['password']);
        $this->assertSame(SensitiveData::REDACTED, $redacted['smtp_password']);
        $this->assertSame(SensitiveData::REDACTED, $redacted['api_key']);
        $this->assertSame(SensitiveData::REDACTED, $redacted['Authorization']);
    }

    public function test_it_redacts_nested_structures(): void
    {
        $redacted = SensitiveData::redact([
            'smtp' => [
                'host' => 'mail.example.com',
                'account' => ['username' => 'postmaster', 'secret' => 'x'],
            ],
        ]);

        $this->assertSame('mail.example.com', $redacted['smtp']['host']);
        $this->assertSame('postmaster', $redacted['smtp']['account']['username']);
        $this->assertSame(SensitiveData::REDACTED, $redacted['smtp']['account']['secret']);
    }

    public function test_a_sensitive_key_redacts_its_entire_subtree(): void
    {
        $redacted = SensitiveData::redact([
            'credentials' => ['username' => 'postmaster', 'password' => 'x'],
        ]);

        $this->assertSame(SensitiveData::REDACTED, $redacted['credentials']);
    }

    public function test_it_does_not_redact_ordinary_values(): void
    {
        $redacted = SensitiveData::redact([
            'user_id' => 42,
            'role' => 'admin',
            'nested' => ['campaign' => 'welcome-series'],
        ]);

        $this->assertSame($redacted['user_id'], 42);
        $this->assertSame('admin', $redacted['role']);
        $this->assertSame('welcome-series', $redacted['nested']['campaign']);
    }

    public function test_it_stops_at_a_maximum_depth(): void
    {
        $data = ['level' => 'root'];

        for ($i = 0; $i < 20; $i++) {
            $data = ['child' => $data];
        }

        $this->assertIsArray(SensitiveData::redact($data));
    }

    public function test_it_redacts_credentials_embedded_in_free_text(): void
    {
        // Key-based redaction cannot help here: the payload is a single string
        // whose keys say nothing. Failure messages routinely quote the
        // configuration that caused them, and are persisted, not just logged.
        $message = 'auth failed for ops with password=hunter2 using api_key=abcdef123456';

        $redacted = SensitiveData::redactText($message);

        $this->assertStringNotContainsString('hunter2', $redacted);
        $this->assertStringNotContainsString('abcdef123456', $redacted);
        $this->assertStringContainsString(SensitiveData::REDACTED, $redacted);
    }

    public function test_free_text_redaction_accepts_colon_separated_and_quoted_values(): void
    {
        foreach ([
            'token: abc123def',
            'Authorization: "Bearer eyJhbGciOi"',
            "api_key='sk-live-9f2'",
        ] as $message) {
            $redacted = SensitiveData::redactText($message);

            foreach (['abc123def', 'eyJhbGciOi', 'sk-live-9f2'] as $secret) {
                $this->assertStringNotContainsString($secret, $redacted, "leaked in: {$message}");
            }
        }
    }

    public function test_free_text_redaction_keeps_the_message_useful(): void
    {
        $redacted = SensitiveData::redactText('Connection refused by mail.example.com:587');

        $this->assertSame('Connection refused by mail.example.com:587', $redacted);
    }

    public function test_free_text_redaction_does_not_mangle_ordinary_words(): void
    {
        $message = 'Extracted 150 addresses from 12 pages in 3.4s';

        $this->assertSame($message, SensitiveData::redactText($message));
    }
}
