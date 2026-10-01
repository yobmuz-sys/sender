<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Users\Enums\Role;
use App\Domain\Users\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * The account record.
 *
 * This model stays in App\Models because the framework's authentication
 * subsystem resolves it from config/auth.php. Authorization rules live in
 * App\Domain\Users so they can evolve without the persistence layer.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * `role` is deliberately absent: a role is assigned by an operator or a
     * console command, never by request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Attribute defaults for a model that has not been persisted yet.
     *
     * `status` needs one because an account is authenticated immediately after
     * it is created, before any refresh — reading the column then would throw
     * on a model that does not have it yet. The database default matches, so
     * the two cannot disagree.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'status' => UserStatus::class,
        ];
    }

    public function hasRole(Role $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Whether this account belongs to platform staff rather than a customer.
     */
    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }

    /**
     * Whether this account holds the platform's highest authority.
     */
    public function isSuperAdministrator(): bool
    {
        return $this->hasRole(Role::SuperAdmin);
    }

    /**
     * Whether this account can currently sign in.
     *
     * Separate from the role: a suspended super administrator is still a super
     * administrator, and an authentication check that conflated the two would
     * misreport what an account is.
     */
    public function canSignIn(): bool
    {
        return $this->status->canSignIn();
    }

    public function isSuspended(): bool
    {
        return ! $this->canSignIn();
    }
}
