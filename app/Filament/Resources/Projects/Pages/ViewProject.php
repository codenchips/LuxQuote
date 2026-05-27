<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectLineType;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Product;
use App\Models\ProjectArea;
use App\Models\ProjectLine;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
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

    // ── Product picker state ─────────────────────────────────────────────────

    public bool $productPickerOpen = false;

    public ?int $productPickerAreaId = null;

    public string $productSearch = '';

    public string $productTypeFilter = '';

    public int $productPage = 1;

    /** @var array<int, array{qty: int}> */
    public array $productSelections = [];

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
            $this->record->revision ? 'Rev '.$this->record->revision : null,
        ]);

        return new HtmlString(implode(' &middot; ', $parts));
    }

    protected function getHeaderActions(): array
    {
        return [
            // Action::make('back')
            //     ->label('All Projects')
            //     ->icon(Heroicon::OutlinedArrowLeft)
            //     ->color('gray')
            //     ->url(ProjectResource::getUrl('index')),

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
        return $this->record
            ->areas()
            ->with(['lines' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();
    }

    // ── Area management ──────────────────────────────────────────────────────

    public function addArea(): void
    {
        $this->validate(['newAreaName' => 'required|string|min:1|max:255']);

        $maxSort = $this->record->areas()->max('sort_order') ?? -1;

        $this->record->areas()->create([
            'name' => trim($this->newAreaName),
            'sort_order' => $maxSort + 1,
        ]);

        $this->newAreaName = '';
    }

    public function removeArea(int $areaId): void
    {
        ProjectArea::where('id', $areaId)
            ->where('project_id', $this->record->id)
            ->firstOrFail()
            ->delete();
    }

    // ── Line management ───────────────────────────────────────────────────────

    public function openProductPicker(int $areaId): void
    {
        $this->productPickerAreaId = $areaId;
        $this->productSearch = '';
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
            ->where('project_id', $this->record->id)
            ->firstOrFail();

        $maxSort = $area->lines()->max('sort_order') ?? -1;

        foreach ($this->productSelections as $productId => $selection) {
            $product = Product::find($productId);

            if (! $product) {
                continue;
            }

            $maxSort++;

            $area->lines()->create([
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
            ->when($this->productTypeFilter, fn ($q) => $q->where('type_name', $this->productTypeFilter))
            ->orderBy('product_name')
            ->paginate(15, ['*'], 'product_page', $this->productPage);
    }

    #[Computed]
    public function productTypeOptions(): array
    {
        return Product::query()
            ->whereNotNull('type_name')
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
            ->where('project_id', $this->record->id)
            ->firstOrFail();

        $maxSort = $area->lines()->max('sort_order') ?? -1;

        $area->lines()->create([
            'description' => '',
            'qty' => 1,
            'type' => ProjectLineType::Standard->value,
            'sort_order' => $maxSort + 1,
        ]);
    }

    public function updateLineField(int $lineId, string $field, mixed $value): void
    {
        $allowed = ['code', 'description', 'qty', 'unit_price', 'notes'];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        ProjectLine::whereHas('area', fn ($q) => $q->where('project_id', $this->record->id))
            ->findOrFail($lineId)
            ->update([$field => $value !== '' ? $value : null]);
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
