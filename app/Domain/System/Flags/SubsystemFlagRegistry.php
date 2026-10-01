<?php

declare(strict_types=1);

namespace App\Domain\System\Flags;

use App\Domain\System\Enums\Subsystem;
use App\Domain\System\Settings\SystemSettings;

/**
 * The single consolidated registry for operator control.
 *
 * Safe mode, emergency disablement and subsystem toggles are this one flag, not
 * three systems: they differ only in intent, and splitting them would mean
 * three places to check before allowing an operation.
 *
 * Defaults live in config('sender.subsystems') so a fresh installation has a
 * known-good state; an operator's choice overrides that and is persisted.
 */
final class SubsystemFlagRegistry
{
    public function __construct(
        private readonly SystemSettings $settings,
    ) {}

    public function enabled(Subsystem $subsystem): bool
    {
        $value = $this->settings->get($this->key($subsystem));

        if ($value === null) {
            return (bool) config('sender.subsystems.'.$subsystem->value, true);
        }

        return (bool) $value;
    }

    public function enable(Subsystem $subsystem): void
    {
        $this->settings->set($this->key($subsystem), true);
    }

    public function disable(Subsystem $subsystem): void
    {
        $this->settings->set($this->key($subsystem), false);
    }

    /**
     * Return a subsystem to its configured default, removing any override.
     */
    public function reset(Subsystem $subsystem): void
    {
        $this->settings->forget($this->key($subsystem));
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        $state = [];

        foreach (Subsystem::cases() as $subsystem) {
            $state[$subsystem->value] = $this->enabled($subsystem);
        }

        return $state;
    }

    private function key(Subsystem $subsystem): string
    {
        return 'subsystem.'.$subsystem->value.'.enabled';
    }
}
