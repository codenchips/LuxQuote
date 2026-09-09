<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AdminGroupGuard;

class UserObserver
{
    public function __construct(private AdminGroupGuard $adminGroupGuard) {}

    public function creating(User $user): void
    {
        $this->adminGroupGuard->enforceMembershipChange($user, auth()->user());
    }

    public function updating(User $user): void
    {
        $this->adminGroupGuard->enforceMembershipChange($user, auth()->user());
    }

    public function deleting(User $user): void
    {
        $this->adminGroupGuard->enforceDeletion($user, auth()->user());
    }
}
