<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use App\Enums\ProjectRevisionStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectVisibility;
use App\Filament\Resources\Projects\Pages\Concerns\HasProjectSubNav;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ActivityLog;
use App\Models\DocumentPack;
use App\Models\DocumentPackItem;
use App\Models\DocumentPackTemplate;
use App\Models\DocumentPackTemplateItem;
use App\Models\ProjectArea;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use App\Models\ResourceFile;
use App\Services\DocumentPackPdfService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
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
use RuntimeException;
use Throwable;

class OutputProject extends ViewRecord
{
    use HasProjectSubNav, WithFileUploads;

    private const DocumentPackResourcePerPage = 10;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.output-project';

    protected static ?string $navigationLabel = 'Output';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    public ?int $selectedDocumentPackId = null;

    public string $documentPackName = '';

    /** @var array<string, array{key: string, id: int|null, role: string, file_path: string|null, original_filename: string|null, resource_file_id: int|null, resource_display_name: string|null, template_item_id: int|null}> */
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

    public ?string $documentPackResourceItemKey = null;

    public string $documentPackResourceSearch = '';

    public int $documentPackResourcePage = 1;

    public ?int $documentPackResourcePreviewId = null;

    public string $documentPackTemplateName = '';

    public string $documentPackTemplateVisibilityTarget = 'open';

    public ?int $selectedDocumentPackTemplateId = null;

    public ?int $generationRevisionId = null;

    public string $outputTab = 'single';

    public bool $includeQuoteDatasheets = false;

    public bool $includeQuoteLegalPage = true;

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

        if (! $this->includeQuoteLegalPage) {
            $parameters['include_legal_page'] = false;
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
                'price' => $canViewPricing ? $this->record->formatCurrency($area->line_total) : null,
                'net' => $canViewPricing && $this->record->has_cover
                    ? $this->record->formatCurrency($area->lines->sum(fn (ProjectLine $line): float => $line->netLineTotalForProject($this->record)))
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
            ->withinRetention()
            ->where('project_id', $this->record->id)
            ->whereIn('action_type', ['quote_pdf.generated', 'schedule_pdf.generated'])
            ->with('user:id,name,email')
            ->latest()
            ->limit(100)
            ->get();

        return $liveLogs
            ->map(function (ActivityLog $log) use ($revisions): array {
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
    public function documentPackTemplates(): Collection
    {
        $user = auth()->user();

        if ($user === null || ! $this->canManageDocumentPacks()) {
            return new Collection;
        }

        return DocumentPackTemplate::query()
            ->visibleTo($user)
            ->with(['owner:id,name', 'team:id,name'])
            ->withCount('items')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, string> */
    public function documentPackTemplateVisibilityOptions(): array
    {
        $options = [
            ProjectVisibility::Open->value => 'Open',
            ProjectVisibility::Private->value => 'Private',
        ];

        $teams = auth()->user()?->teams()
            ->orderBy('name')
            ->pluck('name', 'teams.id')
            ->all() ?? [];

        foreach ($teams as $id => $name) {
            $options["team:{$id}"] = "Team: {$name}";
        }

        return $options;
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

        if ($role === DocumentPackItemRole::StandardLegalPage) {
            return route('projects.document-packs.standard-legal-page.file', [
                'project' => $this->record,
            ]);
        }

        if ($role?->source() !== DocumentPackItemSource::Uploaded) {
            return null;
        }

        if (filled($item['template_item_id'] ?? null)) {
            $templateItem = $this->accessibleDocumentPackTemplateItems()
                ->find((int) $item['template_item_id']);

            if ($templateItem === null || ! $templateItem->hasManagedFile()) {
                return null;
            }

            return route('projects.document-pack-templates.items.file', [
                'project' => $this->record,
                'documentPackTemplateItem' => $templateItem,
            ]);
        }

        if (filled($item['resource_file_id'] ?? null)) {
            if (! $this->canSelectResourcesForDocumentPack()) {
                return null;
            }

            $resourceFile = $this->pdfResourceFiles()->find((int) $item['resource_file_id']);

            if ($resourceFile === null || ! $resourceFile->hasManagedFile()) {
                return null;
            }

            return route('projects.document-packs.resources.file', [
                'project' => $this->record,
                'resourceFile' => $resourceFile,
            ]);
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
        if (filled($item['resource_file_id'] ?? null) || filled($item['template_item_id'] ?? null)) {
            return true;
        }

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
                    'resource_file_id' => null,
                    'resource_display_name' => $item->configuration['resource_display_name'] ?? null,
                    'template_item_id' => null,
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

    public function selectDocumentPackRole(string $key, string $role): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        if (! array_key_exists($key, $this->documentPackItems)) {
            return;
        }

        if ($role === 'select_resource') {
            $this->openDocumentPackResourcePicker($key);

            return;
        }

        $documentRole = DocumentPackItemRole::tryFrom($role);
        abort_unless($documentRole !== null && $documentRole->selectableInBuilder(), 422);
        abort_unless($this->canUseDocumentRole($documentRole), 403);

        $this->documentPackItems[$key]['role'] = $documentRole->value;
        $this->documentPackItems[$key]['resource_file_id'] = null;
        $this->documentPackItems[$key]['resource_display_name'] = null;
        $this->documentPackItems[$key]['template_item_id'] = null;
        $this->documentPackDirty = true;
    }

    public function openDocumentPackResourcePicker(string $key): void
    {
        abort_unless($this->canSelectResourcesForDocumentPack(), 403);
        abort_unless(array_key_exists($key, $this->documentPackItems), 404);

        $this->documentPackResourceItemKey = $key;
        $this->documentPackResourceSearch = '';
        $this->documentPackResourcePage = 1;
        $this->documentPackResourcePreviewId = null;
        $this->dispatch('open-modal', id: 'document-pack-resource-picker');
    }

    public function updatedDocumentPackResourceSearch(): void
    {
        $this->documentPackResourcePage = 1;
    }

    public function previousDocumentPackResourcePage(): void
    {
        $this->documentPackResourcePage = max(1, $this->documentPackResourcePage - 1);
    }

    public function nextDocumentPackResourcePage(): void
    {
        $this->documentPackResourcePage = min(
            $this->documentPackResourceTotalPages(),
            $this->documentPackResourcePage + 1,
        );
    }

    /**
     * @return Collection<int, ResourceFile>
     */
    public function documentPackResourceRows(): Collection
    {
        if (! $this->canSelectResourcesForDocumentPack()) {
            return new Collection;
        }

        $page = min($this->documentPackResourcePage, $this->documentPackResourceTotalPages());

        return $this->documentPackResourceQuery()
            ->forPage($page, self::DocumentPackResourcePerPage)
            ->get();
    }

    public function documentPackResourceTotalRows(): int
    {
        if (! $this->canSelectResourcesForDocumentPack()) {
            return 0;
        }

        return $this->documentPackResourceQuery()->count();
    }

    public function documentPackResourceTotalPages(): int
    {
        return max(1, (int) ceil($this->documentPackResourceTotalRows() / self::DocumentPackResourcePerPage));
    }

    public function documentPackResourceCurrentPage(): int
    {
        return min(max(1, $this->documentPackResourcePage), $this->documentPackResourceTotalPages());
    }

    public function previewDocumentPackResource(int $resourceFileId): void
    {
        abort_unless($this->canSelectResourcesForDocumentPack(), 403);

        $resourceFile = $this->pdfResourceFiles()->findOrFail($resourceFileId);
        abort_unless($resourceFile->hasManagedFile(), 404);

        $this->documentPackResourcePreviewId = $resourceFile->id;
        $this->dispatch('open-modal', id: 'document-pack-resource-preview');
    }

    public function selectedDocumentPackResourcePreview(): ?ResourceFile
    {
        if (! $this->canSelectResourcesForDocumentPack() || $this->documentPackResourcePreviewId === null) {
            return null;
        }

        return $this->pdfResourceFiles()->find($this->documentPackResourcePreviewId);
    }

    public function addDocumentPackResource(int $resourceFileId): void
    {
        abort_unless($this->canSelectResourcesForDocumentPack(), 403);
        abort_unless(
            $this->documentPackResourceItemKey !== null
                && array_key_exists($this->documentPackResourceItemKey, $this->documentPackItems),
            404,
        );

        $resourceFile = $this->pdfResourceFiles()->findOrFail($resourceFileId);

        if (! $this->resourcePdfIsAvailable($resourceFile)) {
            Notification::make()
                ->title('Resource PDF unavailable')
                ->body('The selected Resource file could not be found. It may have been removed.')
                ->danger()
                ->send();

            return;
        }

        $key = $this->documentPackResourceItemKey;
        $this->documentPackItems[$key]['role'] = DocumentPackItemRole::CustomPdf->value;
        $this->documentPackItems[$key]['resource_file_id'] = $resourceFile->id;
        $this->documentPackItems[$key]['resource_display_name'] = $resourceFile->display_name;
        $this->documentPackItems[$key]['original_filename'] = $resourceFile->original_filename;
        $this->documentPackItems[$key]['template_item_id'] = null;
        unset($this->documentPackUploads[$key], $this->documentPackUploadOriginalNames[$key]);
        $this->documentPackDirty = true;
        $this->documentPackResourceItemKey = null;
        $this->dispatch('close-modal', id: 'document-pack-resource-picker');

        Notification::make()->title('Resource added to document pack')->success()->send();
    }

    public function openSaveDocumentPackTemplate(): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        $this->documentPackTemplateName = Str::limit(Str::squish($this->documentPackName), 120, '');
        $this->documentPackTemplateVisibilityTarget = ProjectVisibility::Open->value;
        $this->resetValidation(['documentPackTemplateName', 'documentPackTemplateVisibilityTarget']);
        $this->dispatch('open-modal', id: 'save-document-pack-template');
    }

    public function openDocumentPackTemplatePicker(): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        $this->selectedDocumentPackTemplateId = null;
        $this->dispatch('open-modal', id: 'select-document-pack-template');
    }

    public function saveDocumentPackAsTemplate(): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        $this->removeIncompleteDocumentPackItems();
        $this->documentPackTemplateName = Str::squish($this->documentPackTemplateName);
        $this->validate([
            'documentPackTemplateName' => ['required', 'string', 'max:120'],
            'documentPackTemplateVisibilityTarget' => ['required', 'string', 'max:40'],
            'documentPackItems' => ['required', 'array', 'min:1'],
            'documentPackItems.*.role' => ['required', Rule::enum(DocumentPackItemRole::class)],
        ]);

        $preparedItems = $this->validateDocumentPackItems();
        [$visibility, $teamId] = $this->normaliseDocumentPackTemplateVisibility();
        $newPaths = [];

        try {
            $template = DB::transaction(function () use ($preparedItems, $visibility, $teamId, &$newPaths): DocumentPackTemplate {
                $template = DocumentPackTemplate::query()->create([
                    'user_id' => auth()->id(),
                    'name' => Str::squish($this->documentPackTemplateName),
                    'visibility' => $visibility,
                    'team_id' => $teamId,
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ]);
                $diskName = (string) config('document-packs.upload_disk', 'local');

                foreach (array_values($this->documentPackItems) as $position => $state) {
                    $role = DocumentPackItemRole::from($state['role']);
                    $attributes = [
                        'role' => $role,
                        'source_type' => $role->source(),
                        'sort_order' => $position,
                        'file_disk' => null,
                        'file_path' => null,
                        'original_filename' => null,
                        'configuration' => null,
                    ];

                    if ($role->source() === DocumentPackItemSource::Uploaded) {
                        $upload = $preparedItems['uploads'][$state['key']] ?? null;
                        $resourceFile = $preparedItems['resources'][$state['key']] ?? null;
                        $sourceTemplateItem = $preparedItems['template_items'][$state['key']] ?? null;
                        $path = null;

                        if ($upload instanceof TemporaryUploadedFile) {
                            $path = $upload->storeAs(
                                DocumentPackTemplateItem::Directory.'/'.$template->id,
                                Str::uuid().'.pdf',
                                $diskName,
                            );
                            abort_if($path === false, 500, 'The PDF could not be stored in the template.');
                            $attributes['original_filename'] = $this->documentPackUploadOriginalNames[$state['key']] ?? $upload->getClientOriginalName();
                        } elseif ($resourceFile instanceof ResourceFile) {
                            $path = $this->copyStoredPdf(
                                ResourceFile::Disk,
                                $resourceFile->file_path,
                                $diskName,
                                DocumentPackTemplateItem::Directory.'/'.$template->id,
                            );
                            $attributes['original_filename'] = $resourceFile->original_filename;
                            $attributes['configuration'] = [
                                'resource_file_id' => $resourceFile->id,
                                'resource_display_name' => $resourceFile->display_name,
                            ];
                        } elseif ($sourceTemplateItem instanceof DocumentPackTemplateItem) {
                            $path = $this->copyStoredPdf(
                                $sourceTemplateItem->file_disk ?? 'local',
                                (string) $sourceTemplateItem->file_path,
                                $diskName,
                                DocumentPackTemplateItem::Directory.'/'.$template->id,
                            );
                            $attributes['original_filename'] = $sourceTemplateItem->original_filename;
                            $attributes['configuration'] = $sourceTemplateItem->configuration;
                        } else {
                            $sourcePackItem = $this->currentDocumentPackItem($state);
                            abort_if(
                                $sourcePackItem === null
                                    || ! $this->storedPdfIsAvailable(
                                        $sourcePackItem->file_disk ?? 'local',
                                        $sourcePackItem->file_path,
                                        'document-packs',
                                    ),
                                422,
                                'A static PDF is missing from this pack.',
                            );
                            $path = $this->copyStoredPdf(
                                $sourcePackItem->file_disk ?? 'local',
                                $sourcePackItem->file_path,
                                $diskName,
                                DocumentPackTemplateItem::Directory.'/'.$template->id,
                            );
                            $attributes['original_filename'] = $sourcePackItem->original_filename;
                            $attributes['configuration'] = $sourcePackItem->configuration;
                        }

                        $newPaths[] = [$diskName, $path];
                        $attributes['file_disk'] = $diskName;
                        $attributes['file_path'] = $path;
                    }

                    $template->items()->create($attributes);
                }

                return $template;
            });
        } catch (Throwable $exception) {
            foreach ($newPaths as [$disk, $path]) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            report($exception);
            Notification::make()
                ->title('Template could not be saved')
                ->body('No template was created. Please check the selected PDFs and try again.')
                ->danger()
                ->send();

            return;
        }

        try {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $this->record->id,
                'action_type' => 'document_pack_template.created',
                'user_email_snapshot' => auth()->user()?->email ?? '',
                'project_name_snapshot' => $this->record->name,
                'payload' => [
                    'document_pack_template_id' => $template->id,
                    'document_pack_template_name' => $template->name,
                    'visibility' => $template->visibility->value,
                    'team_id' => $template->team_id,
                    'document_count' => count($this->documentPackItems),
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }

        unset($this->documentPackTemplates);
        $this->dispatch('close-modal', id: 'save-document-pack-template');
        Notification::make()->title('Document pack template saved')->success()->send();
    }

    public function useSelectedDocumentPackTemplate(): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);
        abort_if($this->selectedDocumentPackTemplateId === null, 422);

        $user = auth()->user();
        abort_if($user === null, 403);

        $template = DocumentPackTemplate::query()
            ->visibleTo($user)
            ->with('items')
            ->findOrFail($this->selectedDocumentPackTemplateId);
        $items = [];
        $omittedQuote = false;
        $omittedUnavailable = 0;

        foreach ($template->items as $templateItem) {
            if ($templateItem->source_type !== $templateItem->role->source()) {
                $omittedUnavailable++;

                continue;
            }

            if ($templateItem->role === DocumentPackItemRole::Quote && ! $this->canProduceQuote()) {
                $omittedQuote = true;

                continue;
            }

            if (! $this->canUseDocumentRole($templateItem->role)) {
                $omittedUnavailable++;

                continue;
            }

            if (
                $templateItem->source_type === DocumentPackItemSource::Uploaded
                && ! $this->templatePdfIsUsable($templateItem)
            ) {
                $omittedUnavailable++;

                continue;
            }

            $key = 'template-item-'.$templateItem->id.'-'.Str::uuid();
            $items[$key] = [
                'key' => $key,
                'id' => null,
                'role' => $templateItem->role->value,
                'file_path' => null,
                'original_filename' => $templateItem->original_filename,
                'resource_file_id' => null,
                'resource_display_name' => $templateItem->configuration['resource_display_name'] ?? null,
                'template_item_id' => $templateItem->source_type === DocumentPackItemSource::Uploaded
                    ? $templateItem->id
                    : null,
            ];
        }

        if ($items === []) {
            $emptyItem = $this->emptyDocumentPackItem();
            $items[$emptyItem['key']] = $emptyItem;
        }

        $this->selectedDocumentPackId = null;
        $this->documentPackName = $template->name;
        $this->documentPackItems = $items;
        $this->documentPackUploads = [];
        $this->documentPackUploadOriginalNames = [];
        $this->editingDocumentPackRoleKeys = [];
        $this->originalDocumentPackRoleValues = [];
        $this->originalDocumentPackUploadFilenames = [];
        $this->documentPackDirty = true;
        $this->dispatch('close-modal', id: 'select-document-pack-template');

        if ($omittedQuote || $omittedUnavailable > 0) {
            $messages = [];

            if ($omittedQuote) {
                $messages[] = 'The Quote placeholder was not added because you do not have permission to produce quotes.';
            }

            if ($omittedUnavailable > 0) {
                $messages[] = $omittedUnavailable.' unavailable '.Str::plural('document', $omittedUnavailable).' '.($omittedUnavailable === 1 ? 'was' : 'were').' not added.';
            }

            Notification::make()
                ->title('Template applied with changes')
                ->body(implode(' ', $messages))
                ->warning()
                ->send();

            return;
        }

        Notification::make()->title('Document pack template applied')->success()->send();
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
        $this->documentPackItems[$key]['resource_file_id'] = null;
        $this->documentPackItems[$key]['resource_display_name'] = null;
        $this->documentPackItems[$key]['template_item_id'] = null;
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

        $preparedItems = $this->validateDocumentPackItems();
        $newPaths = [];
        $oldFilesToDelete = [];

        try {
            $documentPack = DB::transaction(function () use ($preparedItems, &$newPaths, &$oldFilesToDelete): DocumentPack {
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
                    $upload = $preparedItems['uploads'][$state['key']] ?? null;
                    $resourceFile = $preparedItems['resources'][$state['key']] ?? null;
                    $templateItem = $preparedItems['template_items'][$state['key']] ?? null;

                    $attributes = [
                        'role' => $role,
                        'source_type' => $role->source(),
                        'sort_order' => $position,
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
                            'configuration' => null,
                        ];

                        if ($oldPath !== null) {
                            $oldFilesToDelete[] = [$oldDisk ?? 'local', $oldPath];
                        }
                    } elseif ($resourceFile instanceof ResourceFile) {
                        $path = $this->copyResourceIntoDocumentPack(
                            $resourceFile,
                            $documentPack,
                            $diskName,
                        );
                        $newPaths[] = [$diskName, $path];
                        $attributes += [
                            'file_disk' => $diskName,
                            'file_path' => $path,
                            'original_filename' => $resourceFile->original_filename,
                            'configuration' => [
                                'resource_file_id' => $resourceFile->id,
                                'resource_display_name' => $resourceFile->display_name,
                            ],
                        ];

                        if ($oldPath !== null) {
                            $oldFilesToDelete[] = [$oldDisk ?? 'local', $oldPath];
                        }
                    } elseif ($templateItem instanceof DocumentPackTemplateItem) {
                        $path = $this->copyStoredPdf(
                            $templateItem->file_disk ?? 'local',
                            (string) $templateItem->file_path,
                            $diskName,
                            'document-packs/'.$this->record->id.'/'.$documentPack->id,
                        );
                        $newPaths[] = [$diskName, $path];
                        $attributes += [
                            'file_disk' => $diskName,
                            'file_path' => $path,
                            'original_filename' => $templateItem->original_filename,
                            'configuration' => array_filter([
                                ...($templateItem->configuration ?? []),
                                'document_pack_template_id' => $templateItem->document_pack_template_id,
                                'document_pack_template_item_id' => $templateItem->id,
                            ], fn (mixed $value): bool => $value !== null),
                        ];

                        if ($oldPath !== null) {
                            $oldFilesToDelete[] = [$oldDisk ?? 'local', $oldPath];
                        }
                    } elseif (in_array($role->source(), [DocumentPackItemSource::Generated, DocumentPackItemSource::Template], true)) {
                        $attributes += [
                            'file_disk' => null,
                            'file_path' => null,
                            'original_filename' => null,
                            'configuration' => null,
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
    private function outputHistoryRegenerateUrl(ActivityLog $log, ?int $revisionId, array $areaIds): ?string
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

            if (array_key_exists('include_legal_page', $payload)) {
                $parameters['include_legal_page'] = (bool) $payload['include_legal_page'];
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

    public function canSelectResourcesForDocumentPack(): bool
    {
        return $this->canManageDocumentPacks();
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
     * @return array{uploads: array<string, TemporaryUploadedFile>, resources: array<string, ResourceFile>, template_items: array<string, DocumentPackTemplateItem>}
     */
    private function validateDocumentPackItems(): array
    {
        $uploads = [];
        $resources = [];
        $templateItems = [];

        foreach ($this->documentPackItems as $state) {
            $role = DocumentPackItemRole::from($state['role']);

            abort_unless($this->canUseDocumentRole($role), 403);

            if ($role->source() !== DocumentPackItemSource::Uploaded) {
                continue;
            }

            $upload = $this->documentPackUploads[$state['key']] ?? null;
            $hasActiveUpload = $this->documentPackItemHasActiveUpload($state);
            $hasExistingFile = $this->documentPackItemHasExistingFile($state);
            $resourceFileId = filled($state['resource_file_id'] ?? null)
                ? (int) $state['resource_file_id']
                : null;
            $templateItemId = filled($state['template_item_id'] ?? null)
                ? (int) $state['template_item_id']
                : null;

            if ($resourceFileId !== null && ! $hasActiveUpload) {
                abort_unless($this->canSelectResourcesForDocumentPack(), 403);

                if ($role !== DocumentPackItemRole::CustomPdf) {
                    throw ValidationException::withMessages([
                        "documentPackItems.{$state['key']}.role" => 'A Resource PDF must use the Custom PDF document type.',
                    ]);
                }

                $resourceFile = $this->pdfResourceFiles()->find($resourceFileId);

                if (
                    $resourceFile === null
                    || ! $this->resourcePdfIsAvailable($resourceFile)
                ) {
                    throw ValidationException::withMessages([
                        "documentPackItems.{$state['key']}.role" => 'The selected Resource PDF is no longer available.',
                    ]);
                }

                try {
                    app(DocumentPackPdfService::class)->assertValidUploadedPdf(
                        Storage::disk(ResourceFile::Disk)->path($resourceFile->file_path),
                    );
                } catch (Throwable $exception) {
                    report($exception);

                    throw ValidationException::withMessages([
                        "documentPackItems.{$state['key']}.role" => 'The selected Resource PDF is corrupt, encrypted, or cannot currently be read.',
                    ]);
                }

                $resources[$state['key']] = $resourceFile;

                continue;
            }

            if ($templateItemId !== null && ! $hasActiveUpload) {
                $templateItem = $this->accessibleDocumentPackTemplateItems()->find($templateItemId);

                if (
                    $templateItem === null
                    || $templateItem->role !== $role
                    || $templateItem->source_type !== DocumentPackItemSource::Uploaded
                    || ! $this->templatePdfIsUsable($templateItem)
                ) {
                    throw ValidationException::withMessages([
                        "documentPackItems.{$state['key']}.role" => 'This template PDF is no longer available.',
                    ]);
                }

                $templateItems[$state['key']] = $templateItem;

                continue;
            }

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

        return [
            'uploads' => $uploads,
            'resources' => $resources,
            'template_items' => $templateItems,
        ];
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

    private function accessibleDocumentPackTemplateItems(): Builder
    {
        $user = auth()->user();

        if ($user === null) {
            return DocumentPackTemplateItem::query()->whereRaw('1 = 0');
        }

        return DocumentPackTemplateItem::query()
            ->whereHas(
                'documentPackTemplate',
                fn (Builder $query): Builder => $query->visibleTo($user),
            );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function currentDocumentPackItem(array $state): ?DocumentPackItem
    {
        if ($this->selectedDocumentPackId === null || blank($state['id'] ?? null)) {
            return null;
        }

        return DocumentPackItem::query()
            ->where('document_pack_id', $this->selectedDocumentPackId)
            ->whereHas('documentPack', fn (Builder $query): Builder => $query->where('project_id', $this->record->id))
            ->find((int) $state['id']);
    }

    /** @return array{ProjectVisibility, int|null} */
    private function normaliseDocumentPackTemplateVisibility(): array
    {
        $target = $this->documentPackTemplateVisibilityTarget;

        if (! str_starts_with($target, 'team:')) {
            return [
                $target === ProjectVisibility::Private->value
                    ? ProjectVisibility::Private
                    : ProjectVisibility::Open,
                null,
            ];
        }

        $teamId = filter_var(str_replace('team:', '', $target), FILTER_VALIDATE_INT);
        $allowed = $teamId !== false
            && (auth()->user()?->teams()->whereKey($teamId)->exists() ?? false);

        return $allowed
            ? [ProjectVisibility::Team, (int) $teamId]
            : [ProjectVisibility::Private, null];
    }

    private function pdfResourceFiles(): Builder
    {
        return ResourceFile::query()
            ->where('extension', 'pdf')
            ->where('mime_type', 'application/pdf');
    }

    private function documentPackResourceQuery(): Builder
    {
        $search = Str::squish($this->documentPackResourceSearch);

        return $this->pdfResourceFiles()
            ->when($search !== '', fn (Builder $query): Builder => $query
                ->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('display_name', 'like', "%{$search}%")
                        ->orWhere('original_filename', 'like', "%{$search}%");
                }))
            ->latest();
    }

    private function resourcePdfIsAvailable(ResourceFile $resourceFile): bool
    {
        if (! $resourceFile->hasManagedFile()) {
            return false;
        }

        try {
            return Storage::disk(ResourceFile::Disk)->exists($resourceFile->file_path);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function copyResourceIntoDocumentPack(
        ResourceFile $resourceFile,
        DocumentPack $documentPack,
        string $destinationDiskName,
    ): string {
        return $this->copyStoredPdf(
            ResourceFile::Disk,
            $resourceFile->file_path,
            $destinationDiskName,
            'document-packs/'.$this->record->id.'/'.$documentPack->id,
        );
    }

    private function storedPdfIsAvailable(
        string $diskName,
        ?string $path,
        string $requiredDirectory,
    ): bool {
        if ($path === null) {
            return false;
        }

        $normalisedPath = str_replace('\\', '/', $path);

        if (
            ! str_starts_with($normalisedPath, trim($requiredDirectory, '/').'/')
            || str_contains($normalisedPath, '../')
            || strtolower(pathinfo($normalisedPath, PATHINFO_EXTENSION)) !== 'pdf'
        ) {
            return false;
        }

        try {
            return Storage::disk($diskName)->exists($normalisedPath);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function templatePdfIsUsable(DocumentPackTemplateItem $templateItem): bool
    {
        $diskName = $templateItem->file_disk ?? 'local';

        if (! $this->storedPdfIsAvailable(
            $diskName,
            $templateItem->file_path,
            DocumentPackTemplateItem::Directory,
        )) {
            return false;
        }

        try {
            app(DocumentPackPdfService::class)->assertValidUploadedPdf(
                Storage::disk($diskName)->path((string) $templateItem->file_path),
            );

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function copyStoredPdf(
        string $sourceDiskName,
        string $sourcePath,
        string $destinationDiskName,
        string $destinationDirectory,
    ): string {
        $destinationPath = trim($destinationDirectory, '/').'/'.Str::uuid().'.pdf';
        $sourceDisk = Storage::disk($sourceDiskName);
        $destinationDisk = Storage::disk($destinationDiskName);
        $stream = null;

        try {
            $stream = $sourceDisk->readStream($sourcePath);

            if ($stream === false) {
                throw new RuntimeException('The source PDF could not be read.');
            }

            if (! $destinationDisk->put($destinationPath, $stream)) {
                throw new RuntimeException('The PDF snapshot could not be stored.');
            }
        } catch (Throwable $exception) {
            try {
                $destinationDisk->delete($destinationPath);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $destinationPath;
    }

    private function generationRevision(): ?ProjectRevision
    {
        if ($this->generationRevisionId === null) {
            return null;
        }

        return $this->record->revisions()->find($this->generationRevisionId);
    }

    /** @return array{key: string, id: null, role: string, file_path: null, original_filename: null, resource_file_id: null, resource_display_name: null, template_item_id: null} */
    private function emptyDocumentPackItem(): array
    {
        return [
            'key' => 'new-'.Str::uuid(),
            'id' => null,
            'role' => '',
            'file_path' => null,
            'original_filename' => null,
            'resource_file_id' => null,
            'resource_display_name' => null,
            'template_item_id' => null,
        ];
    }
}
