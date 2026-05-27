<?php

namespace App\Observers;

use App\Models\ProjectArea;

class ProjectAreaObserver
{
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
