<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectOwnershipService
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reassign(Project $project, User $actor, int $newOwnerId): User
    {
        return DB::transaction(function () use ($project, $actor, $newOwnerId): User {
            $lockedProject = Project::query()
                ->lockForUpdate()
                ->findOrFail($project->getKey());

            if ((int) $lockedProject->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('Only the current project owner can reassign this project.');
            }

            if ($newOwnerId === (int) $actor->getKey()) {
                throw ValidationException::withMessages([
                    'new_owner_ids' => 'Select a different user to reassign the project to.',
                ]);
            }

            $newOwner = User::query()->find($newOwnerId);

            if ($newOwner === null) {
                throw ValidationException::withMessages([
                    'new_owner_ids' => 'The selected user is no longer available.',
                ]);
            }

            $lockedProject->update([
                'user_id' => $newOwner->getKey(),
                'created_by_email' => $newOwner->email,
            ]);

            return $newOwner;
        });
    }
}
