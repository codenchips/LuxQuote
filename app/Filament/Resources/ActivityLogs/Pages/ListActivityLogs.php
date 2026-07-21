<?php

namespace App\Filament\Resources\ActivityLogs\Pages;

use App\Filament\Resources\ActivityLogArchives\ActivityLogArchiveResource;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewArchivedLogs')
                ->label('Archived logs')
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->url(ActivityLogArchiveResource::getUrl('index')),
        ];
    }
}
