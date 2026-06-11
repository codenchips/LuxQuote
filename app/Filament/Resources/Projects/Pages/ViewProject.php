<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectLineType;
use App\Enums\ProjectRevisionStatus;
use App\Filament\Resources\Projects\Pages\Concerns\HasProjectSubNav;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProjectArea;
use App\Models\ProjectLine;
use App\Models\ProjectPresence;
use App\Models\ProjectRevision;
use App\Services\ProjectSchedulePdfService;
use App\Services\SalesforceService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Throwable;

class ViewProject extends ViewRecord
{
    use HasProjectSubNav;

    private const LineStatusPending = 'Pending';

    private const LineStatusPriced = 'Priced';

    private const LineStatusUnpriced = 'Unpriced';

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.view-project';

    public string $newAreaName = '';

    // ── Revision state ───────────────────────────────────────────────────────

    public ?int $viewingRevisionId = null;

    public bool $revisionsModalOpen = false;

    // ── Product picker state ─────────────────────────────────────────────────

    public bool $productPickerOpen = false;

    public ?int $productPickerAreaId = null;

    public string $productSearch = '';

    public string $productSiteFilter = '';

    public string $productTypeFilter = '';

    public int $productPage = 1;

    /** @var array<int, array{qty: int}> */
    public array $productSelections = [];

    // ── Paste products state ─────────────────────────────────────────────────

    public bool $pasteProductsModalOpen = false;

    public ?int $pasteProductsAreaId = null;

    public string $pastedProductData = '';

    public ?string $pasteProductsError = null;

    public bool $pasteAcrossAllAreas = true;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->viewingRevisionId = $this->record->active_revision_id;
        $this->heartbeat();
    }

    // ── Concurrent-editing presence ───────────────────────────────────────────

    public function heartbeat(): void
    {
        ProjectPresence::upsert(
            [[
                'project_id' => $this->record->id,
                'user_id' => auth()->id(),
                'last_seen_at' => now(),
            ]],
            ['project_id', 'user_id'],
            ['last_seen_at'],
        );

        // Purge stale presences globally (older than 90 seconds)
        ProjectPresence::where('last_seen_at', '<', now()->subSeconds(90))->delete();
    }

    #[Computed]
    public function concurrentEditors(): Collection
    {
        return ProjectPresence::where('project_id', $this->record->id)
            ->where('user_id', '!=', auth()->id())
            ->where('last_seen_at', '>=', now()->subSeconds(90))
            ->with('user')
            ->get()
            ->map(fn (ProjectPresence $p) => $p->user)
            ->filter()
            ->values();
    }

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

        if ($this->viewingRevisionId) {
            $revNum = ProjectRevision::find($this->viewingRevisionId)?->revision_number;
            if ($revNum) {
                $parts[] = 'Rev '.$revNum;
            }
        }

        if ($this->isViewingRevisionValidated) {
            $parts[] = 'Approved (locked)';
        }

        return new HtmlString(implode(' &middot; ', $parts));
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make('editProject')
                ->record($this->record)
                ->form(fn (Schema $schema): Schema => ProjectForm::configure($schema))
                ->slideOver()
                ->label('Details')
                ->icon('heroicon-o-pencil')
                ->color('gray')
                ->tooltip('Edit project details')
                ->visible(fn (): bool => ! $this->isViewingRevisionValidated)
                ->after(fn () => $this->afterProjectDetailsSaved()),

            Action::make('manageRevisions')
                ->label('Revisions')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->action(fn () => $this->revisionsModalOpen = true),

            Action::make('manageAreas')
                ->label('Areas')
                ->icon(Heroicon::OutlinedMapPin)
                ->color('gray')
                ->visible(fn (): bool => ! $this->isViewingRevisionValidated)
                ->modalHeading('Manage Areas')
                ->modalDescription('Define the rooms, floors, and areas for this project.')
                ->modalContent(fn (): View => view(
                    'filament.resources.projects.pages.areas-modal-content',
                    ['areas' => $this->getAreas()],
                ))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),

            Action::make('downloadSchedule')
                ->label('Schedule PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('primary')
                ->url(fn (): string => route('projects.pdf.schedule', [
                    'project' => $this->record,
                    'revision' => $this->viewingRevisionId,
                ]))
                ->openUrlInNewTab(),
        ];
    }

    public function getAreas(): Collection
    {
        return ProjectArea::where('project_revision_id', $this->viewingRevisionId)
            ->with(['lines' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();
    }

    private function afterProjectDetailsSaved(): void
    {
        $this->record->refresh();

        if (! $this->record->salesforce_project) {
            $this->logProjectDetailsSaved();

            return;
        }

        $revision = ProjectRevision::where('project_id', $this->record->id)
            ->find($this->viewingRevisionId ?? $this->record->active_revision_id);

        if (! $revision) {
            Notification::make()
                ->title('Salesforce upload skipped')
                ->body('No project revision was available for the schedule PDF.')
                ->warning()
                ->send();

            $this->logProjectDetailsSaved();

            return;
        }

        if (! $this->revisionHasScheduleProducts($revision)) {
            $this->logProjectDetailsSaved();

            return;
        }

        try {
            $pdf = app(ProjectSchedulePdfService::class);
            $filename = $pdf->filename($this->record, $revision);
            $result = app(SalesforceService::class)->uploadSchedulePdf(
                project: $this->record,
                pdfContent: $pdf->content($this->record, $revision),
                filename: $filename,
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Salesforce upload failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            $this->logProjectDetailsSaved();

            return;
        }

        if (! $result['success']) {
            Notification::make()
                ->title('Salesforce upload failed')
                ->body($result['message'] ?? 'The schedule PDF could not be uploaded to Salesforce.')
                ->danger()
                ->send();

            $this->logProjectDetailsSaved();

            return;
        }

        $salesforceUrl = $result['url'] ?? null;

        Notification::make()
            ->title('Schedule PDF uploaded to Salesforce')
            ->body($salesforceUrl ? 'The file is available on Salesforce.' : 'The file is available on the Salesforce Opportunity.')
            ->actions($salesforceUrl ? [
                Action::make('viewSalesforceFile')
                    ->label('View in Salesforce')
                    ->url($salesforceUrl, shouldOpenInNewTab: true),
            ] : [])
            ->success()
            ->send();

        $this->logProjectDetailsSaved($salesforceUrl, $filename);
    }

    private function logProjectDetailsSaved(?string $salesforceUrl = null, ?string $filename = null): void
    {
        $payload = [];

        if ($salesforceUrl) {
            $payload['salesforce_pdf_url'] = $salesforceUrl;
        }

        if ($filename) {
            $payload['salesforce_pdf_filename'] = $filename;
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => $this->record->id,
            'action_type' => 'project.details_saved',
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => $this->record->name,
            'revision_number' => $this->record->revision,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }

    private function revisionHasScheduleProducts(ProjectRevision $revision): bool
    {
        return $revision->areas()
            ->whereHas('lines', fn ($query) => $query->whereNotNull('code')->where('code', '!=', ''))
            ->exists();
    }

    private function findAreaInViewingRevision(int $areaId): ProjectArea
    {
        return ProjectArea::where('id', $areaId)
            ->where('project_id', $this->record->id)
            ->where('project_revision_id', $this->viewingRevisionId)
            ->firstOrFail();
    }

    private function findLineInViewingRevision(int $lineId): ProjectLine
    {
        return ProjectLine::whereHas('area', function ($query): void {
            $query->where('project_id', $this->record->id)
                ->where('project_revision_id', $this->viewingRevisionId);
        })->findOrFail($lineId);
    }

    // ── Revision management ───────────────────────────────────────────────────

    #[Computed]
    public function projectRevisions(): Collection
    {
        return $this->record->revisions()->with('creator')->get();
    }

    #[Computed]
    public function isViewingRevisionValidated(): bool
    {
        if (! $this->viewingRevisionId) {
            return false;
        }

        return ProjectRevision::where('project_id', $this->record->id)
            ->whereKey($this->viewingRevisionId)
            ->where('status', ProjectRevisionStatus::Approved->value)
            ->exists();
    }

    public function setActiveRevision(int $revisionId): void
    {
        $revision = ProjectRevision::where('project_id', $this->record->id)
            ->findOrFail($revisionId);

        $this->record->update([
            'active_revision_id' => $revision->id,
            'revision' => $revision->revision_number,
        ]);

        $this->record->refresh();
        $this->viewingRevisionId = $revision->id;
        unset($this->isViewingRevisionValidated);
        $this->revisionsModalOpen = false;
    }

    public function createNewRevision(): void
    {
        $sourceRevision = ProjectRevision::where('project_id', $this->record->id)
            ->findOrFail($this->viewingRevisionId);

        $newRevisionNumber = $this->record->revisions()->max('revision_number') + 1;

        $newRevision = ProjectRevision::create([
            'project_id' => $this->record->id,
            'revision_number' => $newRevisionNumber,
            'created_by' => auth()->id(),
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => $this->record->id,
            'action_type' => 'revision.created',
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => $this->record->name,
            'revision_number' => $newRevisionNumber,
            'payload' => ['revision_number' => $newRevisionNumber],
        ]);

        foreach ($sourceRevision->areas()->with('lines')->get() as $area) {
            $newArea = ProjectArea::create([
                'project_id' => $this->record->id,
                'project_revision_id' => $newRevision->id,
                'name' => $area->name,
                'sort_order' => $area->sort_order,
            ]);

            foreach ($area->lines as $line) {
                $newArea->lines()->create($line->only([
                    'product_id', 'code', 'ref', 'description', 'qty',
                    'type', 'unit_price', 'notes', 'status', 'sort_order',
                    'approved', 'approved_at', 'approved_by',
                    'validation_flagged', 'validation_note',
                ]));
            }
        }

        $this->record->update([
            'active_revision_id' => $newRevision->id,
            'revision' => $newRevisionNumber,
        ]);

        $this->record->refresh();
        $this->viewingRevisionId = $newRevision->id;
        unset($this->isViewingRevisionValidated);
        $this->revisionsModalOpen = false;
    }

    // ── Area management ──────────────────────────────────────────────────────

    public function addArea(): void
    {
        $this->ensureViewingRevisionIsEditable();

        $this->validate(['newAreaName' => 'required|string|min:1|max:255']);

        $maxSort = ProjectArea::where('project_revision_id', $this->viewingRevisionId)->max('sort_order') ?? -1;

        ProjectArea::create([
            'project_id' => $this->record->id,
            'project_revision_id' => $this->viewingRevisionId,
            'name' => trim($this->newAreaName),
            'sort_order' => $maxSort + 1,
        ]);

        $this->newAreaName = '';
    }

    public function removeArea(int $areaId): void
    {
        $this->ensureViewingRevisionIsEditable();

        $this->findAreaInViewingRevision($areaId)->delete();
    }

    // ── Line management ───────────────────────────────────────────────────────

    public function openProductPicker(int $areaId): void
    {
        $this->productPickerAreaId = $areaId;
        $this->productSearch = '';
        $this->productSiteFilter = '';
        $this->productTypeFilter = '';
        $this->productPage = 1;
        $this->productSelections = [];
        $this->productPickerOpen = true;
    }

    public function closeProductPicker(): void
    {
        $this->productPickerOpen = false;
        $this->productPickerAreaId = null;
        $this->productSelections = [];
    }

    public function updatedProductSearch(): void
    {
        $this->productPage = 1;
    }

    public function updatedProductSiteFilter(): void
    {
        $this->productTypeFilter = '';
        $this->productPage = 1;
    }

    public function updatedProductTypeFilter(): void
    {
        $this->productPage = 1;
    }

    public function toggleProductSelection(int $productId): void
    {
        if (isset($this->productSelections[$productId])) {
            unset($this->productSelections[$productId]);
        } else {
            $this->productSelections[$productId] = ['qty' => 1];
        }
    }

    public function setProductSelectionQty(int $productId, int $qty): void
    {
        $this->productSelections[$productId] = ['qty' => max(1, $qty)];
    }

    public function addSelectedProducts(): void
    {
        $this->ensureViewingRevisionIsEditable();

        if (! $this->productPickerAreaId || empty($this->productSelections)) {
            return;
        }

        $area = $this->findAreaInViewingRevision($this->productPickerAreaId);

        $maxSort = $area->lines()->max('sort_order') ?? -1;

        foreach ($this->productSelections as $productId => $selection) {
            $product = Product::find($productId);

            if (! $product) {
                continue;
            }

            $maxSort++;

            $area->lines()->create([
                'product_id' => $product->id,
                'code' => $product->sku,
                'description' => $product->displayDescription(),
                'qty' => $selection['qty'],
                'type' => ProjectLineType::Standard->value,
                'unit_price' => $product->price,
                'status' => self::LineStatusPending,
                'sort_order' => $maxSort,
            ]);
        }

        $this->closeProductPicker();
    }

    public function openPasteProductsModal(int $areaId): void
    {
        $this->ensureViewingRevisionIsEditable();

        $this->findAreaInViewingRevision($areaId);

        $this->pasteProductsAreaId = $areaId;
        $this->pastedProductData = '';
        $this->pasteProductsError = null;
        $this->pasteAcrossAllAreas = true;
        $this->pasteProductsModalOpen = true;
    }

    public function closePasteProductsModal(): void
    {
        $this->pasteProductsModalOpen = false;
        $this->pasteProductsAreaId = null;
        $this->pastedProductData = '';
        $this->pasteProductsError = null;
        $this->pasteAcrossAllAreas = true;
    }

    public function addPastedProducts(): void
    {
        $this->ensureViewingRevisionIsEditable();

        if (! $this->pasteProductsAreaId) {
            return;
        }

        $this->pasteProductsError = null;

        $area = $this->findAreaInViewingRevision($this->pasteProductsAreaId);
        $parsed = $this->parsePastedProductData();
        $rows = $parsed['rows'];
        $rejectedRows = $parsed['rejected'];

        if ($rows === []) {
            $this->pasteProductsError = $rejectedRows > 0
                ? $rejectedRows.' pasted '.Str::plural('row', $rejectedRows).' could not be imported. Check that each row has Qty and SKU columns.'
                : 'Paste product rows with Qty and SKU columns.';

            return;
        }

        $rowsBySku = collect($rows)
            ->keyBy(fn (array $row): string => $this->normaliseSku($row['sku']));

        $productsBySku = Product::query()
            ->whereIn(DB::raw('upper(sku)'), $rowsBySku->keys())
            ->get()
            ->keyBy(fn (Product $product): string => $this->normaliseSku($product->sku));

        $maxSort = $area->lines()->max('sort_order') ?? -1;

        try {
            DB::transaction(function () use ($area, $productsBySku, $rowsBySku, &$maxSort): void {
                $scopedLines = $this->pasteAcrossAllAreas
                    ? ProjectLine::query()
                        ->whereHas('area', fn ($query) => $query
                            ->where('project_id', $this->record->id)
                            ->where('project_revision_id', $this->viewingRevisionId))
                        ->get()
                    : $area->lines()->get();

                $linesBySku = $scopedLines
                    ->filter(fn (ProjectLine $line): bool => filled($line->code))
                    ->groupBy(fn (ProjectLine $line): string => $this->normaliseSku((string) $line->code));

                foreach ($linesBySku as $sku => $lines) {
                    if (! $rowsBySku->has($sku)) {
                        foreach ($lines as $line) {
                            $line->update($this->unpricedLineUpdateData());
                        }

                        continue;
                    }

                    /** @var array{qty: int, sku: string, unit_price: ?string} $row */
                    $row = $rowsBySku->get($sku);
                    /** @var Product|null $product */
                    $product = $productsBySku->get($sku);

                    foreach ($lines as $line) {
                        $line->update($this->pastedLineUpdateData($row, $product));
                    }
                }

                foreach ($rowsBySku as $sku => $row) {
                    if ($linesBySku->has($sku)) {
                        continue;
                    }

                    /** @var Product|null $product */
                    $product = $productsBySku->get($sku);
                    $maxSort++;

                    $area->lines()->create([
                        'product_id' => $product?->id,
                        'code' => $product?->sku ?? $row['sku'],
                        'description' => $product?->displayDescription() ?? '',
                        'qty' => $row['qty'],
                        'type' => $product ? ProjectLineType::Standard->value : ProjectLineType::Custom->value,
                        'unit_price' => $row['unit_price'] ?? $product?->price,
                        'status' => self::LineStatusPriced,
                        'sort_order' => $maxSort,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            report($exception);

            $this->pasteProductsError = 'The pasted rows could not be saved. Please check the pasted data and try again.';

            return;
        }

        $this->closePasteProductsModal();

        if ($rejectedRows > 0) {
            Notification::make()
                ->title('Some products were not added')
                ->body($rejectedRows.' pasted '.Str::plural('row', $rejectedRows).' could not be imported. The valid rows were added.')
                ->warning()
                ->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function unpricedLineUpdateData(): array
    {
        return [
            'status' => self::LineStatusUnpriced,
            'approved' => false,
            'approved_at' => null,
            'approved_by' => null,
            'validation_flagged' => false,
            'validation_note' => null,
        ];
    }

    /**
     * @param  array{qty: int, sku: string, unit_price: ?string}  $row
     * @return array<string, mixed>
     */
    private function pastedLineUpdateData(array $row, ?Product $product): array
    {
        $data = [
            'product_id' => $product?->id,
            'code' => $product?->sku ?? $row['sku'],
            'description' => $product?->displayDescription() ?? '',
            'type' => $product ? ProjectLineType::Standard->value : ProjectLineType::Custom->value,
            'unit_price' => $row['unit_price'] ?? $product?->price,
            'status' => self::LineStatusPriced,
            'approved' => false,
            'approved_at' => null,
            'approved_by' => null,
            'validation_flagged' => false,
            'validation_note' => null,
        ];

        if (! $this->pasteAcrossAllAreas) {
            $data['qty'] = $row['qty'];
        }

        return $data;
    }

    private function normaliseSku(string $sku): string
    {
        return strtoupper(trim($sku));
    }

    /**
     * @return array{rows: array<int, array{qty: int, sku: string, unit_price: ?string}>, rejected: int}
     */
    private function parsePastedProductData(): array
    {
        $rows = [];
        $rejected = 0;
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return ['rows' => $rows, 'rejected' => 0];
        }

        fwrite($handle, $this->pastedProductData);
        rewind($handle);

        while (($columns = fgetcsv($handle, 0, "\t", '"', '')) !== false) {
            $qty = trim((string) ($columns[0] ?? ''));
            $sku = trim((string) ($columns[1] ?? ''));
            $price = $this->pastedProductPrice($columns);

            if ($qty === '' && $sku === '' && $price === '') {
                continue;
            }

            if ($this->isPastedProductHeader($qty, $sku)) {
                continue;
            }

            if (! is_numeric($qty) || $sku === '' || ($price !== '' && ! is_numeric($price))) {
                $rejected++;

                continue;
            }

            $rows[] = [
                'qty' => max(1, (int) $qty),
                'sku' => $sku,
                'unit_price' => $price === '' ? null : number_format(max(0, (float) $price), 2, '.', ''),
            ];
        }

        fclose($handle);

        return ['rows' => $rows, 'rejected' => $rejected];
    }

    /**
     * @param  array<int, string|null>  $columns
     */
    private function pastedProductPrice(array $columns): string
    {
        if (count($columns) >= 6) {
            return trim((string) ($columns[5] ?? ''));
        }

        if (count($columns) >= 4) {
            return trim((string) ($columns[3] ?? ''));
        }

        return '';
    }

    private function isPastedProductHeader(string $qty, string $sku): bool
    {
        return strtolower($qty) === 'qty' && strtolower($sku) === 'sku';
    }

    #[Computed]
    public function productPickerProducts(): LengthAwarePaginator
    {
        return Product::query()
            ->when(
                $this->productSearch,
                fn ($q) => $q->where(function ($q): void {
                    $q->where('product_name', 'like', "%{$this->productSearch}%")
                        ->orWhere('sku', 'like', "%{$this->productSearch}%")
                        ->orWhere('description', 'like', "%{$this->productSearch}%");
                })
            )
            ->when($this->productSiteFilter, fn ($q) => $q->where('site', $this->productSiteFilter))
            ->when($this->productTypeFilter, fn ($q) => $q->where('type_name', $this->productTypeFilter))
            ->orderBy('product_name')
            ->paginate(15, ['*'], 'product_page', $this->productPage);
    }

    #[Computed]
    public function productSiteOptions(): array
    {
        return Product::query()
            ->whereNotNull('site')
            ->distinct()
            ->orderBy('site')
            ->pluck('site')
            ->toArray();
    }

    #[Computed]
    public function productTypeOptions(): array
    {
        return Product::query()
            ->whereNotNull('type_name')
            ->when($this->productSiteFilter, fn ($q) => $q->where('site', $this->productSiteFilter))
            ->distinct()
            ->orderBy('type_name')
            ->pluck('type_name')
            ->toArray();
    }

    public function addProduct(int $areaId): void
    {
        $this->ensureViewingRevisionIsEditable();

        $this->openProductPicker($areaId);
    }

    public function addBlankLine(int $areaId): void
    {
        $this->ensureViewingRevisionIsEditable();

        $area = $this->findAreaInViewingRevision($areaId);

        $maxSort = $area->lines()->max('sort_order') ?? -1;

        $area->lines()->create([
            'description' => '',
            'qty' => 1,
            'type' => ProjectLineType::Custom->value,
            'sort_order' => $maxSort + 1,
        ]);
    }

    public function updateLineField(int $lineId, string $field, mixed $value): void
    {
        $this->ensureViewingRevisionIsEditable();

        $allowed = ['code', 'ref', 'description', 'qty', 'unit_price', 'notes'];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        $line = $this->findLineInViewingRevision($lineId);

        if ($field === 'ref') {
            $value = ($value !== '' && $value !== null)
                ? strtoupper(substr((string) $value, 0, 6))
                : null;

            $line->update([
                'ref' => $value,
                'approved' => false,
                'approved_at' => null,
                'approved_by' => null,
                'validation_flagged' => false,
                'validation_note' => null,
            ]);

            return;
        }

        $line->update([
            $field => $this->normaliseLineFieldValue($field, $value),
            'approved' => false,
            'approved_at' => null,
            'approved_by' => null,
            'validation_flagged' => false,
            'validation_note' => null,
        ]);

        if (in_array($field, ['code', 'description'], true) && $line->product_id !== null) {
            $line->refresh();
            $this->recalculateLineType($line);
        }
    }

    private function normaliseLineFieldValue(string $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if ($field === 'qty') {
            return max(1, (int) $value);
        }

        if ($field === 'unit_price') {
            return max(0, (float) $value);
        }

        return $value;
    }

    private function recalculateLineType(ProjectLine $line): void
    {
        $product = Product::find($line->product_id);

        if ($product === null) {
            return;
        }

        $unchanged = $line->code === $product->sku
            && $line->description === $product->displayDescription();

        $newType = $unchanged ? ProjectLineType::Standard : ProjectLineType::Modified;

        if ($line->type !== $newType) {
            $line->update(['type' => $newType->value]);
        }
    }

    public function duplicateLine(int $lineId): void
    {
        $this->ensureViewingRevisionIsEditable();

        $line = $this->findLineInViewingRevision($lineId);

        $siblings = ProjectLine::where('project_area_id', $line->project_area_id)
            ->orderBy('sort_order')
            ->pluck('id')
            ->toArray();

        $copy = $line->replicate();
        $copy->approved = false;
        $copy->approved_at = null;
        $copy->approved_by = null;
        $copy->validation_flagged = false;
        $copy->validation_note = null;
        $copy->save();

        $pos = array_search($lineId, $siblings);
        array_splice($siblings, $pos + 1, 0, [$copy->id]);

        foreach ($siblings as $i => $id) {
            ProjectLine::where('id', $id)->update(['sort_order' => $i]);
        }
    }

    public function deleteLine(int $lineId): void
    {
        $this->ensureViewingRevisionIsEditable();

        $this->findLineInViewingRevision($lineId)->delete();
    }

    public function sortLine(int $lineId, int $newPosition, int $targetAreaId): void
    {
        $this->ensureViewingRevisionIsEditable();

        $line = $this->findLineInViewingRevision($lineId);
        $targetArea = $this->findAreaInViewingRevision($targetAreaId);

        $sourceAreaId = $line->project_area_id;

        DB::transaction(function () use ($line, $lineId, $newPosition, $targetArea, $sourceAreaId): void {
            if ($sourceAreaId !== $targetArea->id) {
                $line->update(['project_area_id' => $targetArea->id]);
            }

            $targetSiblings = ProjectLine::where('project_area_id', $targetArea->id)
                ->where('id', '!=', $lineId)
                ->orderBy('sort_order')
                ->pluck('id')
                ->toArray();

            array_splice($targetSiblings, $newPosition, 0, [$lineId]);

            foreach ($targetSiblings as $i => $id) {
                ProjectLine::where('id', $id)->update(['sort_order' => $i]);
            }

            if ($sourceAreaId !== $targetArea->id) {
                ProjectLine::where('project_area_id', $sourceAreaId)
                    ->orderBy('sort_order')
                    ->get()
                    ->each(fn ($l, $i) => $l->update(['sort_order' => $i]));
            }
        });
    }

    private function ensureViewingRevisionIsEditable(): void
    {
        abort_if($this->isViewingRevisionValidated, 403, 'Approved revisions are locked against editing.');
    }
}
