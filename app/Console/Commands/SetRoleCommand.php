<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Users\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

class SetRoleCommand extends Command
{
    protected $signature = 'sender:set-role
                            {email : The address of an existing account}
                            {role : One of the platform roles}
                            {--all : Apply the role to every account}';

    protected $description = 'Assign a platform role to an existing account';

    public function handle(): int
    {
        $role = Role::tryFrom($this->argument('role'));

        if ($role === null) {
            $this->error(sprintf(
                'Unknown role "%s". Valid roles: %s',
                $this->argument('role'),
                implode(', ', array_column(Role::cases(), 'value')),
            ));

            return self::INVALID;
        }

        $query = User::query();

        if (! $this->option('all')) {
            $query->where('email', $this->argument('email'));
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->error('No matching account found.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            $user->forceFill(['role' => $role])->save();

            $this->info(sprintf('%s is now %s.', $user->email, $role->label()));
        }

        return self::SUCCESS;
    }
}
