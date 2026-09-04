<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProjectOwnerNameResolver
{
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
            $cachedOwner = Cache::get($emailCacheKey);

            if (is_array($cachedOwner) && array_key_exists('name', $cachedOwner)) {
                return filled($cachedOwner['name'] ?? null) ? $this->store($project, (string) $cachedOwner['name']) : null;
            }
        }

        if (blank($project->salesforce_id)) {
            return null;
        }

        $cached = Cache::remember(
            'statistics.project-owner.'.md5((string) $project->salesforce_id),
            now()->addHours(6),
            fn (): array => ['name' => $this->salesforceName($project)],
        );
        $name = $cached['name'] ?? null;

        if (isset($emailCacheKey)) {
            Cache::put($emailCacheKey, ['name' => $name], now()->addHours(6));
        }

        return filled($name) ? $this->store($project, (string) $name) : null;
    }

    private function store(Project $project, string $name): string
    {
        $project->owner_name = $name;
        $project->saveQuietly();

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
