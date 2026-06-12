<?php

namespace App\Filament\Resources\PermissionGroups\Pages;

use App\Filament\Resources\PermissionGroups\PermissionGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePermissionGroup extends CreateRecord
{
    protected static string $resource = PermissionGroupResource::class;
}
