<?php

namespace App\Filament\Resources\SpecialOrderCodes\Pages;

use App\Filament\Resources\SpecialOrderCodes\SpecialOrderCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSpecialOrderCodes extends ListRecords
{
    protected static string $resource = SpecialOrderCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New special'),
        ];
    }
}
