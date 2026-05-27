<?php

namespace App\Observers;

use App\Models\ProjectLine;

class ProjectLineObserver
{
    public function saved(ProjectLine $line): void
    {
        $this->touchProject($line);
    }

    public function deleted(ProjectLine $line): void
    {
        $this->touchProject($line);
    }

    private function touchProject(ProjectLine $line): void
    {
        $area = $line->area;

        if (! $area) {
            return;
        }

        $project = $area->project;

        if ($project && $area->project_revision_id === $project->active_revision_id) {
            $project->updateQuietly(['last_edited_at' => now()]);
        }
    }
}
