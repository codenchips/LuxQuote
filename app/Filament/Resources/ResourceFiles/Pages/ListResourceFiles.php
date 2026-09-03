<?php

namespace App\Filament\Resources\ResourceFiles\Pages;

use App\Filament\Resources\ResourceFiles\ResourceFileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListResourceFiles extends ListRecords
{
    protected static string $resource = ResourceFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
