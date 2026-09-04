<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProjectOwnerNameResolver
{
    /** @param Collection<int, Project> $projects
     * @return Collection<int, string|null>
     */
    public function resolveMany(Collection $projects): Collection
    {
        $projects = $projects->unique('id')->values();
        $emails = $projects->pluck('owner_email')->filter()->unique();
        $localNames = User::query()->whereIn('email', $emails)->pluck('name', 'email');
        $knownNames = Project::query()->whereIn('owner_email', $emails)->whereNotNull('owner_name')->pluck('owner_name', 'owner_email');

        return $projects->mapWithKeys(function (Project $project) use ($localNames, $knownNames): array {
            if (filled($project->owner_name)) {
                return [$project->id => (string) $project->owner_name];
            }

            $knownName = $localNames->get($project->owner_email)
                ?: $knownNames->get($project->owner_email);
            $name = filled($knownName) ? $this->store($project, (string) $knownName) : $this->resolve($project);

            return [$project->id => $name];
        });
    }

    public function resolve(Project $project): ?string
    {
        if (filled($project->owner_name)) {
            return (string) $project->owner_name;
        }

        $localName = filled($project->owner_email)
            ? User::query()->where('email', $project->owner_email)->value('name')
            : null;

        if (filled($localName)) {
            return $this->store($project, (string) $localName);
        }

        if (filled($project->owner_email)) {
            $knownName = Project::query()
                ->where('owner_email', $project->owner_email)
                ->whereNotNull('owner_name')
                ->value('owner_name');

            if (filled($knownName)) {
                return $this->store($project, (string) $knownName);
            }

            $emailCacheKey = 'statistics.owner-email.'.md5(strtolower((string) $project->owner_email));
            try {
                $cachedOwner = Cache::get($emailCacheKey);
            } catch (Throwable $exception) {
                Log::warning('Owner-name cache read failed during statistics reporting.', [
                    'project_id' => $project->id,
                    'exception' => $exception->getMessage(),
                ]);
                $cachedOwner = null;
            }

            if (is_array($cachedOwner) && array_key_exists('name', $cachedOwner)) {
                return filled($cachedOwner['name'] ?? null) ? $this->store($project, (string) $cachedOwner['name']) : null;
            }
        }

        if (blank($project->salesforce_id)) {
            return null;
        }

        try {
            $cached = Cache::remember(
                'statistics.project-owner.'.md5((string) $project->salesforce_id),
                now()->addHours(6),
                fn (): array => ['name' => $this->salesforceName($project)],
            );
        } catch (Throwable $exception) {
            Log::warning('Owner-name cache was unavailable during statistics reporting.', [
                'project_id' => $project->id,
                'exception' => $exception->getMessage(),
            ]);
            $cached = ['name' => $this->salesforceName($project)];
        }
        $name = $cached['name'] ?? null;

        if (isset($emailCacheKey)) {
            try {
                Cache::put($emailCacheKey, ['name' => $name], now()->addHours(6));
            } catch (Throwable $exception) {
                Log::warning('Owner-name cache write failed during statistics reporting.', [
                    'project_id' => $project->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return filled($name) ? $this->store($project, (string) $name) : null;
    }

    private function store(Project $project, string $name): string
    {
        $project->owner_name = $name;

        try {
            $project->saveQuietly();
        } catch (Throwable $exception) {
            Log::warning('Resolved project owner name could not be persisted.', [
                'project_id' => $project->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        return $name;
    }

    private function salesforceName(Project $project): ?string
    {
        try {
            $owner = app(SalesforceService::class)->getOpportunityOwner((string) $project->salesforce_id);

            return filled($owner['name'] ?? null) ? (string) $owner['name'] : null;
        } catch (Throwable $exception) {
            Log::warning('Salesforce owner lookup failed during statistics reporting.', [
                'project_id' => $project->id,
                'salesforce_id' => $project->salesforce_id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
