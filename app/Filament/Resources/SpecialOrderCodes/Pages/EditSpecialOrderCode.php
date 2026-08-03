<?php

namespace App\Filament\Resources\SpecialOrderCodes\Pages;

use App\Filament\Resources\SpecialOrderCodes\SpecialOrderCodeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSpecialOrderCode extends EditRecord
{
    protected static string $resource = SpecialOrderCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
