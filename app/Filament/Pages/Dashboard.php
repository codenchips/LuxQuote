<?php

namespace App\Filament\Pages;

use App\Enums\DocumentPackItemRole;
use App\Enums\ProjectVisibility;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ActivityLog;
use App\Models\DocumentPack;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Collection;

class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.pages.dashboard';

    public function getTitle(): string
    {
        $name = auth()->user()?->name;

        return filled($name) ? 'Welcome, '.$name : 'Welcome';
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    public function recentProjects(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->can('projects.view')) {
            return collect();
        }

        return ActivityLog::query()
            ->where('user_id', $user->id)
            ->whereNotNull('project_id')
            ->with(['project.activeRevision'])
            ->latest()
            ->limit(40)
            ->get()
            ->filter(fn (ActivityLog $log): bool => $log->project !== null && $this->canSeeProject($log->project, $user))
            ->unique('project_id')
            ->take(5)
            ->map(fn (ActivityLog $log): array => $this->projectRow($log->project, $log->created_at));
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    public function yourProjects(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->can('projects.view')) {
            return collect();
        }

        return Project::query()
            ->with('activeRevision')
            ->where('user_id', $user->id)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Project $project): array => $this->projectRow($project, $project->last_edited_at ?? $project->created_at));
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    public function recentSchedules(): Collection
    {
        return $this->recentOutputRows('schedule_pdf.generated', 'output.produce-unpriced-schedule', 'projects.pdf.schedule');
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    public function recentQuotes(): Collection
    {
        if (! $this->canViewQuotesPanel()) {
            return collect();
        }

        return $this->recentOutputRows('quote_pdf.generated', 'output.produce-quote', 'projects.pdf.quote');
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    public function recentDocumentPacks(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->can('output.produce-document-packs')) {
            return collect();
        }

        return ActivityLog::query()
            ->where('action_type', 'document_pack.generated')
            ->with(['project.revisions'])
            ->latest()
            ->limit(40)
            ->get()
            ->filter(fn (ActivityLog $log): bool => $log->project !== null && $this->canSeeProject($log->project, $user))
            ->map(function (ActivityLog $log) use ($user): ?array {
                $project = $log->project;
                $revision = $this->revisionForLog($log);
                $documentPackId = $log->payload['document_pack_id'] ?? null;

                if ($project === null || $revision === null || $documentPackId === null) {
                    return null;
                }

                $documentPack = DocumentPack::query()
                    ->where('project_id', $project->id)
                    ->find($documentPackId);

                if ($documentPack === null) {
                    return null;
                }

                if (! $this->canSeeDocumentPack($documentPack, $user)) {
                    return null;
                }

                return $this->outputRow(
                    $project,
                    $revision,
                    $log->created_at,
                    route('projects.document-packs.download', [
                        'project' => $project,
                        'documentPack' => $documentPack,
                        'revision' => $revision->id,
                    ]),
                    $documentPack->name,
                );
            })
            ->filter()
            ->take(5)
            ->values();
    }

    public function canViewQuotesPanel(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->can('pricing.view')
            && $user->can('output.produce-quote');
    }

    /**
     * @return array{name: string, email: string, avatarUrl: string|null, projectCount: int, profileUrl: string|null}
     */
    public function userSummary(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [
                'name' => '',
                'email' => '',
                'avatarUrl' => null,
                'projectCount' => 0,
                'profileUrl' => null,
            ];
        }

        return [
            'name' => $user->name,
            'email' => $user->email,
            'avatarUrl' => Filament::getUserAvatarUrl($user),
            'projectCount' => Project::query()->where('user_id', $user->id)->count(),
            'profileUrl' => Filament::getProfileUrl(),
        ];
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    private function recentOutputRows(string $actionType, string $permission, string $routeName): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->can($permission)) {
            return collect();
        }

        return ActivityLog::query()
            ->where('action_type', $actionType)
            ->with(['project.revisions'])
            ->latest()
            ->limit(40)
            ->get()
            ->filter(fn (ActivityLog $log): bool => $log->project !== null && $this->canSeeProject($log->project, $user))
            ->map(function (ActivityLog $log) use ($routeName): ?array {
                $project = $log->project;
                $revision = $this->revisionForLog($log);

                if ($project === null || $revision === null) {
                    return null;
                }

                return $this->outputRow(
                    $project,
                    $revision,
                    $log->created_at,
                    route($routeName, [
                        'project' => $project,
                        'revision' => $revision->id,
                    ]),
                );
            })
            ->filter()
            ->take(5)
            ->values();
    }

    private function projectRow(Project $project, mixed $lastAccessed): array
    {
        return [
            'name' => $project->name,
            'url' => ProjectResource::getUrl('view', ['record' => $project]),
            'revision' => $project->activeRevision?->label() ?? ProjectRevision::labelForNumber((int) $project->revision),
            'status' => $project->status?->label() ?? (string) $project->status,
            'statusColor' => match ($project->status?->value) {
                'in_progress' => 'info',
                'complete' => 'success',
                'cancelled' => 'danger',
                default => 'gray',
            },
            'visibility' => $project->visibility?->label() ?? (string) $project->visibility,
            'visibilityColor' => match ($project->visibility?->value) {
                'open' => 'success',
                'private' => 'warning',
                default => 'gray',
            },
            'lastAccessed' => $this->formatDateTime($lastAccessed),
        ];
    }

    private function outputRow(
        Project $project,
        ProjectRevision $revision,
        mixed $generatedAt,
        string $url,
        ?string $documentPackName = null,
    ): array {
        $row = [
            'project' => $project->name,
            'projectUrl' => ProjectResource::getUrl('view', ['record' => $project]),
            'revision' => $revision->label(),
            'generatedAt' => $this->formatDateTime($generatedAt),
            'url' => $url,
        ];

        if ($documentPackName !== null) {
            $row['documentPack'] = $documentPackName;
        }

        return $row;
    }

    private function revisionForLog(ActivityLog $log): ?ProjectRevision
    {
        if ($log->project === null) {
            return null;
        }

        return $log->project->revisions
            ->firstWhere('revision_number', $log->revision_number)
            ?? $log->project->activeRevision;
    }

    private function canSeeProject(Project $project, User $user): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        return $project->visibility === ProjectVisibility::Open || $project->user_id === $user->id;
    }

    private function canSeeDocumentPack(DocumentPack $documentPack, User $user): bool
    {
        if ($user->can('pricing.view')) {
            return true;
        }

        return ! $documentPack->items()
            ->where('role', DocumentPackItemRole::Quote->value)
            ->exists();
    }

    private function formatDateTime(mixed $date): string
    {
        if ($date === null) {
            return '';
        }

        return $date->format('M d Y H:i');
    }
}
