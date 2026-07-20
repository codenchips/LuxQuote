<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Html;
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
                        Select::make('project_list_team_id')
                            ->label('Project list view')
                            ->options(fn (): array => self::projectListViewOptions())
                            ->afterStateHydrated(function (Select $component, mixed $state): void {
                                $component->state(filled($state) ? (string) $state : 'all');
                            })
                            ->dehydrateStateUsing(fn (mixed $state): ?int => self::normaliseProjectListTeamId($state))
                            ->selectablePlaceholder(false)
                            ->helperText('Choose which projects are shown by default on the Projects page.'),
                    ])
                    ->columnSpanFull(),
                Section::make('Teams')
                    ->description('Your team memberships affect which team-scoped projects you can see.')
                    ->schema([
                        Html::make(fn (): string => self::teamsHtml()),
                    ])
                    ->columnSpan(1),
                Section::make('User Group')
                    ->description('Your group controls which app permissions are available to you.')
                    ->schema([
                        Html::make(fn (): string => self::permissionGroupHtml()),
                    ])
                    ->columnSpan(1),
                Section::make('Password')
                    ->description('Leave blank to keep your current password.')
                    ->schema([
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function teamsHtml(): string
    {
        $teams = auth()->user()?->teams()
            ->orderBy('name')
            ->pluck('name')
            ->all() ?? [];

        if ($teams === []) {
            return '<div class="min-h-14"><p class="text-sm text-gray-500 dark:text-gray-400">You are not currently a member of any teams.</p></div>';
        }

        $items = collect($teams)
            ->map(fn (string $team): string => '<span class="inline-flex rounded-md border border-info-500/40 bg-info-500/10 px-2 py-1 text-sm font-medium text-info-300">'.e($team).'</span>')
            ->implode('');

        return '<div>'
            .'<div class="flex flex-wrap gap-2">'.$items.'</div>'
            .'<p class="mt-2 text-sm text-gray-500 opacity-0 dark:text-gray-400" aria-hidden="true">Spacer line.</p>'
            .'</div>';
    }

    private static function permissionGroupHtml(): string
    {
        $group = auth()->user()?->permissionGroup;

        if ($group === null) {
            return '<div class="min-h-14"><p class="text-sm text-gray-500 dark:text-gray-400">You are not currently assigned to a user group.</p></div>';
        }

        $description = $group->description !== null && $group->description !== ''
            ? '<p class="mt-2 text-sm text-gray-500 dark:text-gray-400">'.e($group->description).'</p>'
            : '';

        return '<div class="min-h-14">'
            .'<span class="inline-flex rounded-md border border-warning-500/40 bg-warning-500/10 px-2 py-1 text-sm font-medium text-warning-300">'.e($group->name).'</span>'
            .$description
            .'</div>';
    }

    /**
     * @return array<string, string>
     */
    private static function projectListViewOptions(): array
    {
        $teams = auth()->user()?->teams()
            ->orderBy('name')
            ->pluck('name', 'teams.id')
            ->mapWithKeys(fn (string $name, int|string $id): array => [(string) $id => $name])
            ->all() ?? [];

        return ['all' => 'All available projects'] + $teams;
    }

    private static function normaliseProjectListTeamId(mixed $state): ?int
    {
        if ($state === 'all' || blank($state)) {
            return null;
        }

        $teamId = (int) $state;
        $isMember = auth()->user()?->teams()
            ->whereKey($teamId)
            ->exists() ?? false;

        return $isMember ? $teamId : null;
    }
}
