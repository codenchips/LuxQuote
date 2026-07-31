<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use App\Enums\ProjectRevisionStatus;
use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\Pages\Concerns\HasProjectSubNav;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ActivityLog;
use App\Models\ActivityLogArchive;
use App\Models\DocumentPack;
use App\Models\DocumentPackItem;
use App\Models\ProjectArea;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use App\Services\DocumentPackPdfService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class OutputProject extends ViewRecord
{
    use HasProjectSubNav, WithFileUploads;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.output-project';

    protected static ?string $navigationLabel = 'Output';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    public ?int $selectedDocumentPackId = null;

    public string $documentPackName = '';

    /** @var array<string, array{key: string, id: int|null, role: string, file_path: string|null, original_filename: string|null}> */
    public array $documentPackItems = [];

    /** @var array<string, TemporaryUploadedFile> */
    public array $documentPackUploads = [];

    /** @var array<string, string> */
    public array $documentPackUploadOriginalNames = [];

    /** @var array<string, bool> */
    public array $editingDocumentPackRoleKeys = [];

    /** @var array<string, string> */
    public array $originalDocumentPackRoleValues = [];

    /** @var array<string, string> */
    public array $originalDocumentPackUploadFilenames = [];

    public bool $documentPackDirty = false;

    public ?int $generationRevisionId = null;

    public string $outputTab = 'single';

    public bool $includeQuoteDatasheets = false;

    public bool $includeScheduleDatasheets = false;

    public string $outputHistorySearch = '';

    public int $outputHistoryPage = 1;

    public int $outputHistoryPerPage = 10;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->generationRevisionId = $this->record->active_revision_id;

        if (! $this->canManageDocumentPacks()) {
            return;
        }

        $firstPack = $this->record->documentPacks()->first();

        if ($firstPack !== null) {
            $this->loadDocumentPack($firstPack->id);

            return;
        }

        $this->newDocumentPack();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can('output.view') ?? false;
    }

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): string|HtmlString|null
    {
        $parts = array_filter([
            $this->record->visibility?->label(),
            $this->projectRevisionLabelWithOwner($this->record->revision),
        ]);

        return new HtmlString(implode(' &middot; ', $parts));
    }

    public function getSchedulePdfUrl(): string
    {
        abort_unless($this->canProduceUnpricedSchedule(), 403);

        $parameters = [
            'project' => $this->record,
            'revision' => $this->record->active_revision_id,
            'salesforce_upload' => true,
        ];

        if ($this->includeScheduleDatasheets) {
            $parameters['include_datasheets'] = true;
        }

        return route('projects.pdf.schedule', $parameters);
    }

    public function getQuotePdfUrl(): string
    {
        abort_unless($this->canProduceQuote(), 403);

        $parameters = [
            'project' => $this->record,
            'revision' => $this->record->active_revision_id,
            'salesforce_upload' => true,
        ];

        if ($this->includeQuoteDatasheets) {
            $parameters['include_datasheets'] = true;
        }

        return route('projects.pdf.quote', $parameters);
    }

    public function getPreparedQuoteUrl(): string
    {
        abort_unless($this->canProduceQuote(), 403);

        return route('projects.pdf.quote.prepare', [
            'project' => $this->record,
            'revision' => $this->record->active_revision_id,
        ]);
    }

    public function getPreparedQuoteDatasheetsUrl(): string
    {
        abort_unless($this->canProduceQuote(), 403);

        return route('projects.pdf.quote.datasheets.prepare', [
            'project' => $this->record,
            'revision' => $this->record->active_revision_id,
        ]);
    }

    public function getPreparedQuoteZipUrl(): string
    {
        abort_unless($this->canProduceQuote(), 403);

        return route('projects.pdf.quote.zip', [
            'project' => $this->record,
            'revision' => $this->record->active_revision_id,
        ]);
    }

    /**
     * @return array<int, array{id: int, name: string, items: int, qty: int, price: string|null, net: string|null}>
     */
    public function outputAreaOptions(): array
    {
        $canViewPricing = auth()->user()?->can('pricing.view') ?? false;

        return ProjectArea::where('project_revision_id', $this->record->active_revision_id)
            ->with(['lines' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ProjectArea $area): array => [
                'id' => $area->id,
                'name' => $area->name,
                'items' => $area->lines->count(),
                'qty' => (int) $area->line_total_qty,
                'price' => $canViewPricing ? '£'.number_format($area->line_total, 2) : null,
                'net' => $canViewPricing && $this->record->has_cover
                    ? '£'.number_format($area->lines->sum(fn (ProjectLine $line): float => $line->netLineTotalForProject($this->record)), 2)
                    : null,
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, city: string|null, is_primary: bool}>
     */
    public function quoteTenderOptions(): array
    {
        return $this->record->tenders()
            ->orderByDesc('is_primary')
            ->orderBy('account_name')
            ->get()
            ->map(fn ($tender): array => [
                'id' => $tender->id,
                'name' => $tender->account_name,
                'city' => $tender->billing_city,
                'is_primary' => (bool) $tender->is_primary,
            ])
            ->all();
    }

    /**
     * @return array<int, array{user: string, revision: string, type: string, type_classes: string, scope: string, included_datasheets: bool, tender: string|null, filename: string, generated_at: string, regenerate_url: string|null}>
     */
    public function outputHistoryRows(): array
    {
        $rows = $this->filteredOutputHistoryRows();
        $page = $this->outputHistoryCurrentPage();

        return array_slice($rows, ($page - 1) * $this->outputHistoryPerPage, $this->outputHistoryPerPage);
    }

    public function outputHistoryTotalRows(): int
    {
        return count($this->filteredOutputHistoryRows());
    }

    public function outputHistoryTotalPages(): int
    {
        return max(1, (int) ceil($this->outputHistoryTotalRows() / $this->outputHistoryPerPage));
    }

    public function outputHistoryCurrentPage(): int
    {
        return min(max(1, $this->outputHistoryPage), $this->outputHistoryTotalPages());
    }

    public function updatedOutputHistorySearch(): void
    {
        $this->outputHistoryPage = 1;
    }

    public function previousOutputHistoryPage(): void
    {
        $this->outputHistoryPage = max(1, $this->outputHistoryPage - 1);
    }

    public function nextOutputHistoryPage(): void
    {
        $this->outputHistoryPage = min($this->outputHistoryTotalPages(), $this->outputHistoryPage + 1);
    }

    /**
     * @return array<int, array{user: string, revision: string, type: string, type_classes: string, scope: string, included_datasheets: bool, tender: string|null, filename: string, generated_at: string, regenerate_url: string|null}>
     */
    private function filteredOutputHistoryRows(): array
    {
        $rows = $this->allOutputHistoryRows();
        $search = Str::of($this->outputHistorySearch)->lower()->squish()->toString();

        if ($search === '') {
            return $rows;
        }

        return collect($rows)
            ->filter(function (array $row) use ($search): bool {
                $haystack = Str::of(implode(' ', [
                    $row['user'],
                    $row['revision'],
                    $row['type'],
                    $row['scope'],
                    $row['tender'] ?? '',
                    $row['included_datasheets'] ? 'datasheets yes included' : 'datasheets no',
                    $row['filename'],
                    $row['generated_at'],
                ]))->lower()->toString();

                return str_contains($haystack, $search);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{user: string, revision: string, type: string, type_classes: string, scope: string, included_datasheets: bool, tender: string|null, filename: string, generated_at: string, regenerate_url: string|null}>
     */
    private function allOutputHistoryRows(): array
    {
        if (! $this->canViewOutputHistory()) {
            return [];
        }

        $revisions = $this->record->revisions()
            ->get(['id', 'revision_number'])
            ->keyBy('revision_number');

        $liveLogs = ActivityLog::query()
            ->where('project_id', $this->record->id)
            ->whereIn('action_type', ['quote_pdf.generated', 'schedule_pdf.generated'])
            ->with('user:id,name,email')
            ->latest()
            ->limit(100)
            ->get();

        $archivedLogs = ActivityLogArchive::query()
            ->where('project_id', $this->record->id)
            ->whereIn('action_type', ['quote_pdf.generated', 'schedule_pdf.generated'])
            ->with('user:id,name,email')
            ->latest('created_at')
            ->limit(100)
            ->get();

        return $liveLogs
            ->concat($archivedLogs)
            ->sortByDesc('created_at')
            ->take(100)
            ->values()
            ->map(function (ActivityLog|ActivityLogArchive $log) use ($revisions): array {
                $payload = $log->payload ?? [];
                $isQuote = $log->action_type === 'quote_pdf.generated';
                $revisionNumber = (int) ($payload['revision_number'] ?? $log->revision_number ?? 0);
                $revision = $revisions->get($revisionNumber);
                $areaIds = collect($payload['area_ids'] ?? [])
                    ->map(fn (mixed $areaId): int => (int) $areaId)
                    ->filter(fn (int $areaId): bool => $areaId > 0)
                    ->values()
                    ->all();
                $areaCount = $areaIds === []
                    ? null
                    : (int) ($payload['area_count'] ?? count($areaIds));

                return [
                    'user' => $log->user?->name ?: Str::before($log->user_email_snapshot, '@'),
                    'revision' => (string) ($payload['revision_label'] ?? ($revision?->label() ?? ProjectRevision::labelForNumber($revisionNumber))),
                    'type' => $isQuote ? 'Quote' : 'Schedule',
                    'type_classes' => $isQuote
                        ? 'border-sky-500/30 bg-sky-500/15 text-sky-200'
                        : 'border-emerald-500/30 bg-emerald-500/15 text-emerald-200',
                    'scope' => $areaCount === null ? 'Full Project' : $areaCount.' '.Str::plural('Area', $areaCount),
                    'included_datasheets' => (bool) ($payload['include_datasheets'] ?? str_contains((string) ($payload['filename'] ?? ''), 'with-datasheets')),
                    'tender' => filled($payload['tender_account_name'] ?? null)
                        ? (string) $payload['tender_account_name']
                        : (filled($payload['tender'] ?? null) ? (string) $payload['tender'] : null),
                    'filename' => (string) ($payload['filename'] ?? ''),
                    'generated_at' => $this->formatOutputHistoryDate($log->created_at),
                    'regenerate_url' => $this->outputHistoryRegenerateUrl($log, $revision?->id, $areaIds),
                ];
            })
            ->all();
    }

    public function getCsvExportUrl(): string
    {
        abort_unless($this->canProducePricedSchedule(), 403);

        return route('projects.export.csv', [
            'project' => $this->record,
            'revision' => $this->record->active_revision_id,
        ]);
    }

    public function getUnpricedCsvExportUrl(): string
    {
        abort_unless($this->canProduceUnpricedSchedule(), 403);

        return route('projects.export.unpriced-csv', [
            'project' => $this->record,
            'revision' => $this->record->active_revision_id,
        ]);
    }

    public function requestQuoteApproval(): void
    {
        abort_unless($this->canRequestQuoteApproval(), 403);
        abort_if($this->quoteApproved(), 403, 'Approved revisions do not need approval requests.');

        $this->record->markApprovalRequested();
        $this->record->refresh();
        $this->record->load('activeRevision');

        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => $this->record->id,
            'action_type' => 'quote_approval.requested',
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => $this->record->name,
            'revision_number' => $this->activeRevision()->revision_number,
            'payload' => [
                'revision_label' => $this->activeRevision()->label(),
            ],
        ]);

        Notification::make()
            ->title('Quote approval requested')
            ->success()
            ->send();
    }

    #[Computed]
    public function documentPacks(): Collection
    {
        return $this->record->documentPacks()->withCount('items')->get();
    }

    #[Computed]
    public function projectRevisions(): Collection
    {
        return $this->record->revisions()->get();
    }

    /** @return array<string, string> */
    public function documentPackRoleOptions(): array
    {
        return collect(DocumentPackItemRole::cases())
            ->filter(fn (DocumentPackItemRole $role): bool => $role->selectableInBuilder())
            ->filter(fn (DocumentPackItemRole $role): bool => $this->canUseDocumentRole($role))
            ->mapWithKeys(fn (DocumentPackItemRole $role): array => [$role->value => $role->label()])
            ->all();
    }

    public function documentPackRoleDescription(string $role): string
    {
        return DocumentPackItemRole::tryFrom($role)?->description() ?? 'Choose the document to include in this position.';
    }

    public function documentPackRoleRequiresUpload(string $role): bool
    {
        return DocumentPackItemRole::tryFrom($role)?->source() === DocumentPackItemSource::Uploaded;
    }

    public function documentPackGeneratedSummary(string $role): ?string
    {
        $documentRole = DocumentPackItemRole::tryFrom($role);

        if ($documentRole?->source() !== DocumentPackItemSource::Generated) {
            return null;
        }

        $revision = $this->generationRevision();

        if ($revision === null) {
            return 'No revision selected';
        }

        $totals = ProjectLine::query()
            ->whereHas('area', fn ($query) => $query->where('project_revision_id', $revision->id))
            ->selectRaw('COUNT(*) as item_count, COALESCE(SUM(qty), 0) as qty_total')
            ->first();

        $itemCount = (int) ($totals?->item_count ?? 0);
        $quantityTotal = (int) ($totals?->qty_total ?? 0);

        return $revision->label().' - '.$itemCount." SKU's, ".$quantityTotal.' Items';
    }

    public function documentPackGeneratedModifiedAt(string $role): ?string
    {
        $documentRole = DocumentPackItemRole::tryFrom($role);

        if ($documentRole?->source() !== DocumentPackItemSource::Generated) {
            return null;
        }

        $revision = $this->generationRevision();

        if ($revision === null) {
            return null;
        }

        $lastModifiedAt = ProjectLine::query()
            ->whereHas('area', fn ($query) => $query->where('project_revision_id', $revision->id))
            ->max('project_lines.updated_at');

        return ($lastModifiedAt !== null ? Carbon::parse($lastModifiedAt) : $revision->updated_at)?->format('d/m/y H:i');
    }

    /**
     * @param  array{key: string, id: int|null, role: string, file_path: string|null, original_filename: string|null}  $item
     */
    public function documentPackItemPdfUrl(array $item): ?string
    {
        $role = DocumentPackItemRole::tryFrom($item['role'] ?? '');

        if ($role?->source() !== DocumentPackItemSource::Uploaded) {
            return null;
        }

        $upload = $this->documentPackUploads[$item['key']] ?? null;

        if ($upload instanceof TemporaryUploadedFile && ! $this->documentPackUploadAppliesToCurrentRole($item, $upload)) {
            return null;
        }

        if ($upload instanceof TemporaryUploadedFile) {
            return null;
        }

        if (! $upload instanceof TemporaryUploadedFile && ! $this->documentPackExistingFileAppliesToCurrentRole($item)) {
            return null;
        }

        if ($this->selectedDocumentPackId === null || blank($item['id'] ?? null) || blank($item['file_path'] ?? null)) {
            return null;
        }

        return route('projects.document-packs.items.file', [
            'project' => $this->record,
            'documentPack' => $this->selectedDocumentPackId,
            'documentPackItem' => $item['id'],
        ]);
    }

    /**
     * @param  array{key: string, id: int|null, role: string, file_path: string|null, original_filename: string|null}  $item
     */
    public function documentPackItemHasActiveUpload(array $item): bool
    {
        $upload = $this->documentPackUploads[$item['key']] ?? null;

        return $upload instanceof TemporaryUploadedFile
            && $this->documentPackUploadAppliesToCurrentRole($item, $upload);
    }

    /**
     * @param  array{key: string, id: int|null, role: string, file_path: string|null, original_filename: string|null}  $item
     */
    public function documentPackItemHasVisibleExistingFile(array $item): bool
    {
        return filled($item['original_filename'] ?? null)
            && $this->documentPackExistingFileAppliesToCurrentRole($item);
    }

    public function newDocumentPack(): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        $this->selectedDocumentPackId = null;
        $this->documentPackName = '';
        $item = $this->emptyDocumentPackItem();
        $this->documentPackItems = [$item['key'] => $item];
        $this->documentPackUploads = [];
        $this->documentPackUploadOriginalNames = [];
        $this->editingDocumentPackRoleKeys = [];
        $this->originalDocumentPackRoleValues = [];
        $this->originalDocumentPackUploadFilenames = [];
        $this->documentPackDirty = true;
    }

    public function loadDocumentPack(int|string $documentPackId): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        $documentPack = $this->record->documentPacks()
            ->with('items')
            ->findOrFail((int) $documentPackId);

        $this->selectedDocumentPackId = $documentPack->id;
        $this->documentPackName = $documentPack->name;
        $this->documentPackItems = $documentPack->items
            ->mapWithKeys(function (DocumentPackItem $item): array {
                $key = 'item-'.$item->id;

                return [$key => [
                    'key' => $key,
                    'id' => $item->id,
                    'role' => $item->role->value,
                    'file_path' => $item->file_path,
                    'original_filename' => $item->original_filename,
                ]];
            })
            ->all();
        $this->documentPackUploads = [];
        $this->documentPackUploadOriginalNames = [];
        $this->editingDocumentPackRoleKeys = [];
        $this->originalDocumentPackRoleValues = [];
        $this->originalDocumentPackUploadFilenames = [];
        $this->documentPackDirty = false;
    }

    public function addDocumentPackItem(?string $afterKey = null): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        $newItem = $this->emptyDocumentPackItem();

        if ($afterKey === null || ! array_key_exists($afterKey, $this->documentPackItems)) {
            $this->documentPackItems[$newItem['key']] = $newItem;
        } else {
            $items = [];

            foreach ($this->documentPackItems as $key => $item) {
                $items[$key] = $item;

                if ($key === $afterKey) {
                    $items[$newItem['key']] = $newItem;
                }
            }

            $this->documentPackItems = $items;
        }

        $this->documentPackDirty = true;
    }

    public function removeDocumentPackItem(string $key): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        unset($this->documentPackItems[$key]);
        unset($this->documentPackUploads[$key]);
        unset($this->documentPackUploadOriginalNames[$key]);
        unset($this->editingDocumentPackRoleKeys[$key]);
        unset($this->originalDocumentPackRoleValues[$key]);
        unset($this->originalDocumentPackUploadFilenames[$key]);
        $this->documentPackDirty = true;
    }

    public function clearDocumentPackUpload(string $key): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        if (! array_key_exists($key, $this->documentPackItems)) {
            return;
        }

        unset($this->documentPackUploads[$key], $this->documentPackUploadOriginalNames[$key]);
        $this->documentPackDirty = true;
    }

    public function sortDocumentPackItem(string $key, int $position): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        if (! array_key_exists($key, $this->documentPackItems)) {
            return;
        }

        $item = $this->documentPackItems[$key];
        unset($this->documentPackItems[$key]);
        $position = max(0, min($position, count($this->documentPackItems)));
        $before = array_slice($this->documentPackItems, 0, $position, true);
        $after = array_slice($this->documentPackItems, $position, null, true);
        $this->documentPackItems = $before + [$key => $item] + $after;
        $this->documentPackDirty = true;
    }

    public function markDocumentPackDirty(): void
    {
        $this->documentPackDirty = true;
    }

    public function startEditingDocumentPackRole(string $key): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        if (! array_key_exists($key, $this->documentPackItems)) {
            return;
        }

        $this->originalDocumentPackRoleValues[$key] = $this->documentPackItems[$key]['role'];

        $upload = $this->documentPackUploads[$key] ?? null;

        if ($upload instanceof TemporaryUploadedFile) {
            $this->originalDocumentPackUploadFilenames[$key] = $upload->getFilename();
        } else {
            unset($this->originalDocumentPackUploadFilenames[$key]);
        }

        $this->editingDocumentPackRoleKeys[$key] = true;
    }

    public function cancelEditingDocumentPackRole(string $key): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        if (! array_key_exists($key, $this->documentPackItems)) {
            return;
        }

        if (array_key_exists($key, $this->originalDocumentPackRoleValues)) {
            $this->documentPackItems[$key]['role'] = $this->originalDocumentPackRoleValues[$key];
        }

        unset($this->editingDocumentPackRoleKeys[$key]);
        unset($this->originalDocumentPackRoleValues[$key]);
        unset($this->originalDocumentPackUploadFilenames[$key]);
    }

    public function finishEditingDocumentPackRole(string $key): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        if (! array_key_exists($key, $this->documentPackItems)) {
            return;
        }

        $role = DocumentPackItemRole::tryFrom($this->documentPackItems[$key]['role']);
        $originalRole = $this->originalDocumentPackRoleValues[$key] ?? null;
        $hasReplacementUpload = $this->documentPackItemHasActiveUpload($this->documentPackItems[$key]);

        if (
            $originalRole !== null
            && $this->documentPackItems[$key]['role'] !== $originalRole
            && $role?->source() === DocumentPackItemSource::Uploaded
            && ! $hasReplacementUpload
        ) {
            $this->editingDocumentPackRoleKeys[$key] = true;
            $this->markDocumentPackDirty();

            return;
        }

        unset($this->editingDocumentPackRoleKeys[$key]);
        unset($this->originalDocumentPackRoleValues[$key]);
        unset($this->originalDocumentPackUploadFilenames[$key]);

        $this->markDocumentPackDirty();
    }

    public function saveDocumentPack(): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        $removedIncompleteItemCount = $this->removeIncompleteDocumentPackItems();

        $this->validate([
            'documentPackName' => [
                'required',
                'string',
                'max:120',
                Rule::unique('document_packs', 'name')
                    ->where('project_id', $this->record->id)
                    ->ignore($this->selectedDocumentPackId),
            ],
            'documentPackItems' => ['array'],
            'documentPackItems.*.role' => ['required', Rule::enum(DocumentPackItemRole::class)],
        ]);

        $preparedUploads = $this->validateDocumentPackItems();
        $newPaths = [];
        $oldFilesToDelete = [];

        try {
            $documentPack = DB::transaction(function () use ($preparedUploads, &$newPaths, &$oldFilesToDelete): DocumentPack {
                $documentPack = $this->selectedDocumentPackId === null
                    ? $this->record->documentPacks()->create([
                        'name' => $this->documentPackName,
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                    ])
                    : $this->record->documentPacks()->lockForUpdate()->findOrFail($this->selectedDocumentPackId);

                $documentPack->update([
                    'name' => $this->documentPackName,
                    'updated_by' => auth()->id(),
                ]);

                $existingItems = $documentPack->items()->get()->keyBy('id');
                $retainedItemIds = [];
                $diskName = (string) config('document-packs.upload_disk', 'local');

                foreach (array_values($this->documentPackItems) as $position => $state) {
                    $role = DocumentPackItemRole::from($state['role']);
                    $itemId = $state['id'] ?? null;
                    $item = $itemId !== null ? $existingItems->get($itemId) : null;
                    abort_if($itemId !== null && $item === null, 404);
                    $item ??= new DocumentPackItem(['document_pack_id' => $documentPack->id]);

                    $oldDisk = $item->file_disk;
                    $oldPath = $item->file_path;
                    $upload = $preparedUploads[$state['key']] ?? null;

                    $attributes = [
                        'role' => $role,
                        'source_type' => $role->source(),
                        'sort_order' => $position,
                        'configuration' => null,
                    ];

                    if ($upload !== null) {
                        $path = $upload->storeAs(
                            'document-packs/'.$this->record->id.'/'.$documentPack->id,
                            Str::uuid().'.pdf',
                            $diskName,
                        );
                        abort_if($path === false, 500, 'The uploaded PDF could not be stored.');
                        $newPaths[] = [$diskName, $path];
                        $attributes += [
                            'file_disk' => $diskName,
                            'file_path' => $path,
                            'original_filename' => $this->documentPackUploadOriginalNames[$state['key']] ?? $upload->getClientOriginalName(),
                        ];

                        if ($oldPath !== null) {
                            $oldFilesToDelete[] = [$oldDisk ?? 'local', $oldPath];
                        }
                    } elseif (in_array($role->source(), [DocumentPackItemSource::Generated, DocumentPackItemSource::Template], true)) {
                        $attributes += [
                            'file_disk' => null,
                            'file_path' => null,
                            'original_filename' => null,
                        ];

                        if ($oldPath !== null) {
                            $oldFilesToDelete[] = [$oldDisk ?? 'local', $oldPath];
                        }
                    }

                    $item->fill($attributes);
                    $item->save();
                    $retainedItemIds[] = $item->id;
                }

                $existingItems
                    ->reject(fn (DocumentPackItem $item): bool => in_array($item->id, $retainedItemIds, true))
                    ->each(function (DocumentPackItem $item) use (&$oldFilesToDelete): void {
                        if ($item->file_path !== null) {
                            $oldFilesToDelete[] = [$item->file_disk ?? 'local', $item->file_path];
                        }

                        DocumentPackItem::withoutEvents(fn (): bool => $item->delete());
                    });

                return $documentPack;
            });
        } catch (Throwable $exception) {
            foreach ($newPaths as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }

        foreach ($oldFilesToDelete as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => $this->record->id,
            'action_type' => 'document_pack.saved',
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => $this->record->name,
            'revision_number' => $this->generationRevision()?->revision_number,
            'payload' => [
                'document_pack_id' => $documentPack->id,
                'document_pack_name' => $documentPack->name,
                'document_count' => count($this->documentPackItems),
            ],
        ]);

        unset($this->documentPacks);
        $this->loadDocumentPack($documentPack->id);

        if ($removedIncompleteItemCount > 0) {
            Notification::make()
                ->title($removedIncompleteItemCount === 1 ? 'Incomplete document block removed' : 'Incomplete document blocks removed')
                ->body($removedIncompleteItemCount === 1
                    ? 'One unfinished block was removed before saving the pack.'
                    : $removedIncompleteItemCount.' unfinished blocks were removed before saving the pack.')
                ->warning()
                ->send();
        }

        Notification::make()->title('Document pack saved')->success()->send();
    }

    public function deleteDocumentPack(): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);
        abort_if($this->selectedDocumentPackId === null, 404);

        $documentPack = $this->record->documentPacks()->findOrFail($this->selectedDocumentPackId);
        $name = $documentPack->name;
        $documentPack->delete();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => $this->record->id,
            'action_type' => 'document_pack.deleted',
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => $this->record->name,
            'payload' => ['document_pack_name' => $name],
        ]);

        unset($this->documentPacks);
        $this->newDocumentPack();
        Notification::make()->title('Document pack deleted')->success()->send();
    }

    public function getDocumentPackDownloadUrl(): ?string
    {
        if ($this->documentPackGenerationBlockReason() !== null) {
            return null;
        }

        $revision = $this->generationRevision();

        if ($revision === null) {
            return null;
        }

        return route('projects.document-packs.download', [
            'project' => $this->record,
            'documentPack' => $this->selectedDocumentPackId,
            'revision' => $revision->id,
        ]);
    }

    public function documentPackGenerationBlockReason(): ?string
    {
        if (! $this->canProduceDocumentPacks()) {
            return 'You do not have permission to generate document packs.';
        }

        if ($this->selectedDocumentPackId === null) {
            return 'Save the pack before generating it.';
        }

        if ($this->documentPackDirty) {
            return 'Save changes before generating.';
        }

        $revision = $this->generationRevision();

        if ($revision === null) {
            return 'Select a project revision before generating.';
        }

        $containsQuote = $this->record->documentPacks()
            ->whereKey($this->selectedDocumentPackId)
            ->whereHas('items', fn ($query) => $query->where('role', DocumentPackItemRole::Quote->value))
            ->exists();

        if (! $containsQuote) {
            return null;
        }

        if (! $this->canProduceQuote()) {
            return 'This pack contains a document you do not have permission to generate.';
        }

        if (! $revision->validated || $revision->status !== ProjectRevisionStatus::Approved) {
            return 'Approve the quote for '.$revision->label().' before generating this pack.';
        }

        return null;
    }

    public function selectedGenerationRevision(): ?ProjectRevision
    {
        return $this->generationRevision();
    }

    /**
     * @param  array<int, int>  $areaIds
     */
    private function outputHistoryRegenerateUrl(ActivityLog|ActivityLogArchive $log, ?int $revisionId, array $areaIds): ?string
    {
        $payload = $log->payload ?? [];
        $isQuote = $log->action_type === 'quote_pdf.generated';

        if ($isQuote && ! $this->canProduceQuote()) {
            return null;
        }

        if (! $isQuote && ! $this->canProduceUnpricedSchedule()) {
            return null;
        }

        $parameters = [
            'project' => $this->record,
            'revision' => $revisionId ?? (int) $this->record->active_revision_id,
            'salesforce_upload' => false,
        ];

        if ((bool) ($payload['include_datasheets'] ?? false)) {
            $parameters['include_datasheets'] = true;
        }

        if ($areaIds !== []) {
            $parameters['area_ids'] = $areaIds;
        }

        if ($isQuote) {
            if (filled($payload['tender_id'] ?? null)) {
                $parameters['tender_id'] = (int) $payload['tender_id'];
            }

            if (array_key_exists('include_cover', $payload)) {
                $parameters['include_cover'] = (bool) $payload['include_cover'];
            }

            return route('projects.pdf.quote', $parameters);
        }

        return route('projects.pdf.schedule', $parameters);
    }

    private function formatOutputHistoryDate(mixed $date): string
    {
        if ($date === null) {
            return '';
        }

        return Carbon::parse($date)->format('M d Y H:i');
    }

    public function activeRevision(): ProjectRevision
    {
        return $this->record->activeRevision;
    }

    public function validationPassed(): bool
    {
        return $this->activeRevision()->validated;
    }

    public function quoteApproved(): bool
    {
        return $this->activeRevision()->status === ProjectRevisionStatus::Approved;
    }

    public function quoteApprovalRequested(): bool
    {
        return $this->record->status === ProjectStatus::ApprovalRequested;
    }

    public function validationStatusLabel(): string
    {
        return $this->validationPassed() ? 'passed' : 'not_run';
    }

    public function canViewPrices(): bool
    {
        return auth()->user()?->can('pricing.view') ?? false;
    }

    public function canProduceUnpricedSchedule(): bool
    {
        return auth()->user()?->can('output.produce-unpriced-schedule') ?? false;
    }

    public function canProducePricedSchedule(): bool
    {
        return $this->canViewPrices() && (auth()->user()?->can('output.produce-priced-schedule') ?? false);
    }

    public function canProduceQuote(): bool
    {
        return $this->canViewPrices() && (auth()->user()?->can('output.produce-quote') ?? false);
    }

    public function canRequestQuoteApproval(): bool
    {
        return auth()->user()?->can('output.view') ?? false;
    }

    public function canViewValidation(): bool
    {
        return auth()->user()?->can('validation.view') ?? false;
    }

    public function canManageDocumentPacks(): bool
    {
        return auth()->user()?->can('output.manage-document-packs') ?? false;
    }

    public function canProduceDocumentPacks(): bool
    {
        return auth()->user()?->can('output.produce-document-packs') ?? false;
    }

    public function canViewOutputHistory(): bool
    {
        return auth()->user()?->can('output.history.view') ?? false;
    }

    /**
     * @return array<string, TemporaryUploadedFile>
     */
    private function validateDocumentPackItems(): array
    {
        $uploads = [];

        foreach ($this->documentPackItems as $state) {
            $role = DocumentPackItemRole::from($state['role']);

            abort_unless($this->canUseDocumentRole($role), 403);

            if ($role->source() !== DocumentPackItemSource::Uploaded) {
                continue;
            }

            $upload = $this->documentPackUploads[$state['key']] ?? null;
            $hasActiveUpload = $this->documentPackItemHasActiveUpload($state);
            $hasExistingFile = $this->documentPackItemHasExistingFile($state);

            if (! $hasActiveUpload && ! $hasExistingFile) {
                throw ValidationException::withMessages([
                    "documentPackUploads.{$state['key']}" => 'Upload a PDF for this document.',
                ]);
            }

            if (! $hasActiveUpload) {
                continue;
            }

            $this->validate([
                "documentPackUploads.{$state['key']}" => [
                    'file',
                    'mimes:pdf',
                    'max:'.config('document-packs.max_upload_kilobytes', 25600),
                ],
            ]);

            try {
                app(DocumentPackPdfService::class)->assertValidUploadedPdf($upload->getRealPath());
            } catch (Throwable $exception) {
                throw ValidationException::withMessages([
                    "documentPackUploads.{$state['key']}" => $exception->getMessage(),
                ]);
            }

            $uploads[$state['key']] = $upload;
        }

        return $uploads;
    }

    private function removeIncompleteDocumentPackItems(): int
    {
        $removedCount = 0;

        foreach ($this->documentPackItems as $key => $state) {
            $role = DocumentPackItemRole::tryFrom($state['role'] ?? '');

            if ($role === null) {
                unset(
                    $this->documentPackItems[$key],
                    $this->documentPackUploads[$key],
                    $this->documentPackUploadOriginalNames[$key],
                    $this->editingDocumentPackRoleKeys[$key],
                    $this->originalDocumentPackRoleValues[$key],
                    $this->originalDocumentPackUploadFilenames[$key],
                );
                $removedCount++;

                continue;
            }

            if ($role->source() !== DocumentPackItemSource::Uploaded) {
                continue;
            }
        }

        return $removedCount;
    }

    /**
     * @param  array{key: string, id: int|null, role: string, file_path: string|null, original_filename: string|null}  $state
     */
    private function documentPackItemHasExistingFile(array $state): bool
    {
        if (! $this->documentPackExistingFileAppliesToCurrentRole($state)) {
            return false;
        }

        return $this->selectedDocumentPackId !== null
            && filled($state['id'] ?? null)
            && DocumentPackItem::query()
                ->where('document_pack_id', $this->selectedDocumentPackId)
                ->whereKey($state['id'])
                ->whereNotNull('file_path')
                ->exists();
    }

    /**
     * @param  array{key: string, id: int|null, role: string, file_path: string|null, original_filename: string|null}  $state
     */
    private function documentPackExistingFileAppliesToCurrentRole(array $state): bool
    {
        $key = $state['key'];

        return ! array_key_exists($key, $this->originalDocumentPackRoleValues)
            || $state['role'] === $this->originalDocumentPackRoleValues[$key];
    }

    /**
     * @param  array{key: string, id: int|null, role: string, file_path: string|null, original_filename: string|null}  $state
     */
    private function documentPackUploadAppliesToCurrentRole(array $state, TemporaryUploadedFile $upload): bool
    {
        $key = $state['key'];

        if (! array_key_exists($key, $this->originalDocumentPackRoleValues)) {
            return true;
        }

        if ($state['role'] === $this->originalDocumentPackRoleValues[$key]) {
            return true;
        }

        $originalUploadFilename = $this->originalDocumentPackUploadFilenames[$key] ?? null;

        return $originalUploadFilename === null || $upload->getFilename() !== $originalUploadFilename;
    }

    private function canUseDocumentRole(DocumentPackItemRole $role): bool
    {
        return match ($role) {
            DocumentPackItemRole::Quote => $this->canProduceQuote(),
            DocumentPackItemRole::UnpricedSchedule => $this->canProduceUnpricedSchedule(),
            DocumentPackItemRole::Cover,
            DocumentPackItemRole::Legal,
            DocumentPackItemRole::CustomPdf,
            DocumentPackItemRole::StandardLegalPage => $this->canManageDocumentPacks(),
        };
    }

    private function generationRevision(): ?ProjectRevision
    {
        if ($this->generationRevisionId === null) {
            return null;
        }

        return $this->record->revisions()->find($this->generationRevisionId);
    }

    /** @return array{key: string, id: null, role: string, file_path: null, original_filename: null} */
    private function emptyDocumentPackItem(): array
    {
        return [
            'key' => 'new-'.Str::uuid(),
            'id' => null,
            'role' => '',
            'file_path' => null,
            'original_filename' => null,
        ];
    }
}
