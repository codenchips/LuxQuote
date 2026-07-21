<?php

namespace App\Filament\Resources\ActivityLogArchives\Pages;

use App\Filament\Resources\ActivityLogArchives\ActivityLogArchiveResource;
use Filament\Resources\Pages\ListRecords;

class ListActivityLogArchives extends ListRecords
{
    protected static string $resource = ActivityLogArchiveResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
