<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectRevisionStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Projects\Pages\Concerns\HasProjectSubNav;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectRevision;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class OutputProject extends ViewRecord
{
    use HasProjectSubNav;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.output-project';

    protected static ?string $navigationLabel = 'Output';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): string|HtmlString|null
    {
        $parts = array_filter([
            $this->record->visibility?->label(),
            $this->record->revision ? 'Rev '.$this->record->revision : null,
        ]);

        return new HtmlString(implode(' &middot; ', $parts));
    }

    public function getSchedulePdfUrl(): string
    {
        return route('projects.pdf.schedule', [
            'project' => $this->record,
            'revision' => $this->record->active_revision_id,
        ]);
    }

    public function getQuotePdfUrl(): string
    {
        return route('projects.pdf.quote', [
            'project' => $this->record,
            'revision' => $this->record->active_revision_id,
        ]);
    }

    public function getCsvExportUrl(): string
    {
        return route('projects.export.csv', [
            'project' => $this->record,
            'revision' => $this->record->active_revision_id,
        ]);
    }

    public function getUnpricedCsvExportUrl(): string
    {
        return route('projects.export.unpriced-csv', [
            'project' => $this->record,
            'revision' => $this->record->active_revision_id,
        ]);
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
}
