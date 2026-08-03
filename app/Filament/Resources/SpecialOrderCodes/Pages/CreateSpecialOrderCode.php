<?php

namespace App\Filament\Resources\SpecialOrderCodes\Pages;

use App\Filament\Resources\SpecialOrderCodes\SpecialOrderCodeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSpecialOrderCode extends CreateRecord
{
    protected static string $resource = SpecialOrderCodeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl();
    }
}
