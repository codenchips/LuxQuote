<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectLineType;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Models\Product;
use App\Models\ProjectArea;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;

class ViewProject extends ViewRecord
{
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

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->viewingRevisionId = $this->record->active_revision_id;
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
                ->after(fn () => $this->record->refresh()),

            Action::make('manageRevisions')
                ->label('Revisions')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->action(fn () => $this->revisionsModalOpen = true),

            Action::make('manageAreas')
                ->label('Areas')
                ->icon(Heroicon::OutlinedMapPin)
                ->color('gray')
                ->modalHeading('Manage Areas')
                ->modalDescription('Define the rooms, floors, and areas for this project.')
                ->modalContent(fn (): View => view(
                    'filament.resources.projects.pages.areas-modal-content',
                    ['areas' => $this->getAreas()],
                ))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
        ];
    }

    public function getAreas(): Collection
    {
        return ProjectArea::where('project_revision_id', $this->viewingRevisionId)
            ->with(['lines' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();
    }

    // ── Revision management ───────────────────────────────────────────────────

    #[Computed]
    public function projectRevisions(): Collection
    {
        return $this->record->revisions()->with('creator')->get();
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
                ]));
            }
        }

        $this->record->update([
            'active_revision_id' => $newRevision->id,
            'revision' => $newRevisionNumber,
        ]);

        $this->record->refresh();
        $this->viewingRevisionId = $newRevision->id;
        $this->revisionsModalOpen = false;
    }

    // ── Area management ──────────────────────────────────────────────────────

    public function addArea(): void
    {
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
        ProjectArea::where('id', $areaId)
            ->where('project_revision_id', $this->viewingRevisionId)
            ->firstOrFail()
            ->delete();
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
        if (! $this->productPickerAreaId || empty($this->productSelections)) {
            return;
        }

        $area = ProjectArea::where('id', $this->productPickerAreaId)
            ->where('project_revision_id', $this->viewingRevisionId)
            ->firstOrFail();

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
                'description' => $product->product_name,
                'qty' => $selection['qty'],
                'type' => ProjectLineType::Standard->value,
                'sort_order' => $maxSort,
            ]);
        }

        $this->closeProductPicker();
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
        $this->openProductPicker($areaId);
    }

    public function addBlankLine(int $areaId): void
    {
        $area = ProjectArea::where('id', $areaId)
            ->where('project_revision_id', $this->viewingRevisionId)
            ->firstOrFail();

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
        $allowed = ['code', 'ref', 'description', 'qty', 'unit_price', 'notes'];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        $line = ProjectLine::whereHas('area', fn ($q) => $q->where('project_id', $this->record->id))
            ->findOrFail($lineId);

        if ($field === 'ref') {
            $value = ($value !== '' && $value !== null)
                ? strtoupper(substr((string) $value, 0, 6))
                : null;

            $line->update(['ref' => $value]);

            return;
        }

        $line->update([$field => $value !== '' ? $value : null]);

        if (in_array($field, ['code', 'description'], true) && $line->product_id !== null) {
            $line->refresh();
            $this->recalculateLineType($line);
        }
    }

    private function recalculateLineType(ProjectLine $line): void
    {
        $product = Product::find($line->product_id);

        if ($product === null) {
            return;
        }

        $unchanged = $line->code === $product->sku
            && $line->description === $product->product_name;

        $newType = $unchanged ? ProjectLineType::Standard : ProjectLineType::Modified;

        if ($line->type !== $newType) {
            $line->update(['type' => $newType->value]);
        }
    }

    public function duplicateLine(int $lineId): void
    {
        $line = ProjectLine::whereHas('area', fn ($q) => $q->where('project_id', $this->record->id))
            ->findOrFail($lineId);

        $siblings = ProjectLine::where('project_area_id', $line->project_area_id)
            ->orderBy('sort_order')
            ->pluck('id')
            ->toArray();

        $copy = $line->replicate();
        $copy->save();

        $pos = array_search($lineId, $siblings);
        array_splice($siblings, $pos + 1, 0, [$copy->id]);

        foreach ($siblings as $i => $id) {
            ProjectLine::where('id', $id)->update(['sort_order' => $i]);
        }
    }

    public function deleteLine(int $lineId): void
    {
        ProjectLine::whereHas('area', fn ($q) => $q->where('project_id', $this->record->id))
            ->findOrFail($lineId)
            ->delete();
    }

    public function sortLine(int $lineId, int $newPosition, int $targetAreaId): void
    {
        $line = ProjectLine::whereHas('area', fn ($q) => $q->where('project_id', $this->record->id))
            ->findOrFail($lineId);

        $sourceAreaId = $line->project_area_id;

        DB::transaction(function () use ($line, $lineId, $newPosition, $targetAreaId, $sourceAreaId): void {
            if ($sourceAreaId !== $targetAreaId) {
                $line->update(['project_area_id' => $targetAreaId]);
            }

            $targetSiblings = ProjectLine::where('project_area_id', $targetAreaId)
                ->where('id', '!=', $lineId)
                ->orderBy('sort_order')
                ->pluck('id')
                ->toArray();

            array_splice($targetSiblings, $newPosition, 0, [$lineId]);

            foreach ($targetSiblings as $i => $id) {
                ProjectLine::where('id', $id)->update(['sort_order' => $i]);
            }

            if ($sourceAreaId !== $targetAreaId) {
                ProjectLine::where('project_area_id', $sourceAreaId)
                    ->orderBy('sort_order')
                    ->get()
                    ->each(fn ($l, $i) => $l->update(['sort_order' => $i]));
            }
        });
    }
}
