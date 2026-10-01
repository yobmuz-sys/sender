<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Users\Enums\Role;
use Database\Factories\UserFactory;
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
class User extends Authenticatable
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
}
