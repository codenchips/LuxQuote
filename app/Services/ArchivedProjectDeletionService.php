<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\ProjectVisibility;
use App\Models\ActivityLog;
use App\Models\DocumentPack;
use App\Models\PdfGeneration;
use App\Models\Project;
use App\Models\ReportingEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArchivedProjectDeletionService
{
    public function delete(Project $project, User $actor): void
    {
        abort_unless($actor->can('projects.view') && $actor->can('projects.delete-archived'), 403);

        $downloads = DB::transaction(function () use ($project, $actor): array {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless($actor->isAdministrator()
                || $lockedProject->visibility === ProjectVisibility::Open
                || (int) $lockedProject->user_id === (int) $actor->id
                || ($lockedProject->visibility === ProjectVisibility::Team
                    && $lockedProject->team?->users()->whereKey($actor->id)->exists()), 403);
            abort_unless($lockedProject->status === ProjectStatus::Archived, 409, 'Only archived projects can be deleted with this action.');

            $downloads = PdfGeneration::query()->where('project_id', $lockedProject->id)
                ->get()->flatMap(function (PdfGeneration $generation): array {
                    return collect([
                        $generation->result['token'] ?? null,
                        $generation->parameters['datasheet_token'] ?? null,
                    ])->filter()->map(fn (string $token): array => [
                        'token' => $token,
                        'user_id' => (int) $generation->user_id,
                    ])->all();
                })->all();

            $lockedProject->documentPacks()->get()->each(fn (DocumentPack $pack): ?bool => $pack->delete());
            ReportingEvent::query()->where('project_id', $lockedProject->id)->delete();
            ActivityLog::query()->where('project_id', $lockedProject->id)->delete();
            $lockedProject->delete();

            return $downloads;
        });

        foreach ($downloads as $download) {
            app(PdfDownloadUrlService::class)->revoke($download['token'], $download['user_id']);
        }
    }
}
