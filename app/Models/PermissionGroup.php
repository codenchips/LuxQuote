<?php

namespace App\Models;

use App\Enums\LandingPage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'default_landing_page', 'is_system'])]
class PermissionGroup extends Model
{
    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_group_permission');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isAdminGroup(): bool
    {
        return $this->slug === 'admin';
    }

    public function ensureHasAllPermissions(): void
    {
        if (! $this->isAdminGroup()) {
            return;
        }

        $this->permissions()->syncWithoutDetaching(
            Permission::query()->pluck('id')->all(),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_landing_page' => LandingPage::class,
            'is_system' => 'boolean',
        ];
    }
}
