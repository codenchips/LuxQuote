<?php

namespace App\Filament\Widgets;

use App\Models\ActivityLog;
use App\Services\SalesforceService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;
use Throwable;

class CalendarWidget extends FullCalendarWidget
{
    protected static bool $isDiscovered = false;

    /** @var array<string, string> */
    public array $eventTypeOptions = [];

    /** @var array<string, bool> */
    public array $eventFieldUpdateability = [];

    /** @var array<string, bool> */
    public array $eventFieldCreateability = [];

    public bool $eventMetadataAvailable = false;

    public ?string $eventMetadataWarning = null;

    public bool $calendarLoadFailureNotified = false;

    /**
     * @param  array{start: string, end: string, timezone: string}  $info
     * @return array<int, array<string, mixed>>
     */
    public function fetchEvents(array $info): array
    {
        abort_unless(auth()->user()?->can('calendar.view'), 403);

        try {
            $calendarId = (string) config('services.salesforce.visits_calendar_id');
            $from = CarbonImmutable::parse($info['start'])->utc()->format('Y-m-d\TH:i:s\Z');
            $to = CarbonImmutable::parse($info['end'])->utc()->format('Y-m-d\TH:i:s\Z');
            $response = app(SalesforceService::class)->fetchCalendarBookings($calendarId, $from, $to, 200);
        } catch (Throwable $exception) {
            Log::error('Unexpected calendar event loading failure', [
                'exception' => $exception,
            ]);

            $response = ['success' => false];
        }

        if (! ($response['success'] ?? false)) {
            if (! $this->calendarLoadFailureNotified) {
                Notification::make()
                    ->danger()
                    ->title('Calendar events could not be loaded')
                    ->body('Salesforce did not allow the Visits calendar to be read. Check the integration user’s calendar and Event field permissions.')
                    ->send();
                $this->calendarLoadFailureNotified = true;
            }

            return [];
        }

        $this->calendarLoadFailureNotified = false;

        return collect($response['records'] ?? [])
            ->flatMap(fn (array $booking): array => $this->calendarEventsForBooking($booking))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function onEventClick(array $event): void
    {
        abort_unless(auth()->user()?->can('calendar.view'), 403);

        $currentType = trim((string) data_get($event, 'extendedProps.type'));

        if (auth()->user()?->can('calendar.update')) {
            $this->loadEventMetadata('update');
        }

        if (filled($currentType) && ! array_key_exists($currentType, $this->eventTypeOptions)) {
            $this->eventTypeOptions = [$currentType => $currentType, ...$this->eventTypeOptions];
        }

        parent::onEventClick($event);
    }

    /**
     * @param  array<string, mixed>|null  $view
     * @param  array<string, mixed>|null  $resource
     */
    public function onDateSelect(string $start, ?string $end, bool $allDay, ?array $view, ?array $resource): void
    {
        abort_unless(auth()->user()?->can('calendar.view'), 403);

        if (! (auth()->user()?->can('calendar.create') ?? false)) {
            return;
        }

        $this->loadEventMetadata('create');

        parent::onDateSelect($start, $end, $allDay, $view, $resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $showFullDays = (bool) config('calendar.show_full_days', false);
        $showWeekends = (bool) config('calendar.show_weekends', false);

        return [
            'initialView' => 'dayGridMonth',
            'selectable' => auth()->user()?->can('calendar.create') ?? false,
            'firstDay' => 1,
            'nowIndicator' => true,
            'dayMaxEvents' => 3,
            'allDaySlot' => true,
            'eventDisplay' => 'block',
            'displayEventEnd' => true,
            'expandRows' => true,
            'height' => 'auto',
            'slotMinTime' => $showFullDays ? '00:00:00' : '06:00:00',
            'slotMaxTime' => $showFullDays ? '24:00:00' : '20:00:00',
            'scrollTime' => $showFullDays ? '08:00:00' : '06:00:00',
            'views' => [
                'dayGridMonth' => [
                    'weekends' => $showWeekends,
                ],
            ],
            'headerToolbar' => [
                'left' => 'prev,next today',
                'center' => 'title',
                'right' => 'dayGridMonth,timeGridWeek,timeGridDay',
            ],
            'buttonText' => [
                'today' => 'Today',
                'month' => 'Month',
                'week' => 'Week',
                'day' => 'Day',
            ],
            'eventTimeFormat' => [
                'hour' => '2-digit',
                'minute' => '2-digit',
                'hour12' => false,
            ],
        ];
    }

    public function eventContent(): string
    {
        return <<<'JS'
            function({ event, timeText, view }) {
                const content = document.createElement('div')

                if (event.allDay) {
                    content.className = 'lux-calendar-event-all-day'
                    content.textContent = event.extendedProps.location
                        ? `${event.title} · ${event.extendedProps.location}`
                        : event.title

                    return { domNodes: [content] }
                }

                if (view.type === 'dayGridMonth') {
                    content.className = 'lux-calendar-event-month'
                    content.textContent = event.extendedProps.location
                        ? `${event.title} · ${event.extendedProps.location}`
                        : event.title

                    return { domNodes: [content] }
                }

                content.className = 'lux-calendar-event-details'

                if (timeText) {
                    const time = document.createElement('div')
                    time.className = 'lux-calendar-event-time'
                    time.textContent = timeText
                    content.appendChild(time)
                }

                const subject = document.createElement('div')
                subject.className = 'lux-calendar-event-subject'
                subject.textContent = event.title
                content.appendChild(subject)

                if (event.extendedProps.location) {
                    const location = document.createElement('div')
                    location.className = 'lux-calendar-event-location'
                    location.textContent = event.extendedProps.location
                    content.appendChild(location)
                }

                return { domNodes: [content] }
            }
            JS;
    }

    public function eventDidMount(): string
    {
        return <<<'JS'
            function({ event, el }) {
                const location = event.extendedProps.location
                el.setAttribute('title', location ? `${event.title} — ${location}` : event.title)
            }
            JS;
    }

    protected function headerActions(): array
    {
        return [];
    }

    protected function createAction(): Action
    {
        $canCreate = auth()->user()?->can('calendar.create') ?? false;
        $canSubmitCreate = $canCreate && $this->hasCreateableRequiredFields();

        return Action::make('create')
            ->label('Create event')
            ->authorize('calendar.create')
            ->modalHeading('Create event')
            ->modalDescription($this->eventModalDescription($canCreate, 'create'))
            ->schema($this->eventFormSchema($canCreate, creating: true))
            ->mountUsing(function (Schema $schema, array $arguments): void {
                $timezone = (string) config('app.timezone');
                $allDay = (bool) ($arguments['allDay'] ?? false);
                $startsAt = CarbonImmutable::parse((string) ($arguments['start'] ?? 'now'), $timezone)->setTimezone($timezone);
                $endsAt = filled($arguments['end'] ?? null)
                    ? CarbonImmutable::parse((string) $arguments['end'], $timezone)->setTimezone($timezone)
                    : ($allDay ? $startsAt : $startsAt->addHour());

                $schema->fill([
                    'subject' => '',
                    'location' => null,
                    'type' => null,
                    'start_date' => $startsAt->format('Y-m-d'),
                    'end_date' => $endsAt->format('Y-m-d'),
                    'start_time' => $allDay ? '08:00' : $startsAt->format('H:i'),
                    'end_time' => $allDay ? '17:00' : $endsAt->format('H:i'),
                    'all_day' => $allDay,
                ]);
            })
            ->action(function (Action $action, array $data): void {
                abort_unless(auth()->user()?->can('calendar.create'), 403);

                $payload = $this->eventCreatePayload($data);

                try {
                    $result = app(SalesforceService::class)->createCalendarBooking(
                        (string) config('services.salesforce.visits_calendar_id'),
                        $payload,
                    );
                } catch (Throwable $exception) {
                    Log::error('Unexpected calendar event creation failure', [
                        'exception' => $exception,
                    ]);

                    $result = [
                        'success' => false,
                        'message' => 'Salesforce could not be reached. The event has not been created.',
                    ];
                }

                if (! ($result['success'] ?? false)) {
                    Notification::make()
                        ->danger()
                        ->title('Event could not be created')
                        ->body((string) ($result['message'] ?? 'Salesforce rejected the event. It has not been created.'))
                        ->send();

                    $action->halt();
                }

                $this->recordCalendarActivity('created', [
                    'event_id' => (string) ($result['eventId'] ?? ''),
                    ...$this->calendarActivityDetailsFromForm($data),
                ]);

                Notification::make()
                    ->success()
                    ->title('Event created')
                    ->send();

                $this->refreshRecords();
            })
            ->modalSubmitAction($canSubmitCreate ? null : false)
            ->modalSubmitActionLabel('Create event')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth(Width::TwoExtraLarge);
    }

    protected function viewAction(): Action
    {
        $canUpdate = auth()->user()?->can('calendar.update') ?? false;
        $canDelete = auth()->user()?->can('calendar.delete') ?? false;
        $canSubmitUpdate = $canUpdate && $this->hasEditableEventFields();

        return Action::make('view')
            ->modalHeading($canSubmitUpdate ? 'Edit event' : 'Event details')
            ->modalDescription($this->eventModalDescription($canUpdate))
            ->schema($this->eventFormSchema($canUpdate))
            ->mountUsing(function (Schema $schema, array $arguments): void {
                $event = (array) ($arguments['event'] ?? []);
                $details = (array) ($event['extendedProps'] ?? []);

                $schema->fill([
                    'subject' => trim((string) ($event['title'] ?? '')) ?: 'Room booking',
                    'location' => filled($details['location'] ?? null) ? trim((string) $details['location']) : null,
                    'type' => filled($details['type'] ?? null) ? (string) $details['type'] : null,
                    'start_date' => (string) ($details['startDate'] ?? ''),
                    'end_date' => (string) ($details['endDate'] ?? ''),
                    'start_time' => (string) ($details['startTime'] ?? '08:00'),
                    'end_time' => (string) ($details['endTime'] ?? '17:00'),
                    'all_day' => (bool) ($details['allDay'] ?? false),
                    'owner' => trim((string) ($details['owner'] ?? '')) ?: 'Not available',
                    'created_by' => trim((string) ($details['createdBy'] ?? '')) ?: 'Not available',
                ]);
            })
            ->action(function (Action $action, array $arguments, array $data): void {
                abort_unless(auth()->user()?->can('calendar.update'), 403);

                $eventId = (string) data_get($arguments, 'event.id');
                $event = (array) ($arguments['event'] ?? []);
                $payload = $this->eventUpdatePayload($data, $event);

                if ($payload === []) {
                    Notification::make()
                        ->info()
                        ->title('No event changes to save')
                        ->send();

                    return;
                }

                try {
                    $result = app(SalesforceService::class)->updateCalendarBooking(
                        (string) config('services.salesforce.visits_calendar_id'),
                        $eventId,
                        $payload,
                    );
                } catch (Throwable $exception) {
                    Log::error('Unexpected calendar event update failure', [
                        'event_id' => $eventId,
                        'exception' => $exception,
                    ]);

                    $result = [
                        'success' => false,
                        'message' => 'Salesforce could not be reached. The event has not been updated.',
                    ];
                }

                if (! ($result['success'] ?? false)) {
                    Notification::make()
                        ->danger()
                        ->title('Event could not be updated')
                        ->body((string) ($result['message'] ?? 'Salesforce rejected the update.'))
                        ->send();

                    $action->halt();
                }

                $this->recordCalendarActivity('updated', [
                    'event_id' => $eventId,
                    ...$this->calendarActivityDetailsFromForm($data, $event),
                ]);

                Notification::make()
                    ->success()
                    ->title('Event updated')
                    ->send();

                $this->refreshRecords();
            })
            ->modalSubmitAction($canSubmitUpdate ? null : false)
            ->modalSubmitActionLabel('Update event')
            ->modalCancelActionLabel($canUpdate ? 'Cancel' : 'Close')
            ->extraModalFooterActions($canDelete ? [
                Action::make('removeEvent')
                    ->label('Remove event')
                    ->color('danger')
                    ->icon('heroicon-o-trash')
                    ->authorize('calendar.delete')
                    ->requiresConfirmation()
                    ->modalHeading('Remove event?')
                    ->modalDescription('Are you sure? This will permanently remove the event from the Salesforce Visits calendar.')
                    ->modalSubmitActionLabel('Remove event')
                    ->action(function (Action $action): void {
                        abort_unless(auth()->user()?->can('calendar.delete'), 403);

                        $parentAction = collect($this->mountedActions)
                            ->first(fn (array $mountedAction): bool => ($mountedAction['name'] ?? null) === 'view');
                        $eventId = trim((string) data_get($parentAction, 'arguments.event.id'));
                        $event = (array) data_get($parentAction, 'arguments.event', []);

                        if ($eventId === '') {
                            Notification::make()
                                ->danger()
                                ->title('Event could not be removed')
                                ->body('The selected Salesforce event could not be identified. Close the dialog and try again.')
                                ->send();

                            $action->halt();
                        }

                        try {
                            $result = app(SalesforceService::class)->deleteCalendarBooking(
                                (string) config('services.salesforce.visits_calendar_id'),
                                $eventId,
                            );
                        } catch (Throwable $exception) {
                            Log::error('Unexpected calendar event deletion failure', [
                                'event_id' => $eventId,
                                'exception' => $exception,
                            ]);

                            $result = [
                                'success' => false,
                                'message' => 'Salesforce could not be reached. The event has not been removed.',
                            ];
                        }

                        if (! ($result['success'] ?? false)) {
                            Notification::make()
                                ->danger()
                                ->title('Event could not be removed')
                                ->body((string) ($result['message'] ?? 'Salesforce rejected the deletion. The event has not been removed.'))
                                ->send();

                            $action->halt();
                        }

                        $this->recordCalendarActivity('deleted', [
                            'event_id' => $eventId,
                            ...$this->calendarActivityDetailsFromEvent($event),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Event removed')
                            ->send();

                        $this->refreshRecords();
                    })
                    ->cancelParentActions(),
            ] : [])
            ->modalWidth(Width::TwoExtraLarge);
    }

    /**
     * @return array<int, mixed>
     */
    private function eventFormSchema(bool $canWrite, bool $creating = false): array
    {
        $canWriteField = fn (string $field): bool => $creating
            ? $this->canCreateSalesforceField($field, $canWrite)
            : $this->canEditSalesforceField($field, $canWrite);
        $canEditSubject = $canWriteField('Subject');
        $canEditLocation = $canWriteField('Location');
        $canEditType = $canWriteField('Type');
        $canEditSchedule = $canWriteField('StartDateTime') && $canWriteField('EndDateTime');
        $canEditAllDay = $canEditSchedule && $canWriteField('IsAllDayEvent');
        $gridFields = [
            TextInput::make('location')
                ->label('Location')
                ->placeholder('Not specified')
                ->maxLength(255)
                ->disabled(! $canEditLocation),
            Select::make('type')
                ->label('Type')
                ->options(fn (): array => $this->eventTypeOptions)
                ->placeholder('Not specified')
                ->disabled(! $canEditType),
            DatePicker::make('start_date')
                ->label('Start date')
                ->displayFormat('d/m/Y')
                ->native(false)
                ->required()
                ->disabled(! $canEditSchedule),
            DatePicker::make('end_date')
                ->label('End date')
                ->displayFormat('d/m/Y')
                ->native(false)
                ->required()
                ->afterOrEqual('start_date')
                ->disabled(! $canEditSchedule),
            Select::make('start_time')
                ->label('Start time')
                ->options(fn (Get $get): array => $this->timeOptions((string) $get('start_time')))
                ->required(fn (Get $get): bool => ! $get('all_day'))
                ->disabled(fn (Get $get): bool => (! $canEditSchedule) || $get('all_day')),
            Select::make('end_time')
                ->label('End time')
                ->options(fn (Get $get): array => $this->timeOptions((string) $get('end_time')))
                ->required(fn (Get $get): bool => ! $get('all_day'))
                ->disabled(fn (Get $get): bool => (! $canEditSchedule) || $get('all_day')),
            Checkbox::make('all_day')
                ->label('All-day event')
                ->live()
                ->disabled(! $canEditAllDay)
                ->columnSpanFull(),
        ];

        if (! $creating) {
            $gridFields[] = TextInput::make('owner')
                ->label('Owner')
                ->disabled()
                ->dehydrated(false);
            $gridFields[] = TextInput::make('created_by')
                ->label('Created By')
                ->disabled()
                ->dehydrated(false);
        }

        return [
            TextInput::make('subject')
                ->label('Subject')
                ->required()
                ->maxLength(255)
                ->disabled(! $canEditSubject)
                ->columnSpanFull(),
            Grid::make(2)
                ->schema($gridFields),
        ];
    }

    private function canEditSalesforceField(string $field, bool $canUpdate): bool
    {
        if (! $canUpdate) {
            return false;
        }

        if ($this->eventMetadataAvailable) {
            return $this->eventFieldUpdateability[$field] ?? false;
        }

        return $field !== 'Type' || count($this->eventTypeOptions) > 1;
    }

    private function canCreateSalesforceField(string $field, bool $canCreate): bool
    {
        if (! $canCreate) {
            return false;
        }

        if ($this->eventMetadataAvailable) {
            return $this->eventFieldCreateability[$field] ?? false;
        }

        return $field !== 'Type' || count($this->eventTypeOptions) > 1;
    }

    private function hasCreateableRequiredFields(): bool
    {
        foreach (['Subject', 'StartDateTime', 'EndDateTime', 'IsAllDayEvent', 'OwnerId'] as $field) {
            if (! $this->canCreateSalesforceField($field, true)) {
                return false;
            }
        }

        return true;
    }

    private function hasEditableEventFields(): bool
    {
        foreach (['Subject', 'Location', 'Type', 'StartDateTime', 'EndDateTime', 'IsAllDayEvent'] as $field) {
            if ($this->canEditSalesforceField($field, true)) {
                return true;
            }
        }

        return false;
    }

    private function eventModalDescription(bool $canWrite, string $operation = 'update'): ?string
    {
        if (! $canWrite) {
            return null;
        }

        if (filled($this->eventMetadataWarning)) {
            return $this->eventMetadataWarning;
        }

        if ($operation === 'create' && ! $this->hasCreateableRequiredFields()) {
            return 'Salesforce does not permit the integration user to create events with the required fields.';
        }

        if ($this->eventMetadataAvailable) {
            $fieldPermissions = $operation === 'create' ? $this->eventFieldCreateability : $this->eventFieldUpdateability;

            foreach (['Subject', 'Location', 'Type', 'StartDateTime', 'EndDateTime', 'IsAllDayEvent'] as $field) {
                if (! ($fieldPermissions[$field] ?? false)) {
                    return "Some fields are read-only because the Salesforce integration user cannot {$operation} them.";
                }
            }
        }

        return null;
    }

    private function loadEventMetadata(string $operation): void
    {
        $this->eventTypeOptions = [];
        $this->eventFieldUpdateability = [];
        $this->eventFieldCreateability = [];
        $this->eventMetadataAvailable = false;
        $this->eventMetadataWarning = null;

        try {
            $response = app(SalesforceService::class)->fetchEventTypeOptions();
        } catch (Throwable $exception) {
            Log::warning('Calendar event metadata could not be loaded.', [
                'operation' => $operation,
                'exception' => $exception,
            ]);

            $response = ['success' => false, 'options' => []];
        }
        $this->eventTypeOptions = (array) ($response['options'] ?? []);
        $permissionKey = $operation === 'create' ? 'createable' : 'updateable';

        if (($response['success'] ?? false) && array_key_exists($permissionKey, $response)) {
            $this->eventMetadataAvailable = true;
            $this->eventFieldUpdateability = (array) ($response['updateable'] ?? []);
            $this->eventFieldCreateability = (array) ($response['createable'] ?? []);

            return;
        }

        $this->eventMetadataWarning = $operation === 'create'
            ? 'Salesforce field permissions could not be checked. Type selection is unavailable, but the event can still be submitted safely.'
            : 'Salesforce field permissions could not be checked. Type changes are unavailable, but other edits can still be attempted safely.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function eventCreatePayload(array $data): array
    {
        $payload = [
            'Subject' => trim((string) ($data['subject'] ?? '')),
        ];

        if (array_key_exists('location', $data) && filled($data['location'])) {
            $payload['Location'] = trim((string) $data['location']);
        }

        if (array_key_exists('type', $data) && filled($data['type'])) {
            $payload['Type'] = (string) $data['type'];
        }

        $startDate = (string) ($data['start_date'] ?? '');
        $endDate = (string) ($data['end_date'] ?? '');
        $isAllDay = (bool) ($data['all_day'] ?? false);

        if ($isAllDay) {
            $payload['StartDateTime'] = $startDate.'T00:00:00Z';
            $payload['EndDateTime'] = $endDate.'T00:00:00Z';
        } else {
            $timezone = (string) config('app.timezone');
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $startDate.' '.(string) ($data['start_time'] ?? ''), $timezone);
            $endsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $endDate.' '.(string) ($data['end_time'] ?? ''), $timezone);

            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                throw ValidationException::withMessages([
                    'end_time' => 'The event must end after it starts.',
                ]);
            }

            $payload['StartDateTime'] = $startsAt->utc()->format('Y-m-d\TH:i:s\Z');
            $payload['EndDateTime'] = $endsAt->utc()->format('Y-m-d\TH:i:s\Z');
        }

        $payload['IsAllDayEvent'] = $isAllDay;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $event
     * @return array{subject: string, dates: string, times: string}
     */
    private function calendarActivityDetailsFromForm(array $data, array $event = []): array
    {
        $details = (array) ($event['extendedProps'] ?? []);
        $subject = trim((string) ($data['subject'] ?? $event['title'] ?? '')) ?: 'Room booking';
        $startDate = (string) ($data['start_date'] ?? $details['startDate'] ?? '');
        $endDate = (string) ($data['end_date'] ?? $details['endDate'] ?? $startDate);
        $allDay = (bool) ($data['all_day'] ?? $details['allDay'] ?? false);
        $timezone = (string) config('app.timezone');
        $startsOn = CarbonImmutable::parse($startDate, $timezone)->startOfDay();
        $endsOn = CarbonImmutable::parse($endDate, $timezone)->startOfDay();
        $startTime = (string) ($data['start_time'] ?? $details['startTime'] ?? '08:00');
        $endTime = (string) ($data['end_time'] ?? $details['endTime'] ?? '17:00');

        return [
            'subject' => $subject,
            'dates' => $this->formatDateRange($startsOn, $endsOn),
            'times' => $allDay ? 'All day' : "{$startTime} – {$endTime}",
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{subject: string, dates: string, times: string}
     */
    private function calendarActivityDetailsFromEvent(array $event): array
    {
        $details = (array) ($event['extendedProps'] ?? []);

        if (filled($details['dates'] ?? null) && filled($details['times'] ?? null)) {
            return [
                'subject' => trim((string) ($event['title'] ?? '')) ?: 'Room booking',
                'dates' => (string) $details['dates'],
                'times' => (string) $details['times'],
            ];
        }

        return $this->calendarActivityDetailsFromForm([], $event);
    }

    /**
     * @param  array{event_id: string, subject: string, dates: string, times: string}  $event
     */
    private function recordCalendarActivity(string $action, array $event): void
    {
        try {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => null,
                'action_type' => "calendar.{$action}",
                'user_email_snapshot' => auth()->user()?->email ?? '',
                'project_name_snapshot' => null,
                'payload' => [
                    'calendar_id' => (string) config('services.salesforce.visits_calendar_id'),
                    'calendar_name' => (string) config('services.salesforce.visits_calendar_name', 'Visits'),
                    ...$event,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::error('Calendar activity could not be recorded', [
                'action' => $action,
                'event_id' => $event['event_id'],
                'exception' => $exception,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function eventUpdatePayload(array $data, array $event): array
    {
        $details = (array) ($event['extendedProps'] ?? []);
        $payload = [];

        if (array_key_exists('subject', $data)) {
            $subject = trim((string) $data['subject']);

            if ($subject !== trim((string) ($event['title'] ?? ''))) {
                $payload['Subject'] = $subject;
            }
        }

        if (array_key_exists('location', $data)) {
            $location = filled($data['location']) ? trim((string) $data['location']) : null;
            $originalLocation = filled($details['location'] ?? null) ? trim((string) $details['location']) : null;

            if ($location !== $originalLocation) {
                $payload['Location'] = $location;
            }
        }

        if (array_key_exists('type', $data)) {
            $type = filled($data['type']) ? (string) $data['type'] : null;
            $originalType = filled($details['type'] ?? null) ? (string) $details['type'] : null;

            if ($type !== $originalType) {
                $payload['Type'] = $type;
            }
        }

        $startDate = (string) ($data['start_date'] ?? $details['startDate'] ?? '');
        $endDate = (string) ($data['end_date'] ?? $details['endDate'] ?? '');
        $startTime = (string) ($data['start_time'] ?? $details['startTime'] ?? '08:00');
        $endTime = (string) ($data['end_time'] ?? $details['endTime'] ?? '17:00');
        $isAllDay = (bool) ($data['all_day'] ?? $details['allDay'] ?? false);
        $scheduleChanged = $startDate !== (string) ($details['startDate'] ?? '')
            || $endDate !== (string) ($details['endDate'] ?? '')
            || $startTime !== (string) ($details['startTime'] ?? '08:00')
            || $endTime !== (string) ($details['endTime'] ?? '17:00')
            || $isAllDay !== (bool) ($details['allDay'] ?? false);

        if (! $scheduleChanged) {
            return $payload;
        }

        if ($isAllDay) {
            $payload['StartDateTime'] = $startDate.'T00:00:00Z';
            $payload['EndDateTime'] = $endDate.'T00:00:00Z';
        } else {
            $timezone = (string) config('app.timezone');
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $startDate.' '.$startTime, $timezone);
            $endsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $endDate.' '.$endTime, $timezone);

            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                throw ValidationException::withMessages([
                    'end_time' => 'The event must end after it starts.',
                ]);
            }

            $payload['StartDateTime'] = $startsAt->utc()->format('Y-m-d\TH:i:s\Z');
            $payload['EndDateTime'] = $endsAt->utc()->format('Y-m-d\TH:i:s\Z');
        }

        $payload['IsAllDayEvent'] = $isAllDay;

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function timeOptions(?string $currentTime = null): array
    {
        $options = collect(range(0, 95))
            ->mapWithKeys(function (int $slot): array {
                $minutes = $slot * 15;
                $time = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);

                return [$time => $time];
            })
            ->all();

        if (filled($currentTime) && ! array_key_exists($currentTime, $options)) {
            $options[$currentTime] = $currentTime;
            ksort($options);
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $booking
     * @return array<int, array<string, mixed>>
     */
    private function calendarEventsForBooking(array $booking): array
    {
        if (blank($booking['Id'] ?? null) || blank($booking['StartDateTime'] ?? null) || blank($booking['EndDateTime'] ?? null)) {
            Log::warning('Skipping malformed Salesforce calendar booking.', [
                'event_id' => $booking['Id'] ?? null,
            ]);

            return [];
        }

        try {
            return $this->mapCalendarEventsForBooking($booking);
        } catch (Throwable $exception) {
            Log::warning('Skipping Salesforce calendar booking with invalid dates.', [
                'event_id' => $booking['Id'] ?? null,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $booking
     * @return array<int, array<string, mixed>>
     */
    private function mapCalendarEventsForBooking(array $booking): array
    {
        $id = (string) ($booking['Id'] ?? '');
        $subject = trim((string) ($booking['Subject'] ?? '')) ?: 'Room booking';
        $location = filled($booking['Location'] ?? null) ? (string) $booking['Location'] : null;
        $owner = filled(data_get($booking, 'Owner.Name')) ? (string) data_get($booking, 'Owner.Name') : null;
        $eventType = filled($booking['Type'] ?? null) ? (string) $booking['Type'] : null;
        $createdBy = filled(data_get($booking, 'CreatedBy.Name')) ? (string) data_get($booking, 'CreatedBy.Name') : null;
        $timezone = (string) config('app.timezone');

        if (! ($booking['IsAllDayEvent'] ?? false)) {
            $startsAt = CarbonImmutable::parse((string) $booking['StartDateTime'])->setTimezone($timezone);
            $endsAt = CarbonImmutable::parse((string) $booking['EndDateTime'])->setTimezone($timezone);

            return [[
                ...$this->eventBase($id, $subject, [
                    'location' => $location,
                    'dates' => $this->formatDateRange($startsAt, $endsAt),
                    'times' => $startsAt->format('H:i').' – '.$endsAt->format('H:i'),
                    'owner' => $owner,
                    'type' => $eventType,
                    'createdBy' => $createdBy,
                    'startDate' => $startsAt->format('Y-m-d'),
                    'endDate' => $endsAt->format('Y-m-d'),
                    'startTime' => $startsAt->format('H:i'),
                    'endTime' => $endsAt->format('H:i'),
                    'allDay' => false,
                ]),
                'start' => $startsAt->toIso8601String(),
                'end' => $endsAt->toIso8601String(),
            ]];
        }

        $firstDay = CarbonImmutable::parse(substr((string) $booking['StartDateTime'], 0, 10), $timezone)->startOfDay();
        $lastDay = CarbonImmutable::parse(substr((string) $booking['EndDateTime'], 0, 10), $timezone)->startOfDay();

        return [[
            ...$this->eventBase($id, $subject, [
                'location' => $location,
                'dates' => $this->formatDateRange($firstDay, $lastDay),
                'times' => 'All day',
                'owner' => $owner,
                'type' => $eventType,
                'createdBy' => $createdBy,
                'startDate' => $firstDay->format('Y-m-d'),
                'endDate' => $lastDay->format('Y-m-d'),
                'startTime' => '08:00',
                'endTime' => '17:00',
                'allDay' => true,
            ]),
            'allDay' => true,
            'start' => $firstDay->format('Y-m-d'),
            'end' => $lastDay->addDay()->format('Y-m-d'),
        ]];
    }

    /**
     * @param  array<string, mixed>  $extendedProps
     * @return array<string, mixed>
     */
    private function eventBase(string $id, string $subject, array $extendedProps): array
    {
        return [
            'id' => $id,
            'title' => $subject,
            'allDay' => false,
            'display' => 'block',
            'classNames' => ['lux-calendar-booking'],
            'extendedProps' => $extendedProps,
        ];
    }

    private function formatDateRange(CarbonImmutable $startsAt, CarbonImmutable $endsAt): string
    {
        $startDate = $startsAt->format('D j M Y');

        if ($startsAt->isSameDay($endsAt)) {
            return $startDate;
        }

        return $startDate.' – '.$endsAt->format('D j M Y');
    }
}
