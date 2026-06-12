<?php

namespace App\Filament\Resources\PermissionGroups\Pages;

use App\Filament\Resources\PermissionGroups\PermissionGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPermissionGroup extends EditRecord
{
    protected static string $resource = PermissionGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => ! $this->record->is_system),
        ];
    }
}
