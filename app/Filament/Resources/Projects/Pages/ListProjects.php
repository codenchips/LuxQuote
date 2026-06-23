<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Project')
                ->visible(fn (): bool => auth()->user()?->can('projects.create') ?? false)
                ->slideOver()
                ->createAnother(false)
                ->modalSubmitAction(fn (Action $action): Action => $action
                    ->disabled(false)
                    ->extraAttributes([
                        'x-bind:class' => '($wire.mountedActions?.[0]?.data?.name && $wire.mountedActions?.[0]?.data?.customer_name && $wire.mountedActions?.[0]?.data?.reference_number) ? \'\' : \'opacity-60 cursor-not-allowed\'',
                        'x-bind:disabled' => '!($wire.mountedActions?.[0]?.data?.name && $wire.mountedActions?.[0]?.data?.customer_name && $wire.mountedActions?.[0]?.data?.reference_number)',
                    ]))
                ->mutateFormDataUsing(function (array $data): array {
                    $data['user_id'] = auth()->id();

                    if (! empty($data['salesforce_project']) && ! empty($data['salesforce_pending_data'])) {
                        $sfData = json_decode((string) $data['salesforce_pending_data'], true);

                        if (is_array($sfData) && ! empty($sfData['Name'])) {
                            $data['name'] = ProjectForm::titleCaseProjectName($sfData['Name']);
                            $data['salesforce_id'] = $sfData['Id'] ?? $data['salesforce_id'] ?? null;
                            $data['cover_percentage'] = $sfData['CEF_Cover__c'] ?? $data['cover_percentage'] ?? null;
                            $data['value'] = $sfData['Amount'] ?? $data['value'] ?? null;
                        }
                    }

                    return $data;
                })
                ->successRedirectUrl(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record])),
        ];
    }

    protected function onValidationError(ValidationException $exception): void
    {
        parent::onValidationError($exception);

        $messages = collect($exception->errors())
            ->flatten()
            ->filter(fn (mixed $message): bool => is_string($message));

        if ($messages->isEmpty()) {
            return;
        }

        $isDuplicateProject = $messages->contains(
            fn (string $message): bool => str_contains($message, 'already exists'),
        );

        Notification::make()
            ->danger()
            ->title($isDuplicateProject ? 'Project already exists' : 'Project could not be created')
            ->body((string) $messages->first())
            ->send();
    }
}
