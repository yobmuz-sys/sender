<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\System\Enums\DeploymentLimit;
use App\Support\Bytes;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces a byte ceiling read from the deployment limits.
 *
 * The value this replaces was `'content' => ['max:20000']`. Two problems with
 * that, both of which had to be fixed rather than retyped:
 *
 *  1. It was a literal. The platform already declares
 *     `deployment_limits.max_text_input_bytes`, and a limit that exists in two
 *     places stops meaning anything the moment an operator changes one.
 *  2. It was not a byte limit. Laravel's `max` on a string counts characters
 *     via `mb_strlen`, so `'max:1048576'` would have accepted a megabyte of
 *     characters — up to four megabytes of UTF-8 — which is precisely the
 *     quantity a memory ceiling is supposed to bound.
 *
 * So this measures `strlen`, which counts bytes, and reports the ceiling in
 * human units so the message matches what an operator configured.
 */
final class WithinByteCeiling implements ValidationRule
{
    public function __construct(
        private readonly DeploymentLimit $limit,
    ) {}

    public static function forTextInput(): self
    {
        return new self(DeploymentLimit::MaxTextInputBytes);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $ceiling = $this->limit->value();

        if ($ceiling < 1) {
            // An unset limit is a configuration fault, not a free pass. Failing
            // closed means a misconfigured deployment accepts nothing rather
            // than everything.
            $fail("The {$this->limit->value} deployment limit is not configured, so no input can be accepted.");

            return;
        }

        $size = strlen($value);

        if ($size > $ceiling) {
            $fail('The :attribute may not be larger than '.Bytes::humanize($ceiling).'.');
        }
    }
}
