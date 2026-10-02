<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Audience\ValidationMethod;
use App\Domain\Audience\ValidationReason;
use App\Domain\Audience\ValidationStatus;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * A contact that has been created and nothing else.
     *
     * The default is `UNKNOWN` with `NOT_VALIDATED`, which is the honest state of
     * a freshly imported address and the one most of a real audience is in. A
     * factory whose default address was "likely active" would quietly bias every
     * test that creates contacts, and the bias would point the convenient way:
     * towards the optimistic classification this platform is built to distrust.
     */
    public function definition(): array
    {
        $email = Str::lower(Str::random(10).'@example.com');

        return [
            'user_id' => User::factory(),
            'email' => $email,
            'normalized_email' => $email,
            'validation_status' => ValidationStatus::Unknown->value,
            'validation_reason' => ValidationReason::NotValidated->value,
            'validation_method' => ValidationMethod::None->value,
            'validated_at' => null,
            'validation_expires_at' => null,
            'last_smtp_code' => null,
            'last_enhanced_code' => null,
            'is_catch_all' => false,
        ];
    }

    /**
     * A contact whose recipient server accepted the address.
     */
    public function likelyActive(): static
    {
        return $this->state(fn (): array => [
            'validation_status' => ValidationStatus::LikelyActive->value,
            'validation_reason' => ValidationReason::RecipientAccepted->value,
            'validation_method' => ValidationMethod::SmtpRecipient->value,
            'last_smtp_code' => 250,
            'validated_at' => now(),
            'validation_expires_at' => now()->addWeek(),
        ]);
    }

    /**
     * A contact with definitive evidence that the mailbox does not exist.
     */
    public function confirmedInvalid(): static
    {
        return $this->state(fn (): array => [
            'validation_status' => ValidationStatus::ConfirmedInvalid->value,
            'validation_reason' => ValidationReason::MailboxNotFound->value,
            'validation_method' => ValidationMethod::SmtpRecipient->value,
            'last_smtp_code' => 550,
            'last_enhanced_code' => '5.1.1',
            'validated_at' => now(),
            'validation_expires_at' => now()->addMonth(),
        ]);
    }

    /**
     * A contact that could not be checked, which is not the same as invalid.
     */
    public function unknown(): static
    {
        return $this->state(fn (): array => [
            'validation_status' => ValidationStatus::Unknown->value,
            'validation_reason' => ValidationReason::VerificationBlocked->value,
            'validation_method' => ValidationMethod::SmtpRecipient->value,
            'validated_at' => now(),
            'validation_expires_at' => now()->addWeek(),
        ]);
    }
}
