<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Projects\Pages\Concerns\HasProjectSubNav;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use App\Services\ProjectRevisionValidator;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;

class ValidationProject extends ViewRecord
{
    use HasProjectSubNav;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.validation-project';

    protected static ?string $navigationLabel = 'Validation';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): string|HtmlString|null
    {
        $parts = array_filter([
            $this->record->customer_name,
            $this->record->contractor,
            $this->record->site_location,
        ]);

        if ($this->record->revision) {
            $parts[] = 'Rev '.$this->record->revision;
        }

        return new HtmlString(implode(' &middot; ', $parts));
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     type: string,
     *     area: string,
     *     code: string,
     *     description: string,
     *     message: string,
     *     line_ids: array<int, int>,
     *     approved: bool
     * }>
     */
    #[Computed]
    public function validationIssues(): array
    {
        return $this->validator()->issues($this->activeRevision());
    }

    #[Computed]
    public function activeRevisionValidated(): bool
    {
        return $this->activeRevision()->validated;
    }

    public function runValidation(): void
    {
        $this->refreshValidation();
    }

    public function approveIssue(string $issueKey): void
    {
        $issue = $this->findIssue($issueKey);

        $this->linesForIssue($issue)->update([
            'approved' => true,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        $this->refreshValidation();
    }

    public function undoIssueApproval(string $issueKey): void
    {
        $issue = $this->findIssue($issueKey);

        $this->linesForIssue($issue)->update([
            'approved' => false,
            'approved_at' => null,
            'approved_by' => null,
        ]);

        $this->refreshValidation();
    }

    public function mergeIssue(string $issueKey): void
    {
        $issue = $this->findIssue($issueKey);

        abort_unless($issue['type'] === 'duplicate_sku', 404);

        DB::transaction(function () use ($issue): void {
            $lines = $this->linesForIssue($issue)
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->get();

            /** @var ProjectLine $remainingLine */
            $remainingLine = $lines->firstOrFail();
            $remainingLine->update([
                'qty' => $lines->sum('qty'),
                'approved' => true,
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);

            $lines->skip(1)->each->delete();
        });

        $this->refreshValidation();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }

    private function activeRevision(): ProjectRevision
    {
        return $this->record->activeRevision()->firstOrFail();
    }

    /**
     * @return array{
     *     key: string,
     *     type: string,
     *     area: string,
     *     code: string,
     *     description: string,
     *     message: string,
     *     line_ids: array<int, int>,
     *     approved: bool
     * }
     */
    private function findIssue(string $issueKey): array
    {
        $issue = collect($this->validator()->issues($this->activeRevision()))
            ->firstWhere('key', $issueKey);

        abort_unless($issue, 404);

        return $issue;
    }

    /**
     * @param  array{line_ids: array<int, int>}  $issue
     */
    private function linesForIssue(array $issue): Builder
    {
        return ProjectLine::query()
            ->whereIn('id', $issue['line_ids'])
            ->whereHas('area', fn ($query) => $query
                ->where('project_id', $this->record->id)
                ->where('project_revision_id', $this->record->active_revision_id));
    }

    private function refreshValidation(): void
    {
        unset($this->validationIssues);
        $this->validator()->syncValidationStatus($this->activeRevision());
        unset($this->activeRevisionValidated);
        $this->record->load('activeRevision');
    }

    private function validator(): ProjectRevisionValidator
    {
        return app(ProjectRevisionValidator::class);
    }
}
