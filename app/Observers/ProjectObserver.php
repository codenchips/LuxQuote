<?php

namespace App\Observers;

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

    public function updating(Project $project): void
    {
        $meaningful = array_diff_key($project->getDirty(), array_flip(self::META_KEYS));

        if (! empty($meaningful)) {
            $project->last_edited_at = Carbon::now();
            $project->last_edited_by = auth()->id();
        }
    }
}
