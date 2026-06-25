<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use App\Enums\ProjectRevisionStatus;
use App\Filament\Resources\Projects\Pages\Concerns\HasProjectSubNav;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ActivityLog;
use App\Models\DocumentPack;
use App\Models\DocumentPackItem;
use App\Models\ProjectRevision;
use App\Services\DocumentPackPdfService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
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

    public bool $documentPackDirty = false;

    public ?int $generationRevisionId = null;

    public string $outputTab = 'single';

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
            ProjectRevision::labelForNumber($this->record->revision),
        ]);

        return new HtmlString(implode(' &middot; ', $parts));
    }

    public function getSchedulePdfUrl(): string
    {
        abort_unless($this->canProduceUnpricedSchedule(), 403);

        return route('projects.pdf.schedule', [
            'project' => $this->record,
            'revision' => $this->record->active_revision_id,
        ]);
    }

    public function getQuotePdfUrl(): string
    {
        abort_unless($this->canProduceQuote(), 403);

        return route('projects.pdf.quote', [
            'project' => $this->record,
            'revision' => $this->record->active_revision_id,
        ]);
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

    public function newDocumentPack(): void
    {
        abort_unless($this->canManageDocumentPacks(), 403);

        $this->selectedDocumentPackId = null;
        $this->documentPackName = '';
        $item = $this->emptyDocumentPackItem();
        $this->documentPackItems = [$item['key'] => $item];
        $this->documentPackUploads = [];
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
                            'original_filename' => $upload->getClientOriginalName(),
                        ];

                        if ($oldPath !== null) {
                            $oldFilesToDelete[] = [$oldDisk ?? 'local', $oldPath];
                        }
                    } elseif ($role->source() === DocumentPackItemSource::Generated) {
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
        return $this->canViewPrices() && (auth()->user()?->can('quote-approval.request') ?? false);
    }

    public function canManageDocumentPacks(): bool
    {
        return auth()->user()?->can('output.manage-document-packs') ?? false;
    }

    public function canProduceDocumentPacks(): bool
    {
        return auth()->user()?->can('output.produce-document-packs') ?? false;
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
            $hasExistingFile = $this->selectedDocumentPackId !== null
                && filled($state['id'] ?? null)
                && DocumentPackItem::query()
                    ->where('document_pack_id', $this->selectedDocumentPackId)
                    ->whereKey($state['id'])
                    ->whereNotNull('file_path')
                    ->exists();

            if (! $upload instanceof TemporaryUploadedFile && ! $hasExistingFile) {
                throw ValidationException::withMessages([
                    "documentPackUploads.{$state['key']}" => 'Upload a PDF for this document.',
                ]);
            }

            if (! $upload instanceof TemporaryUploadedFile) {
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
                unset($this->documentPackItems[$key], $this->documentPackUploads[$key]);
                $removedCount++;

                continue;
            }

            if ($role->source() !== DocumentPackItemSource::Uploaded) {
                continue;
            }

            $upload = $this->documentPackUploads[$state['key']] ?? null;

            if ($upload instanceof TemporaryUploadedFile || $this->documentPackItemHasExistingFile($state)) {
                continue;
            }

            unset($this->documentPackItems[$key], $this->documentPackUploads[$key]);
            $removedCount++;
        }

        return $removedCount;
    }

    /**
     * @param  array{key: string, id: int|null, role: string, file_path: string|null, original_filename: string|null}  $state
     */
    private function documentPackItemHasExistingFile(array $state): bool
    {
        return $this->selectedDocumentPackId !== null
            && filled($state['id'] ?? null)
            && DocumentPackItem::query()
                ->where('document_pack_id', $this->selectedDocumentPackId)
                ->whereKey($state['id'])
                ->whereNotNull('file_path')
                ->exists();
    }

    private function canUseDocumentRole(DocumentPackItemRole $role): bool
    {
        return match ($role) {
            DocumentPackItemRole::Quote => $this->canProduceQuote(),
            DocumentPackItemRole::UnpricedSchedule => $this->canProduceUnpricedSchedule(),
            DocumentPackItemRole::Cover, DocumentPackItemRole::Legal => $this->canManageDocumentPacks(),
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
