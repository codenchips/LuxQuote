<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Project;
use Illuminate\Support\Carbon;

class ProjectObserver
{
    /** Fields that do not constitute a meaningful user edit. */
    private const META_KEYS = [
        'last_edited_at',
        'last_edited_by',
        'active_revision_id',
        'updated_at',
        'created_at',
    ];

    /** Stash pending update payloads between updating() and updated(). */
    private static array $pendingPayloads = [];

    public function created(Project $project): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => $project->id,
            'action_type' => 'project.created',
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => $project->name,
            'payload' => null,
        ]);
    }

    public function updating(Project $project): void
    {
        $meaningful = array_diff_key($project->getDirty(), array_flip(self::META_KEYS));

        if (! empty($meaningful)) {
            $project->last_edited_at = Carbon::now();
            $project->last_edited_by = auth()->id();

            $payload = [];
            foreach ($meaningful as $key => $newValue) {
                $payload[$key] = ['old' => $project->getOriginal($key), 'new' => $newValue];
            }
            self::$pendingPayloads[$project->id] = $payload;
        }
    }

    public function updated(Project $project): void
    {
        $payload = self::$pendingPayloads[$project->id] ?? null;

        if ($payload) {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action_type' => 'project.updated',
                'user_email_snapshot' => auth()->user()?->email ?? '',
                'project_name_snapshot' => $project->name,
                'payload' => $payload,
            ]);
            unset(self::$pendingPayloads[$project->id]);
        }
    }

    public function deleting(Project $project): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => $project->id,
            'action_type' => 'project.deleted',
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => $project->name,
            'payload' => null,
        ]);
    }
}
