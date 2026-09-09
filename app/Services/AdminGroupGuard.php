<?php

namespace App\Services;

use App\Models\PermissionGroup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class AdminGroupGuard
{
    public const GroupSlug = 'admin';

    public function isAdminGroupMember(?User $user): bool
    {
        return $user !== null
            && $user->exists
            && User::query()
                ->whereKey($user->getKey())
                ->whereHas('permissionGroup', fn (Builder $query): Builder => $query->where('slug', self::GroupSlug))
                ->exists();
    }

    public function isAdminGroupId(mixed $permissionGroupId): bool
    {
        if (! is_numeric($permissionGroupId)) {
            return false;
        }

        return PermissionGroup::query()
            ->whereKey((int) $permissionGroupId)
            ->where('slug', self::GroupSlug)
            ->exists();
    }

    public function enforceMembershipChange(User $user, ?User $actor): void
    {
        if (! $user->isDirty('permission_group_id')) {
            return;
        }

        $wasAdminMember = $user->exists
            && $this->isAdminGroupId($user->getRawOriginal('permission_group_id'));
        $willBeAdminMember = $this->isAdminGroupId($user->permission_group_id);

        if ($wasAdminMember === $willBeAdminMember) {
            return;
        }

        $this->authorizeAdminMembershipChange($actor);

        if ($wasAdminMember && ! $willBeAdminMember) {
            $this->ensureAnotherAdminMemberExists($user);
        }
    }

    public function enforceDeletion(User $user, ?User $actor): void
    {
        if (! $this->isAdminGroupId($user->getRawOriginal('permission_group_id'))) {
            return;
        }

        $this->authorizeAdminMembershipChange($actor);
        $this->ensureAnotherAdminMemberExists($user);
    }

    public function canDelete(User $user, ?User $actor): bool
    {
        if (! $this->isAdminGroupId($user->getRawOriginal('permission_group_id'))) {
            return true;
        }

        return $this->isAdminGroupMember($actor)
            && $this->adminMemberCount() > 1;
    }

    private function authorizeAdminMembershipChange(?User $actor): void
    {
        if ($actor === null) {
            return;
        }

        if (! $this->isAdminGroupMember($actor)) {
            throw new AuthorizationException('Only a current member of the Admin group can add or remove Admin members.');
        }
    }

    private function ensureAnotherAdminMemberExists(User $user): void
    {
        $otherAdminExists = User::query()
            ->where('id', '!=', $user->getKey())
            ->whereHas('permissionGroup', fn (Builder $query): Builder => $query->where('slug', self::GroupSlug))
            ->exists();

        if (! $otherAdminExists) {
            throw ValidationException::withMessages([
                'permission_group_id' => 'The final member of the Admin group cannot be removed.',
            ]);
        }
    }

    private function adminMemberCount(): int
    {
        return User::query()
            ->whereHas('permissionGroup', fn (Builder $query): Builder => $query->where('slug', self::GroupSlug))
            ->count();
    }
}
