<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectRevisionStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Projects\Pages\Concerns\HasProjectSubNav;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use App\Services\ProjectRevisionValidator;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    public bool $approveRevisionModalOpen = false;

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
     *     approved: bool,
     *     rrp?: string|null,
     *     quote_price?: string|null
     * }>
     */
    #[Computed]
    public function validationIssues(): array
    {
        return $this->validator()->issues($this->activeRevision());
    }

    /**
     * @return array<int, array{
     *     id: int,
     *     code: string,
     *     description: string,
     *     qty: int,
     *     unit_price: string|null,
     *     status: string,
     *     note: string
     * }>
     */
    #[Computed]
    public function validatedLines(): array
    {
        $revision = $this->activeRevision();
        $issues = collect($this->validationIssues);
        $unresolvedLineIds = $issues
            ->where('approved', false)
            ->flatMap(fn (array $issue): array => $issue['line_ids'])
            ->unique()
            ->values();

        return $revision->areas()
            ->with(['lines' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->flatMap->lines
            ->reject(fn (ProjectLine $line): bool => $unresolvedLineIds->contains($line->id))
            ->map(function (ProjectLine $line) use ($issues): array {
                $lineIssues = $issues->filter(
                    fn (array $issue): bool => in_array($line->id, $issue['line_ids'], true)
                );

                return [
                    'id' => $line->id,
                    'code' => $line->code ?? '',
                    'description' => $line->description,
                    'qty' => $line->qty,
                    'unit_price' => $line->unit_price,
                    'status' => $lineIssues->where('approved', true)->isNotEmpty() ? 'Approved' : 'Resolved',
                    'note' => $this->validatedLineNote($line, $lineIssues),
                ];
            })
            ->values()
            ->all();
    }

    #[Computed]
    public function activeRevisionValidated(): bool
    {
        return $this->activeRevision()->validated;
    }

    #[Computed]
    public function activeRevisionApproved(): bool
    {
        return $this->activeRevision()->status === ProjectRevisionStatus::Approved;
    }

    public function runValidation(): void
    {
        $this->ensureActiveRevisionIsEditable();

        $this->refreshValidation();
    }

    public function openApproveRevisionModal(): void
    {
        abort_unless($this->activeRevision()->validated, 403);

        $this->approveRevisionModalOpen = true;
    }

    public function closeApproveRevisionModal(): void
    {
        $this->approveRevisionModalOpen = false;
    }

    public function approveRevision(): void
    {
        $revision = $this->activeRevision();

        abort_unless($revision->validated, 403);

        $revision->update([
            'status' => ProjectRevisionStatus::Approved,
        ]);

        $this->approveRevisionModalOpen = false;
        unset($this->activeRevisionApproved);
        unset($this->activeRevisionValidated);
        $this->record->load('activeRevision');
    }

    public function approveIssue(string $issueKey): void
    {
        $this->ensureActiveRevisionIsEditable();

        $issue = $this->findIssue($issueKey);

        $this->linesForIssue($issue)->update([
            'approved' => true,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
            'validation_flagged' => false,
            'validation_note' => $this->approvalNote($issue),
        ]);

        $this->refreshValidation();
    }

    public function undoIssueApproval(string $issueKey): void
    {
        $this->ensureActiveRevisionIsEditable();

        $issue = $this->findIssue($issueKey);

        $this->linesForIssue($issue)->update([
            'approved' => false,
            'approved_at' => null,
            'approved_by' => null,
            'validation_note' => null,
        ]);

        $this->refreshValidation();
    }

    public function updateIssueQuotePrice(string $issueKey, mixed $value): void
    {
        $this->ensureActiveRevisionIsEditable();

        $issue = $this->findIssue($issueKey);

        abort_unless($issue['type'] === 'price_mismatch' && ! $issue['approved'], 404);

        $quotePrice = $value === '' || $value === null
            ? null
            : number_format(max(0, (float) $value), 2, '.', '');

        $this->linesForIssue($issue)->update([
            'unit_price' => $quotePrice,
            'approved' => false,
            'approved_at' => null,
            'approved_by' => null,
            'validation_flagged' => false,
            'validation_note' => $this->quotePriceResolvesIssue($issue, $quotePrice) ? $this->resolutionNote($issue) : null,
        ]);

        $this->refreshValidation();
    }

    public function matchIssueQuotePrice(string $issueKey): void
    {
        $this->ensureActiveRevisionIsEditable();

        $issue = $this->findIssue($issueKey);

        abort_unless($issue['type'] === 'price_mismatch' && ! $issue['approved'] && $issue['rrp'] !== null, 404);

        $this->linesForIssue($issue)->update([
            'unit_price' => number_format((float) $issue['rrp'], 2, '.', ''),
            'approved' => false,
            'approved_at' => null,
            'approved_by' => null,
            'validation_flagged' => false,
            'validation_note' => $this->resolutionNote($issue),
        ]);

        $this->refreshValidation();
    }

    public function mergeIssue(string $issueKey): void
    {
        $this->ensureActiveRevisionIsEditable();

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
                'validation_flagged' => false,
                'validation_note' => $this->resolutionNote($issue),
            ]);

            $lines->skip(1)->each->delete();
        });

        $this->refreshValidation();
    }

    public function flagValidatedLine(int $lineId): void
    {
        $this->ensureActiveRevisionIsEditable();

        $line = $this->lineForActiveRevision($lineId);
        $approvedIssues = collect($this->validator()->issues($this->activeRevision()))
            ->filter(fn (array $issue): bool => $issue['approved'] && in_array($line->id, $issue['line_ids'], true));

        $line->update([
            'approved' => false,
            'approved_at' => null,
            'approved_by' => null,
            'validation_flagged' => $line->validation_flagged || $approvedIssues->isEmpty(),
            'validation_note' => null,
        ]);

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

    private function ensureActiveRevisionIsEditable(): void
    {
        abort_if($this->activeRevision()->status === ProjectRevisionStatus::Approved, 403, 'Approved revisions are locked against editing.');
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
     *     approved: bool,
     *     rrp?: string|null,
     *     quote_price?: string|null
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

    private function lineForActiveRevision(int $lineId): ProjectLine
    {
        return ProjectLine::query()
            ->whereHas('area', fn ($query) => $query
                ->where('project_id', $this->record->id)
                ->where('project_revision_id', $this->record->active_revision_id))
            ->findOrFail($lineId);
    }

    /**
     * @param  array{message: string}  $issue
     */
    private function approvalNote(array $issue): string
    {
        return 'Approved: '.$issue['message'];
    }

    /**
     * @param  array{message: string}  $issue
     */
    private function resolutionNote(array $issue): string
    {
        return 'Resolved: '.$issue['message'];
    }

    /**
     * @param  array{rrp?: string|null}  $issue
     */
    private function quotePriceResolvesIssue(array $issue, ?string $quotePrice): bool
    {
        if ($quotePrice === null || ($issue['rrp'] ?? null) === null) {
            return false;
        }

        return number_format((float) $quotePrice, 2, '.', '') === number_format((float) $issue['rrp'], 2, '.', '');
    }

    private function validatedLineNote(ProjectLine $line, Collection $lineIssues): string
    {
        if (filled($line->validation_note)) {
            return (string) $line->validation_note;
        }

        $approvedMessages = $lineIssues
            ->where('approved', true)
            ->pluck('message');

        if ($approvedMessages->isNotEmpty()) {
            return 'Approved: '.$approvedMessages->implode(' ');
        }

        return 'Resolved: no current validation issues.';
    }

    private function refreshValidation(): void
    {
        unset($this->validationIssues);
        unset($this->validatedLines);
        $this->validator()->syncValidationStatus($this->activeRevision());
        unset($this->activeRevisionValidated);
        unset($this->activeRevisionApproved);
        $this->record->load('activeRevision');
    }

    private function validator(): ProjectRevisionValidator
    {
        return app(ProjectRevisionValidator::class);
    }
}
