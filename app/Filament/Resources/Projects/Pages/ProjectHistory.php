<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use App\Filament\Resources\Projects\Pages\Concerns\HasProjectSubNav;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ActivityLog;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ProjectHistory extends ViewRecord implements HasTable
{
    use HasProjectSubNav;
    use InteractsWithTable;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-history';

    protected static ?string $navigationLabel = 'Project History';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can('project-history.view') ?? false;
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

    public function table(Table $table): Table
    {
        return ActivityLogsTable::configure(
            $table->query(
                ActivityLog::query()
                    ->where('project_id', $this->record->getKey())
            ),
        );
    }
}
