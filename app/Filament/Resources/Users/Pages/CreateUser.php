<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Automatically mark new users as verified since email sending may be unavailable.
     */
    protected function afterCreate(): void
    {
        $this->record->forceFill(['email_verified_at' => now()])->save();
    }
}
