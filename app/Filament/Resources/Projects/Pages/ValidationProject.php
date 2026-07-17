<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectRevisionStatus;
use App\Filament\Resources\Projects\Pages\Concerns\HasProjectSubNav;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ActivityLog;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use App\Services\ProjectRevisionValidator;
use App\Services\SalesforcePushControl;
use App\Services\SalesforceService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

    public bool $flagIssueModalOpen = false;

    public ?string $pendingFlagIssueKey = null;

    public ?int $pendingFlagLineId = null;

    public string $flagIssueNote = '';

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

        $parts[] = $this->projectRevisionLabelWithOwner($this->record->revision);

        return new HtmlString(implode(' &middot; ', $parts));
    }

    protected function getHeaderActions(): array
    {
        if ($this->activeRevisionApproved) {
            return [
                Action::make('unapproveRevision')
                    ->label('Unapprove Revision')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (): bool => $this->canApproveRevision())
                    ->requiresConfirmation()
                    ->modalHeading('Unapprove this revision?')
                    ->modalDescription('This will unlock the current revision and allow validation or schedule changes again.')
                    ->modalSubmitActionLabel('Unapprove revision')
                    ->action('unapproveRevision'),
            ];
        }

        return [
            Action::make('openApproveRevisionModal')
                ->label('Approve Revision')
                ->icon('heroicon-o-check-badge')
                ->color(fn (): string => $this->activeRevisionReadyForApproval ? 'success' : 'gray')
                ->visible(fn (): bool => $this->canApproveRevision())
                ->disabled(fn (): bool => ! $this->activeRevisionReadyForApproval)
                ->action('openApproveRevisionModal'),

            Action::make('runValidation')
                ->label('Run Validation')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->canRunValidation())
                ->action('runValidation'),
        ];
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
     *     flagged: bool,
     *     rrp?: string|null,
     *     quote_price?: string|null,
     *     unit_price?: string|null,
     *     net_price?: float|null,
     *     total_price?: float|null,
     *     flag_note?: string|null,
     *     cover_values?: array{cover_1: string|null, cover_2: string|null, cover_3: string|null},
     *     cover_defaults?: array{cover_1: string|null, cover_2: string|null, cover_3: string|null}
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
     *     cover_1: string|null,
     *     cover_2: string|null,
     *     cover_3: string|null,
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
                    'cover_1' => $line->cover_1,
                    'cover_2' => $line->cover_2,
                    'cover_3' => $line->cover_3,
                    'status' => 'Approved',
                    'note' => $this->validatedLineNote($line, $lineIssues),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{type: string, message: string}  $issue
     */
    public function validationIssueLabel(array $issue): string
    {
        return match ($issue['type']) {
            'cover_mismatch' => 'Cover value',
            'duplicate_sku' => 'Duplicate SKU',
            'manual_flag' => 'Issue reported',
            'price_mismatch' => 'Quote price',
            default => $this->issueLabelFromMessage($issue['message']),
        };
    }

    /**
     * @param  array{type: string}  $issue
     */
    public function validationIssueBadgeClasses(array $issue): string
    {
        return match ($issue['type']) {
            'cover_mismatch' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
            'duplicate_sku' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300',
            'manual_flag' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
            'price_mismatch' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
            default => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300',
        };
    }

    /**
     * @param  array{type: string}  $issue
     */
    public function validationIssueIcon(array $issue): string
    {
        return match ($issue['type']) {
            'cover_mismatch' => 'heroicon-o-adjustments-horizontal',
            'duplicate_sku' => 'heroicon-o-square-2-stack',
            'manual_flag' => 'heroicon-o-flag',
            'price_mismatch' => 'heroicon-o-currency-pound',
            default => 'heroicon-o-exclamation-circle',
        };
    }

    /**
     * @param  array{type: string}  $issue
     */
    public function validationIssueIconClasses(array $issue): string
    {
        return match ($issue['type']) {
            'cover_mismatch' => 'text-sky-500',
            'duplicate_sku' => 'text-purple-500',
            'manual_flag' => 'text-red-500',
            'price_mismatch' => 'text-amber-500',
            default => 'text-gray-500',
        };
    }

    /**
     * @param  array{type: string, code: string, message: string}  $issue
     */
    public function validationIssueMessage(array $issue): HtmlString
    {
        return $this->highlightValidationMessage($issue['message']);
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

    #[Computed]
    public function activeRevisionReadyForApproval(): bool
    {
        return $this->revisionReadyForApproval($this->activeRevision());
    }

    public function runValidation(): void
    {
        abort_unless($this->canRunValidation(), 403);

        $this->ensureActiveRevisionIsEditable();

        $this->refreshValidation();
    }

    public function openApproveRevisionModal(): void
    {
        abort_unless($this->canApproveRevision(), 403);
        abort_unless($this->revisionReadyForApproval($this->activeRevision()), 403);

        $this->approveRevisionModalOpen = true;
    }

    public function closeApproveRevisionModal(): void
    {
        $this->approveRevisionModalOpen = false;
    }

    public function approveRevision(): void
    {
        abort_unless($this->canApproveRevision(), 403);

        $revision = $this->activeRevision();

        abort_unless($this->revisionReadyForApproval($revision), 403);

        $this->validator()->syncValidationStatus($revision);
        $revision->refresh();

        abort_unless($revision->validated, 403);

        $revision->update([
            'status' => ProjectRevisionStatus::Approved,
        ]);

        $this->syncApprovedRevisionValueToSalesforce($revision);
        $this->record->syncStatusFromActiveRevision();
        $this->logValidationActivity('revision.approved', [
            'revision_label' => $revision->label(),
        ]);

        $this->approveRevisionModalOpen = false;
        unset($this->activeRevisionApproved);
        unset($this->activeRevisionValidated);
        unset($this->activeRevisionReadyForApproval);
        $this->record->load('activeRevision');
        $this->refreshHeaderActions();
    }

    public function unapproveRevision(): void
    {
        abort_unless($this->canApproveRevision(), 403);

        $revision = $this->activeRevision();

        abort_unless($revision->status === ProjectRevisionStatus::Approved, 403);

        $revision->update([
            'status' => ProjectRevisionStatus::Draft,
        ]);

        $this->record->syncStatusFromActiveRevision();
        $this->logValidationActivity('revision.unapproved', [
            'revision_label' => $revision->label(),
        ]);

        unset($this->activeRevisionApproved);
        unset($this->activeRevisionValidated);
        unset($this->activeRevisionReadyForApproval);
        $this->record->load('activeRevision');
        $this->refreshHeaderActions();
    }

    public function approveIssue(string $issueKey): void
    {
        abort_unless($this->canApproveValidationLines(), 403);

        $this->ensureActiveRevisionIsEditable();

        $issue = $this->findIssue($issueKey);
        $lines = $this->linesForIssue($issue)->get();
        $approvalNote = $this->approvalNote($issue);

        foreach ($lines as $line) {
            $line->update([
                'approved' => true,
                'approved_at' => now(),
                'approved_by' => auth()->id(),
                'validation_flagged' => false,
                'validation_note' => $this->appendValidationNote($line->validation_note, $approvalNote),
            ]);
        }

        $this->logValidationActivity('validation.issue_approved', $this->issueActivityPayload($issue, $lines));
        $this->refreshValidation();
    }

    public function undoIssueApproval(string $issueKey): void
    {
        abort_unless($this->canApproveValidationLines(), 403);

        $this->ensureActiveRevisionIsEditable();

        $issue = $this->findIssue($issueKey);
        $lines = $this->linesForIssue($issue)->get();
        $approvalNote = $this->approvalNote($issue);

        foreach ($lines as $line) {
            $validationNote = $this->removeValidationNote($line->validation_note, $approvalNote);

            $line->update([
                'approved' => filled($validationNote),
                'approved_at' => filled($validationNote) ? $line->approved_at : null,
                'approved_by' => filled($validationNote) ? $line->approved_by : null,
                'validation_note' => $validationNote,
            ]);
        }

        $this->logValidationActivity('validation.issue_approval_undone', $this->issueActivityPayload($issue, $lines));
        $this->refreshValidation();
    }

    public function updateIssueQuotePrice(string $issueKey, mixed $value): void
    {
        abort_unless($this->canUpdateValidationLines() && $this->canEditPrices(), 403);

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
        abort_unless($this->canUpdateValidationLines() && $this->canEditPrices(), 403);

        $this->ensureActiveRevisionIsEditable();

        $issue = $this->findIssue($issueKey);
        $lines = $this->linesForIssue($issue)->get();

        abort_unless($issue['type'] === 'price_mismatch' && ! $issue['approved'] && $issue['rrp'] !== null, 404);

        $this->linesForIssue($issue)->update([
            'unit_price' => number_format((float) $issue['rrp'], 2, '.', ''),
            'approved' => false,
            'approved_at' => null,
            'approved_by' => null,
            'validation_flagged' => false,
            'validation_note' => $this->resolutionNote($issue),
        ]);

        $this->logValidationActivity('validation.issue_matched', $this->issueActivityPayload($issue, $lines) + [
            'matched_price' => number_format((float) $issue['rrp'], 2, '.', ''),
        ]);
        $this->refreshValidation();
    }

    public function updateIssueCoverValue(string $issueKey, string $field, mixed $value): void
    {
        abort_unless($this->canUpdateValidationLines() && $this->canEditCover(), 403);
        abort_unless($this->projectHasCover(), 404);

        $this->ensureActiveRevisionIsEditable();

        abort_unless(in_array($field, ['cover_1', 'cover_2', 'cover_3'], true), 404);

        $issue = $this->findIssue($issueKey);

        abort_unless($issue['type'] === 'cover_mismatch' && ! $issue['approved'], 404);

        $this->linesForIssue($issue)->update([
            $field => $this->normaliseCoverValue($value),
            'approved' => false,
            'approved_at' => null,
            'approved_by' => null,
            'validation_flagged' => false,
            'validation_note' => null,
        ]);

        $this->refreshValidation();
    }

    public function openFlagIssueModal(string $issueKey): void
    {
        abort_unless($this->canFlagValidationLines(), 403);

        $this->ensureActiveRevisionIsEditable();

        $this->findIssue($issueKey);
        $this->pendingFlagIssueKey = $issueKey;
        $this->pendingFlagLineId = null;
        $this->flagIssueNote = '';
        $this->flagIssueModalOpen = true;
    }

    public function openFlagValidatedLineModal(int $lineId): void
    {
        abort_unless($this->canFlagValidationLines(), 403);

        $this->ensureActiveRevisionIsEditable();

        $this->lineForActiveRevision($lineId);
        $this->pendingFlagIssueKey = null;
        $this->pendingFlagLineId = $lineId;
        $this->flagIssueNote = '';
        $this->flagIssueModalOpen = true;
    }

    public function closeFlagIssueModal(): void
    {
        $this->resetFlagIssueModal();
    }

    public function submitFlagIssue(): void
    {
        abort_unless($this->canFlagValidationLines(), 403);

        $this->ensureActiveRevisionIsEditable();

        $note = trim($this->flagIssueNote);

        if ($note === '') {
            Notification::make()
                ->title('Flag note required')
                ->body('Add a short note before flagging this issue.')
                ->warning()
                ->send();

            return;
        }

        $note = mb_substr($note, 0, 255);

        if ($this->pendingFlagIssueKey !== null) {
            $this->flagExistingIssue($this->pendingFlagIssueKey, $note);
        } elseif ($this->pendingFlagLineId !== null) {
            $this->flagValidatedLineWithNote($this->pendingFlagLineId, $note);
        }

        $this->resetFlagIssueModal();
        $this->refreshValidation();
    }

    public function mergeIssue(string $issueKey): void
    {
        abort_unless($this->canMergeValidationLines(), 403);

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

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can('validation.view') ?? false;
    }

    private function issueLabelFromMessage(string $message): string
    {
        if (preg_match('/^([[:alpha:]]+)\s+([[:alpha:]]+)/u', $message, $matches) !== 1) {
            return 'Issue';
        }

        return "{$matches[1]} {$matches[2]}";
    }

    private function highlightValidationMessage(string $message): HtmlString
    {
        $message = trim((string) $message);

        if ($message === '') {
            return new HtmlString('');
        }

        $parts = preg_split('/("[^"]+")/u', $message, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return new HtmlString(e($message));
        }

        $message = collect($parts)
            ->map(fn (string $part): string => str_starts_with($part, '"') && str_ends_with($part, '"')
                ? $this->highlightValidationData($part)
                : e($part))
            ->implode('');

        return new HtmlString($message);
    }

    private function highlightValidationData(string $value): string
    {
        return '<span class="font-semibold text-sky-300">'.e($value).'</span>';
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
     *     quote_price?: string|null,
     *     flag_note?: string|null,
     *     cover_values?: array{cover_1: string|null, cover_2: string|null, cover_3: string|null},
     *     cover_defaults?: array{cover_1: string|null, cover_2: string|null, cover_3: string|null}
     * }
     */
    private function findIssue(string $issueKey): array
    {
        $issue = collect($this->validator()->issues($this->activeRevision()))
            ->firstWhere('key', $issueKey);

        abort_unless($issue, 404);

        return $issue;
    }

    private function flagExistingIssue(string $issueKey, string $note): void
    {
        $issue = $this->findIssue($issueKey);
        $lines = $this->linesForIssue($issue)->get();

        foreach ($lines as $line) {
            $line->update([
                'approved' => false,
                'approved_at' => null,
                'approved_by' => null,
                'validation_flagged' => true,
                'validation_note' => $note,
            ]);
        }

        $this->logValidationActivity('validation.issue_flagged', $this->issueActivityPayload($issue, $lines) + [
            'flag_note' => $note,
        ]);
    }

    private function flagValidatedLineWithNote(int $lineId, string $note): void
    {
        $line = $this->lineForActiveRevision($lineId);

        $line->update([
            'approved' => false,
            'approved_at' => null,
            'approved_by' => null,
            'validation_flagged' => true,
            'validation_note' => $note,
        ]);

        $this->logValidationActivity('validation.issue_flagged', [
            'issue_type' => 'manual_flag',
            'message' => 'Line flagged for review.',
            'flag_note' => $note,
            'line_count' => 1,
            'lines' => [$this->lineActivityPayload($line)],
        ]);
    }

    private function resetFlagIssueModal(): void
    {
        $this->flagIssueModalOpen = false;
        $this->pendingFlagIssueKey = null;
        $this->pendingFlagLineId = null;
        $this->flagIssueNote = '';
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
        return $this->lineQuery($this->activeRevision())
            ->findOrFail($lineId);
    }

    private function lineQuery(ProjectRevision $revision): Builder
    {
        return ProjectLine::query()
            ->whereHas('area', fn ($query) => $query
                ->where('project_id', $this->record->id)
                ->where('project_revision_id', $revision->id));
    }

    private function revisionReadyForApproval(ProjectRevision $revision): bool
    {
        return $revision->validated || (
            $this->validator()->unresolvedIssues($revision) === []
            && $this->lineQuery($revision)->exists()
            && $this->lineQuery($revision)->where('approved', false)->doesntExist()
        );
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
        return 'Approved: '.$issue['message'];
    }

    private function appendValidationNote(?string $currentNote, string $newNote): string
    {
        $notes = collect(preg_split('/\s*\|\s*/', (string) $currentNote, -1, PREG_SPLIT_NO_EMPTY))
            ->push($newNote)
            ->unique()
            ->values();

        return $notes->implode(' | ');
    }

    private function removeValidationNote(?string $currentNote, string $noteToRemove): ?string
    {
        $notes = collect(preg_split('/\s*\|\s*/', (string) $currentNote, -1, PREG_SPLIT_NO_EMPTY))
            ->reject(fn (string $note): bool => $note === $noteToRemove)
            ->values();

        return $notes->isEmpty() ? null : $notes->implode(' | ');
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

    private function normaliseCoverValue(mixed $value): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return number_format(min(999.99, max(0, (float) $value)), 2, '.', '');
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

        return 'Approved: no current validation issues.';
    }

    private function refreshValidation(): void
    {
        unset($this->validationIssues);
        unset($this->validatedLines);
        $this->validator()->syncValidationStatus($this->activeRevision());
        unset($this->activeRevisionValidated);
        unset($this->activeRevisionApproved);
        unset($this->activeRevisionReadyForApproval);
        $this->record->load('activeRevision');
    }

    /**
     * @param  array{type: string, message: string}  $issue
     * @param  Collection<int, ProjectLine>  $lines
     * @return array{issue_type: string, message: string, line_count: int, lines: array<int, array{id: int, code: string, description: string}>}
     */
    private function issueActivityPayload(array $issue, Collection $lines): array
    {
        return [
            'issue_type' => $issue['type'],
            'message' => $issue['message'],
            'line_count' => $lines->count(),
            'lines' => $lines
                ->map(fn (ProjectLine $line): array => $this->lineActivityPayload($line))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{id: int, code: string, description: string}
     */
    private function lineActivityPayload(ProjectLine $line): array
    {
        return [
            'id' => $line->id,
            'code' => (string) $line->code,
            'description' => (string) $line->description,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function logValidationActivity(string $actionType, ?array $payload = null): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => $this->record->id,
            'action_type' => $actionType,
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => $this->record->name,
            'revision_number' => $this->activeRevision()->revision_number,
            'payload' => $payload,
        ]);
    }

    private function syncApprovedRevisionValueToSalesforce(ProjectRevision $revision): void
    {
        if (! $this->record->salesforce_project) {
            return;
        }

        if (app(SalesforcePushControl::class)->disabled()) {
            Notification::make()
                ->title('Salesforce value update skipped')
                ->body('Salesforce pushes are currently paused.')
                ->warning()
                ->send();

            return;
        }

        $total = $this->revisionTotal($revision);
        $result = app(SalesforceService::class)->updateOpportunityAmount($this->record, $total);

        if (! $result['success']) {
            Notification::make()
                ->title('Salesforce value update failed')
                ->body($result['message'] ?? 'The Opportunity amount could not be updated.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Salesforce value updated')
            ->body('Opportunity Amount updated to £'.number_format($total, 2).'.')
            ->success()
            ->send();
    }

    private function revisionTotal(ProjectRevision $revision): float
    {
        return (float) $revision->areas()
            ->with('lines')
            ->get()
            ->flatMap->lines
            ->sum(fn (ProjectLine $line): float => (float) ($line->qty ?? 0) * (float) ($line->unit_price ?? 0));
    }

    private function refreshHeaderActions(): void
    {
        $this->cachedHeaderActions = [];
        $this->cacheInteractsWithHeaderActions();
    }

    private function validator(): ProjectRevisionValidator
    {
        return app(ProjectRevisionValidator::class);
    }

    public function canViewPrices(): bool
    {
        return auth()->user()?->can('pricing.view') ?? false;
    }

    public function canEditPrices(): bool
    {
        return auth()->user()?->can('pricing.update') ?? false;
    }

    public function canEditCover(): bool
    {
        return $this->canViewPrices() && (auth()->user()?->can('cover.update') ?? false);
    }

    public function projectHasCover(): bool
    {
        return (bool) $this->record->has_cover;
    }

    public function canRunValidation(): bool
    {
        return auth()->user()?->can('validation.run') ?? false;
    }

    public function canUpdateValidationLines(): bool
    {
        return auth()->user()?->can('validation.update-lines') ?? false;
    }

    public function canFlagValidationLines(): bool
    {
        return auth()->user()?->can('validation.flag-lines') ?? false;
    }

    public function canMergeValidationLines(): bool
    {
        return auth()->user()?->can('validation.merge-lines') ?? false;
    }

    public function canApproveValidationLines(): bool
    {
        return auth()->user()?->can('validation.approve-lines') ?? false;
    }

    public function canApproveRevision(): bool
    {
        return auth()->user()?->can('revisions.approve') ?? false;
    }
}
