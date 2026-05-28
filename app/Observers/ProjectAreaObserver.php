<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\ProjectArea;
use App\Models\ProjectLine;

class ProjectAreaObserver
{
    public function created(ProjectArea $area): void
    {
        $project = $area->project;

        if (! $project || $area->project_revision_id !== $project->active_revision_id) {
            return;
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => $project->id,
            'action_type' => 'area.created',
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => $project->name,
            'payload' => ['area' => $area->name],
        ]);
    }

    public function deleting(ProjectArea $area): void
    {
        $project = $area->project;

        if (! $project || $area->project_revision_id !== $project->active_revision_id) {
            return;
        }

        // Capture lines before the DB cascade removes them.
        $lines = $area->lines
            ->map(fn (ProjectLine $line): array => array_filter([
                'code' => $line->code,
                'description' => $line->description,
                'qty' => $line->qty,
            ], fn ($v): bool => $v !== null && $v !== ''))
            ->values()
            ->all();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => $project->id,
            'action_type' => 'area.deleted',
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => $project->name,
            'payload' => [
                'area' => $area->name,
                'lines' => $lines,
            ],
        ]);
    }

    public function saved(ProjectArea $area): void
    {
        $this->touchProject($area);
    }

    public function deleted(ProjectArea $area): void
    {
        $this->touchProject($area);
    }

    private function touchProject(ProjectArea $area): void
    {
        $project = $area->project;

        if ($project && $area->project_revision_id === $project->active_revision_id) {
            $project->updateQuietly(['last_edited_at' => now(), 'last_edited_by' => auth()->id()]);
        }
    }
}
