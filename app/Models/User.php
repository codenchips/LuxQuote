<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\JobRole;
use App\Enums\PermissionKey;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser; // <-- 1. ADDED THIS IMPORT
use Filament\Panel; // <-- 2. ADDED THIS IMPORT
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'permission_group_id', 'area_code', 'job_role', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery // <-- 3. APPENDED FilamentUser HERE
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'job_role' => JobRole::class,
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Since this is a front-end app panel, allow all authenticated users in
        return true;
    }

    /**
     * @return BelongsTo<PermissionGroup, $this>
     */
    public function permissionGroup(): BelongsTo
    {
        return $this->belongsTo(PermissionGroup::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function isAdministrator(): bool
    {
        return $this->role === UserRole::Admin || $this->permissionGroup?->slug === 'admin';
    }

    public function hasPermission(PermissionKey|string $permission): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        $permissionKey = $permission instanceof PermissionKey ? $permission->value : $permission;
        $group = $this->permissionGroup;

        if ($group === null) {
            return false;
        }

        return $group->permissions()->where('key', $permissionKey)->exists();
    }
}
