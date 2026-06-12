<?php

namespace App\Filament\Resources\PermissionGroups\Pages;

use App\Filament\Resources\PermissionGroups\PermissionGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPermissionGroups extends ListRecords
{
    protected static string $resource = PermissionGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
