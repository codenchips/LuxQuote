<?php

namespace App\Filament\Pages\Auth;

use App\Enums\JobRole;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profile Details')
                    ->description('Update your personal information.')
                    ->schema([
                        $this->getNameFormComponent(),
                        TextInput::make('area_code')
                            ->label('Area Code')
                            ->maxLength(20),
                        Select::make('job_role')
                            ->label('Role')
                            ->options(collect(JobRole::cases())->mapWithKeys(
                                fn (JobRole $role) => [$role->value => $role->label()]
                            )),
                    ]),
                Section::make('Password')
                    ->description('Leave blank to keep your current password.')
                    ->schema([
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ]),
            ]);
    }
}
