<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * Users are NOT tenant-scoped by the global scope: the login process must be able
 * to find them before any tenant is known. Access is enforced by the methods below.
 */
#[Fillable(['tenant_id', 'name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ADMIN_PANEL = 'admin';

    public const APP_PANEL = 'app';

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
            'role' => UserRole::class,
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    /**
     * super_admin -> /admin only. owner/staff -> /app only, and only with an active tenant.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            self::ADMIN_PANEL => $this->isSuperAdmin(),
            self::APP_PANEL => ! $this->isSuperAdmin()
                && $this->tenant_id !== null
                && $this->tenant?->isActive() === true,
            default => false,
        };
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function getTenants(Panel $panel): Collection
    {
        if ($this->isSuperAdmin() || $this->tenant === null) {
            return collect();
        }

        return collect([$this->tenant]);
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return ! $this->isSuperAdmin()
            && $this->tenant_id !== null
            && (int) $tenant->getKey() === (int) $this->tenant_id;
    }
}
