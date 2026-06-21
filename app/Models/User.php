<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLES = [
        'admin' => 'Administrator (full access)',
        'manager' => 'Manager (no settings/users)',
        'staff' => 'Staff (orders only)',
    ];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Admin sections a given role may access ('*' = everything). */
    public static function sectionsFor(string $role): array
    {
        return match ($role) {
            'manager' => ['dashboard', 'products', 'categories', 'coupons', 'offers', 'reviews', 'abandoned', 'orders', 'menu', 'profile'],
            'staff' => ['dashboard', 'orders', 'profile'],
            default => ['*'], // admin
        };
    }

    public function canAccess(string $section): bool
    {
        $allowed = static::sectionsFor($this->role);

        return in_array('*', $allowed, true) || in_array($section, $allowed, true);
    }

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
        ];
    }
}
