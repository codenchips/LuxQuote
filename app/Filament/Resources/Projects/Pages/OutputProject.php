<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectRevisionStatus;
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
}
