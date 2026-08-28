<?php

namespace Tests\Feature;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Widgets\CalendarWidget;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\User;
use App\Services\SalesforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SalesforceCalendarWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_view_displays_labels_for_up_to_three_events_per_day(): void
    {
        $widget = app(CalendarWidget::class);

        $this->assertSame(3, $widget->config()['dayMaxEvents']);
        $this->assertStringContainsString("view.type === 'dayGridMonth'", $widget->eventContent());
        $this->assertStringContainsString('`${event.title} · ${event.extendedProps.location}`', $widget->eventContent());
    }

    public function test_time_grid_is_limited_to_business_visit_hours_by_default(): void
    {
        config(['calendar.show_full_days' => false]);

        $calendarConfig = app(CalendarWidget::class)->config();

        $this->assertSame('06:00:00', $calendarConfig['slotMinTime']);
        $this->assertSame('20:00:00', $calendarConfig['slotMaxTime']);
        $this->assertSame('06:00:00', $calendarConfig['scrollTime']);
    }

    public function test_time_grid_can_show_full_days_from_configuration(): void
    {
        config(['calendar.show_full_days' => true]);

        $calendarConfig = app(CalendarWidget::class)->config();

        $this->assertSame('00:00:00', $calendarConfig['slotMinTime']);
        $this->assertSame('24:00:00', $calendarConfig['slotMaxTime']);
        $this->assertSame('08:00:00', $calendarConfig['scrollTime']);
    }

    public function test_month_view_hides_weekends_by_default(): void
    {
        config(['calendar.show_weekends' => false]);

        $calendarConfig = app(CalendarWidget::class)->config();

        $this->assertFalse($calendarConfig['views']['dayGridMonth']['weekends']);
        $this->assertArrayNotHasKey('weekends', $calendarConfig);
    }

    public function test_month_view_can_show_weekends_from_configuration(): void
    {
        config(['calendar.show_weekends' => true]);

        $calendarConfig = app(CalendarWidget::class)->config();

        $this->assertTrue($calendarConfig['views']['dayGridMonth']['weekends']);
    }

    public function test_widget_maps_timed_and_all_day_salesforce_bookings(): void
    {
        $fake = new class extends SalesforceService
        {
            /** @var array<string, mixed> */
            public array $arguments = [];

            public function fetchCalendarBookings(
                string $calendarId,
                string $from,
                ?string $to = null,
                int $limit = 100,
            ): array {
                $this->arguments = compact('calendarId', 'from', 'to', 'limit');

                return [
                    'success' => true,
                    'records' => [
                        [
                            'Id' => '00USq00000a2fX2MAI',
                            'Subject' => 'EO - Technical training Group B',
                            'StartDateTime' => '2026-01-19T08:30:00.000+0000',
                            'EndDateTime' => '2026-01-19T12:00:00.000+0000',
                            'IsAllDayEvent' => false,
                            'Location' => 'Boardroom',
                            'Owner' => ['Name' => 'Visits'],
                            'Type' => 'Meeting',
                            'CreatedBy' => ['Name' => 'Ian Neill'],
                        ],
                        [
                            'Id' => '00USq00000ALLDAYAA',
                            'Subject' => 'Two day visit',
                            'StartDateTime' => '2026-01-20T00:00:00.000+0000',
                            'EndDateTime' => '2026-01-21T00:00:00.000+0000',
                            'IsAllDayEvent' => true,
                            'Location' => 'Showroom',
                            'Owner' => ['Name' => 'Visits'],
                            'Type' => 'Training',
                            'CreatedBy' => ['Name' => 'Dean Pugh'],
                        ],
                    ],
                ];
            }
        };

        config(['services.salesforce.visits_calendar_id' => '023J7000000YLWi']);
        $this->app->instance(SalesforceService::class, $fake);
        $this->actingAs(User::factory()->create());

        $events = app(CalendarWidget::class)->fetchEvents([
            'start' => '2026-01-01T00:00:00+00:00',
            'end' => '2026-02-01T00:00:00+00:00',
            'timezone' => 'Europe/London',
        ]);

        $this->assertSame([
            'calendarId' => '023J7000000YLWi',
            'from' => '2026-01-01T00:00:00Z',
            'to' => '2026-02-01T00:00:00Z',
            'limit' => 200,
        ], $fake->arguments);
        $this->assertCount(2, $events);
        $this->assertSame('EO - Technical training Group B', $events[0]['title']);
        $this->assertSame('Boardroom', $events[0]['extendedProps']['location']);
        $this->assertSame('Visits', $events[0]['extendedProps']['owner']);
        $this->assertSame('Meeting', $events[0]['extendedProps']['type']);
        $this->assertSame('Ian Neill', $events[0]['extendedProps']['createdBy']);
        $this->assertSame('Mon 19 Jan 2026', $events[0]['extendedProps']['dates']);
        $this->assertSame('08:30 – 12:00', $events[0]['extendedProps']['times']);
        $this->assertSame('2026-01-19', $events[0]['extendedProps']['startDate']);
        $this->assertSame('2026-01-19', $events[0]['extendedProps']['endDate']);
        $this->assertSame('08:30', $events[0]['extendedProps']['startTime']);
        $this->assertSame('12:00', $events[0]['extendedProps']['endTime']);
        $this->assertFalse($events[0]['extendedProps']['allDay']);
        $this->assertSame('2026-01-19T08:30:00+00:00', $events[0]['start']);
        $this->assertSame('2026-01-19T12:00:00+00:00', $events[0]['end']);
        $this->assertFalse($events[0]['allDay']);
        $this->assertTrue($events[1]['allDay']);
        $this->assertSame('2026-01-20', $events[1]['start']);
        $this->assertSame('2026-01-22', $events[1]['end']);
        $this->assertSame('Tue 20 Jan 2026 – Wed 21 Jan 2026', $events[1]['extendedProps']['dates']);
        $this->assertSame('All day', $events[1]['extendedProps']['times']);
        $this->assertSame('2026-01-20', $events[1]['extendedProps']['startDate']);
        $this->assertSame('2026-01-21', $events[1]['extendedProps']['endDate']);
        $this->assertSame('08:00', $events[1]['extendedProps']['startTime']);
        $this->assertSame('17:00', $events[1]['extendedProps']['endTime']);
        $this->assertTrue($events[1]['extendedProps']['allDay']);
    }

    public function test_user_can_update_event_from_modal(): void
    {
        $fake = new class extends SalesforceService
        {
            /** @var array<string, mixed> */
            public array $updateArguments = [];

            public function fetchEventTypeOptions(): array
            {
                return [
                    'success' => true,
                    'options' => [
                        'Meeting' => 'Meeting',
                        'Other' => 'Other',
                    ],
                ];
            }

            public function updateCalendarBooking(string $calendarId, string $eventId, array $attributes): array
            {
                $this->updateArguments = compact('calendarId', 'eventId', 'attributes');

                return ['success' => true, 'eventId' => $eventId];
            }
        };

        config(['services.salesforce.visits_calendar_id' => '023J7000000YLWi']);
        $this->app->instance(SalesforceService::class, $fake);
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(CalendarWidget::class)
            ->call('onEventClick', [
                'id' => '00USq00000a2fX2MAI',
                'title' => 'EO - Technical training Group B',
                'extendedProps' => [
                    'location' => 'Boardroom',
                    'dates' => 'Mon 19 Jan 2026',
                    'times' => '08:30 – 12:00',
                    'owner' => 'Visits',
                    'type' => 'Meeting',
                    'createdBy' => 'Ian Neill',
                    'startDate' => '2026-01-19',
                    'endDate' => '2026-01-19',
                    'startTime' => '08:30',
                    'endTime' => '12:00',
                    'allDay' => false,
                ],
            ])
            ->assertActionMounted('view')
            ->assertFormFieldDisabled('owner')
            ->assertFormFieldDisabled('created_by')
            ->assertSchemaStateSet([
                'subject' => 'EO - Technical training Group B',
                'location' => 'Boardroom',
                'type' => 'Meeting',
                'start_date' => '2026-01-19',
                'end_date' => '2026-01-19',
                'start_time' => '08:30',
                'end_time' => '12:00',
                'all_day' => false,
                'owner' => 'Visits',
                'created_by' => 'Ian Neill',
            ]);

        $action = $component->instance()->getMountedAction();

        $this->assertSame('Edit event', $action?->getModalHeading());

        $component
            ->fillForm([
                'subject' => 'Updated technical training',
                'location' => 'Showroom',
                'type' => 'Other',
                'start_date' => '2026-01-20',
                'end_date' => '2026-01-20',
                'start_time' => '09:15',
                'end_time' => '11:45',
                'all_day' => false,
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame([
            'calendarId' => '023J7000000YLWi',
            'eventId' => '00USq00000a2fX2MAI',
            'attributes' => [
                'Subject' => 'Updated technical training',
                'Location' => 'Showroom',
                'Type' => 'Other',
                'StartDateTime' => '2026-01-20T09:15:00Z',
                'EndDateTime' => '2026-01-20T11:45:00Z',
                'IsAllDayEvent' => false,
            ],
        ], $fake->updateArguments);

        $activity = ActivityLog::query()->where('action_type', 'calendar.updated')->sole();

        $this->assertEquals([
            'calendar_id' => '023J7000000YLWi',
            'calendar_name' => 'Visits',
            'event_id' => '00USq00000a2fX2MAI',
            'subject' => 'Updated technical training',
            'dates' => 'Tue 20 Jan 2026',
            'times' => '09:15 – 11:45',
        ], $activity->payload);
    }

    public function test_all_day_event_disables_time_selection(): void
    {
        $fake = new class extends SalesforceService
        {
            /** @var array<string, mixed> */
            public array $updateArguments = [];

            public function fetchEventTypeOptions(): array
            {
                return ['success' => true, 'options' => ['Training' => 'Training']];
            }

            public function updateCalendarBooking(string $calendarId, string $eventId, array $attributes): array
            {
                $this->updateArguments = compact('calendarId', 'eventId', 'attributes');

                return ['success' => true, 'eventId' => $eventId];
            }
        };

        config(['services.salesforce.visits_calendar_id' => '023J7000000YLWi']);
        $this->app->instance(SalesforceService::class, $fake);
        $this->actingAs(User::factory()->create());

        Livewire::test(CalendarWidget::class)
            ->call('onEventClick', [
                'id' => '00USq00000ALLDAYAA',
                'title' => 'Two day visit',
                'extendedProps' => [
                    'location' => 'Showroom',
                    'owner' => 'Visits',
                    'type' => 'Training',
                    'createdBy' => 'Dean Pugh',
                    'startDate' => '2026-01-20',
                    'endDate' => '2026-01-21',
                    'startTime' => '08:00',
                    'endTime' => '17:00',
                    'allDay' => true,
                ],
            ])
            ->assertActionMounted('view')
            ->assertFormFieldDisabled('start_time')
            ->assertFormFieldDisabled('end_time')
            ->fillForm([
                'subject' => 'Updated two day visit',
                'location' => 'Showroom',
                'type' => 'Training',
                'start_date' => '2026-01-21',
                'end_date' => '2026-01-22',
                'all_day' => true,
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame([
            'calendarId' => '023J7000000YLWi',
            'eventId' => '00USq00000ALLDAYAA',
            'attributes' => [
                'Subject' => 'Updated two day visit',
                'StartDateTime' => '2026-01-21T00:00:00Z',
                'EndDateTime' => '2026-01-22T00:00:00Z',
                'IsAllDayEvent' => true,
            ],
        ], $fake->updateArguments);
    }

    public function test_failed_event_update_is_not_recorded_as_completed_activity(): void
    {
        $this->app->instance(SalesforceService::class, new class extends SalesforceService
        {
            public function fetchEventTypeOptions(): array
            {
                return ['success' => false, 'options' => []];
            }

            public function updateCalendarBooking(string $calendarId, string $eventId, array $attributes): array
            {
                return [
                    'success' => false,
                    'message' => 'Salesforce does not permit the integration user to update this event.',
                ];
            }
        });
        $this->actingAs(User::factory()->create());

        Livewire::test(CalendarWidget::class)
            ->call('onEventClick', $this->calendarEvent())
            ->fillForm(['subject' => 'Update that will fail'])
            ->callMountedAction()
            ->assertActionMounted('view')
            ->assertNotified('Event could not be updated');

        $this->assertDatabaseMissing('activity_logs', ['action_type' => 'calendar.updated']);
    }

    public function test_salesforce_field_permissions_make_only_affected_fields_read_only(): void
    {
        $this->app->instance(SalesforceService::class, new class extends SalesforceService
        {
            public function fetchEventTypeOptions(): array
            {
                return [
                    'success' => true,
                    'options' => ['Meeting' => 'Meeting', 'Other' => 'Other'],
                    'updateable' => [
                        'Subject' => true,
                        'Location' => false,
                        'Type' => false,
                        'StartDateTime' => true,
                        'EndDateTime' => true,
                        'IsAllDayEvent' => false,
                    ],
                ];
            }
        });
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(CalendarWidget::class)
            ->call('onEventClick', [
                'id' => '00USq00000a2fX2MAI',
                'title' => 'Partially editable visit',
                'extendedProps' => [
                    'location' => 'Boardroom',
                    'type' => 'Meeting',
                    'startDate' => '2026-01-19',
                    'endDate' => '2026-01-19',
                    'startTime' => '08:30',
                    'endTime' => '12:00',
                    'allDay' => false,
                ],
            ])
            ->assertActionMounted('view')
            ->assertFormFieldEnabled('subject')
            ->assertFormFieldDisabled('location')
            ->assertFormFieldDisabled('type')
            ->assertFormFieldEnabled('start_date')
            ->assertFormFieldEnabled('end_date')
            ->assertFormFieldDisabled('all_day');

        $this->assertSame(
            'Some fields are read-only because the Salesforce integration user cannot update them.',
            (string) $component->instance()->getMountedAction()?->getModalDescription(),
        );
    }

    public function test_modal_uses_safe_fallback_when_salesforce_metadata_is_unavailable(): void
    {
        $this->app->instance(SalesforceService::class, new class extends SalesforceService
        {
            public function fetchEventTypeOptions(): array
            {
                return [
                    'success' => false,
                    'options' => [],
                    'updateable' => [],
                    'errors' => ['Describe access denied'],
                ];
            }
        });
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(CalendarWidget::class)
            ->call('onEventClick', [
                'id' => '00USq00000a2fX2MAI',
                'title' => 'Fallback visit',
                'extendedProps' => [
                    'type' => 'Meeting',
                    'startDate' => '2026-01-19',
                    'endDate' => '2026-01-19',
                    'startTime' => '08:30',
                    'endTime' => '12:00',
                    'allDay' => false,
                ],
            ])
            ->assertActionMounted('view')
            ->assertFormFieldEnabled('subject')
            ->assertFormFieldEnabled('location')
            ->assertFormFieldDisabled('type');

        $this->assertSame(
            'Salesforce field permissions could not be checked. Type changes are unavailable, but other edits can still be attempted safely.',
            (string) $component->instance()->getMountedAction()?->getModalDescription(),
        );
    }

    public function test_widget_notifies_user_when_calendar_cannot_be_read(): void
    {
        $this->app->instance(SalesforceService::class, new class extends SalesforceService
        {
            public function fetchCalendarBookings(
                string $calendarId,
                string $from,
                ?string $to = null,
                int $limit = 100,
            ): array {
                return [
                    'success' => false,
                    'records' => [],
                    'errors' => ['Calendar access denied'],
                ];
            }
        });
        $this->actingAs(User::factory()->create());

        Livewire::test(CalendarWidget::class)
            ->call('fetchEvents', [
                'start' => '2026-01-01T00:00:00+00:00',
                'end' => '2026-02-01T00:00:00+00:00',
                'timezone' => 'Europe/London',
            ])
            ->assertSet('calendarLoadFailureNotified', true)
            ->assertNotified('Calendar events could not be loaded');
    }

    public function test_widget_skips_malformed_salesforce_booking_without_losing_valid_events(): void
    {
        $this->app->instance(SalesforceService::class, new class extends SalesforceService
        {
            public function fetchCalendarBookings(
                string $calendarId,
                string $from,
                ?string $to = null,
                int $limit = 100,
            ): array {
                return [
                    'success' => true,
                    'records' => [
                        ['Id' => '00U000000000BADAAA', 'Subject' => 'Broken booking'],
                        [
                            'Id' => '00U000000000GOODAA',
                            'Subject' => 'Valid booking',
                            'StartDateTime' => '2026-01-19T08:30:00.000+0000',
                            'EndDateTime' => '2026-01-19T09:30:00.000+0000',
                            'IsAllDayEvent' => false,
                        ],
                    ],
                ];
            }
        });
        $this->actingAs(User::factory()->create());

        $events = app(CalendarWidget::class)->fetchEvents([
            'start' => '2026-01-01T00:00:00+00:00',
            'end' => '2026-02-01T00:00:00+00:00',
            'timezone' => 'Europe/London',
        ]);

        $this->assertCount(1, $events);
        $this->assertSame('Valid booking', $events[0]['title']);
    }

    public function test_user_can_create_timed_event_from_clicked_calendar_slot(): void
    {
        $fake = new class extends SalesforceService
        {
            /** @var array<string, mixed> */
            public array $createArguments = [];

            public function fetchEventTypeOptions(): array
            {
                return [
                    'success' => true,
                    'options' => ['Meeting' => 'Meeting', 'Training' => 'Training'],
                    'updateable' => [],
                    'createable' => [
                        'Subject' => true,
                        'Location' => true,
                        'Type' => true,
                        'StartDateTime' => true,
                        'EndDateTime' => true,
                        'IsAllDayEvent' => true,
                        'OwnerId' => true,
                    ],
                ];
            }

            public function createCalendarBooking(string $calendarId, array $attributes): array
            {
                $this->createArguments = compact('calendarId', 'attributes');

                return ['success' => true, 'eventId' => '00U000000000NEWAAA'];
            }
        };

        config(['services.salesforce.visits_calendar_id' => '023J7000000YLWi']);
        $this->app->instance(SalesforceService::class, $fake);
        $this->actingAs(User::factory()->create());

        Livewire::test(CalendarWidget::class)
            ->call('onDateSelect', '2026-09-04T09:15:00+01:00', null, false, ['type' => 'timeGridWeek'], null)
            ->assertActionMounted('create')
            ->assertSchemaStateSet([
                'subject' => '',
                'location' => null,
                'type' => null,
                'start_date' => '2026-09-04',
                'end_date' => '2026-09-04',
                'start_time' => '09:15',
                'end_time' => '10:15',
                'all_day' => false,
            ])
            ->fillForm([
                'subject' => 'Customer factory visit',
                'location' => 'Boardroom',
                'type' => 'Meeting',
                'start_date' => '2026-09-04',
                'end_date' => '2026-09-04',
                'start_time' => '09:15',
                'end_time' => '11:00',
                'all_day' => false,
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('Event created');

        $this->assertSame([
            'calendarId' => '023J7000000YLWi',
            'attributes' => [
                'Subject' => 'Customer factory visit',
                'Location' => 'Boardroom',
                'Type' => 'Meeting',
                'StartDateTime' => '2026-09-04T08:15:00Z',
                'EndDateTime' => '2026-09-04T10:00:00Z',
                'IsAllDayEvent' => false,
            ],
        ], $fake->createArguments);

        $activity = ActivityLog::query()->where('action_type', 'calendar.created')->sole();

        $this->assertEquals([
            'calendar_id' => '023J7000000YLWi',
            'calendar_name' => 'Visits',
            'event_id' => '00U000000000NEWAAA',
            'subject' => 'Customer factory visit',
            'dates' => 'Fri 4 Sep 2026',
            'times' => '09:15 – 11:00',
        ], $activity->payload);
    }

    public function test_all_day_slot_click_prefills_all_day_event(): void
    {
        $this->app->instance(SalesforceService::class, new class extends SalesforceService
        {
            public function fetchEventTypeOptions(): array
            {
                return [
                    'success' => true,
                    'options' => [],
                    'updateable' => [],
                    'createable' => array_fill_keys([
                        'Subject',
                        'Location',
                        'Type',
                        'StartDateTime',
                        'EndDateTime',
                        'IsAllDayEvent',
                        'OwnerId',
                    ], true),
                ];
            }
        });
        $this->actingAs(User::factory()->create());

        Livewire::test(CalendarWidget::class)
            ->call('onDateSelect', '2026-09-07', null, true, ['type' => 'dayGridMonth'], null)
            ->assertActionMounted('create')
            ->assertSchemaStateSet([
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '17:00',
                'all_day' => true,
            ])
            ->assertFormFieldDisabled('start_time')
            ->assertFormFieldDisabled('end_time');
    }

    public function test_create_event_failure_is_reported_and_modal_stays_open(): void
    {
        $this->app->instance(SalesforceService::class, new class extends SalesforceService
        {
            public function fetchEventTypeOptions(): array
            {
                return [
                    'success' => true,
                    'options' => [],
                    'updateable' => [],
                    'createable' => array_fill_keys([
                        'Subject',
                        'Location',
                        'Type',
                        'StartDateTime',
                        'EndDateTime',
                        'IsAllDayEvent',
                        'OwnerId',
                    ], true),
                ];
            }

            public function createCalendarBooking(string $calendarId, array $attributes): array
            {
                return [
                    'success' => false,
                    'message' => 'Salesforce does not permit the integration user to create events on this calendar.',
                ];
            }
        });
        $this->actingAs(User::factory()->create());

        Livewire::test(CalendarWidget::class)
            ->call('onDateSelect', '2026-09-04T09:00:00+01:00', null, false, ['type' => 'timeGridDay'], null)
            ->fillForm([
                'subject' => 'Blocked visit',
                'start_date' => '2026-09-04',
                'end_date' => '2026-09-04',
                'start_time' => '09:00',
                'end_time' => '10:00',
                'all_day' => false,
            ])
            ->callMountedAction()
            ->assertActionMounted('create')
            ->assertNotified('Event could not be created');

        $this->assertDatabaseMissing('activity_logs', ['action_type' => 'calendar.created']);
    }

    public function test_calendar_slot_selection_is_ignored_without_create_permission(): void
    {
        $group = PermissionGroup::query()->create([
            'name' => 'Calendar viewers without create',
            'slug' => 'calendar-viewers-without-create',
            'description' => 'Read-only calendar access.',
            'is_system' => false,
        ]);
        $group->permissions()->attach(Permission::query()->where('key', 'calendar.view')->firstOrFail());
        $this->actingAs(User::factory()->create(['permission_group_id' => $group->id]));

        $component = Livewire::test(CalendarWidget::class)
            ->call('onDateSelect', '2026-09-04T09:00:00+01:00', null, false, ['type' => 'timeGridDay'], null);

        $this->assertSame([], $component->instance()->mountedActions);
        $this->assertFalse($component->instance()->config()['selectable']);

        $component->mountAction('create');

        $this->assertSame([], $component->instance()->mountedActions);
    }

    public function test_user_can_remove_event_after_confirmation(): void
    {
        $fake = new class extends SalesforceService
        {
            /** @var array<string, string> */
            public array $deleteArguments = [];

            public function fetchEventTypeOptions(): array
            {
                return ['success' => true, 'options' => [], 'updateable' => []];
            }

            public function deleteCalendarBooking(string $calendarId, string $eventId): array
            {
                $this->deleteArguments = compact('calendarId', 'eventId');

                return ['success' => true, 'eventId' => $eventId];
            }
        };

        config(['services.salesforce.visits_calendar_id' => '023J7000000YLWi']);
        $this->app->instance(SalesforceService::class, $fake);
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(CalendarWidget::class)
            ->call('onEventClick', $this->calendarEvent())
            ->assertActionMounted('view')
            ->assertActionVisible('removeEvent')
            ->mountAction('removeEvent')
            ->assertActionMounted('removeEvent');

        $confirmationAction = $component->instance()->getMountedAction();

        $this->assertSame('Remove event?', $confirmationAction?->getModalHeading());
        $this->assertStringContainsString('Are you sure?', (string) $confirmationAction?->getModalDescription());

        $component
            ->callMountedAction()
            ->assertNotified('Event removed');

        $this->assertSame([
            'calendarId' => '023J7000000YLWi',
            'eventId' => '00USq00000a2fX2MAI',
        ], $fake->deleteArguments);

        $activity = ActivityLog::query()->where('action_type', 'calendar.deleted')->sole();

        $this->assertEquals([
            'calendar_id' => '023J7000000YLWi',
            'calendar_name' => 'Visits',
            'event_id' => '00USq00000a2fX2MAI',
            'subject' => 'EO - Technical training Group B',
            'dates' => 'Mon 19 Jan 2026',
            'times' => '08:30 – 12:00',
        ], $activity->payload);
    }

    public function test_remove_event_failure_is_reported_and_confirmation_stays_open(): void
    {
        $this->app->instance(SalesforceService::class, new class extends SalesforceService
        {
            public function fetchEventTypeOptions(): array
            {
                return ['success' => true, 'options' => [], 'updateable' => []];
            }

            public function deleteCalendarBooking(string $calendarId, string $eventId): array
            {
                return [
                    'success' => false,
                    'message' => 'Salesforce does not permit the integration user to remove this event. The event has not been removed.',
                ];
            }
        });
        $this->actingAs(User::factory()->create());

        Livewire::test(CalendarWidget::class)
            ->call('onEventClick', $this->calendarEvent())
            ->mountAction('removeEvent')
            ->callMountedAction()
            ->assertActionMounted('removeEvent')
            ->assertNotified('Event could not be removed');

        $this->assertDatabaseMissing('activity_logs', ['action_type' => 'calendar.deleted']);
    }

    public function test_calendar_activity_uses_requested_history_action_format(): void
    {
        $manager = User::factory()->manager()->create();
        $this->actingAs($manager);

        ActivityLog::create([
            'user_id' => $manager->id,
            'project_id' => null,
            'action_type' => 'calendar.created',
            'user_email_snapshot' => $manager->email,
            'project_name_snapshot' => null,
            'payload' => [
                'calendar_id' => '023J7000000YLWi',
                'calendar_name' => 'Visits',
                'event_id' => '00U000000000NEWAAA',
                'subject' => 'Customer factory visit',
                'dates' => 'Fri 4 Sep 2026',
                'times' => '09:15 – 11:00',
            ],
        ]);
        ActivityLog::create([
            'user_id' => $manager->id,
            'project_id' => null,
            'action_type' => 'calendar.updated',
            'user_email_snapshot' => $manager->email,
            'project_name_snapshot' => null,
            'payload' => [
                'calendar_id' => '023J7000000YLWi',
                'calendar_name' => 'Visits',
                'event_id' => '00U000000000UPDATED',
                'subject' => 'Updated customer visit',
                'dates' => 'Sat 5 Sep 2026',
                'times' => '10:00 – 12:00',
            ],
        ]);
        ActivityLog::create([
            'user_id' => $manager->id,
            'project_id' => null,
            'action_type' => 'calendar.deleted',
            'user_email_snapshot' => $manager->email,
            'project_name_snapshot' => null,
            'payload' => [
                'calendar_id' => '023J7000000YLWi',
                'calendar_name' => 'Visits',
                'event_id' => '00U000000000DELETED',
                'subject' => 'Cancelled customer visit',
                'dates' => 'Sun 6 Sep 2026',
                'times' => 'All day',
            ],
        ]);

        Livewire::test(ListActivityLogs::class)
            ->assertSeeText('Created - Customer factory visit - Fri 4 Sep 2026 / 09:15 – 11:00')
            ->assertSeeText('Updated - Updated customer visit - Sat 5 Sep 2026 / 10:00 – 12:00')
            ->assertSeeText('Deleted - Cancelled customer visit - Sun 6 Sep 2026 / All day')
            ->assertDontSeeText('Calendar - Created')
            ->assertDontSeeText('Calendar - Updated')
            ->assertDontSeeText('Calendar - Deleted')
            ->assertDontSeeText('No project')
            ->assertDontSeeText('Visits')
            ->assertSeeHtml('<span class="font-semibold text-emerald-600 dark:text-emerald-400">Created</span>')
            ->assertSeeHtml('<span class="font-semibold text-sky-600 dark:text-sky-300">Updated</span>')
            ->assertSeeHtml('<span class="font-semibold text-rose-600 dark:text-rose-400">Deleted</span>');
    }

    public function test_remove_event_action_is_unavailable_without_delete_permission(): void
    {
        $group = PermissionGroup::query()->create([
            'name' => 'Calendar viewers',
            'slug' => 'calendar-viewers-without-delete',
            'description' => 'Calendar access without event deletion.',
            'is_system' => false,
        ]);
        $group->permissions()->attach(Permission::query()->where('key', 'calendar.view')->firstOrFail());
        $this->actingAs(User::factory()->create(['permission_group_id' => $group->id]));

        $component = Livewire::test(CalendarWidget::class)
            ->call('onEventClick', $this->calendarEvent())
            ->assertActionMounted('view');

        $this->assertSame([], $component->instance()->getMountedAction()?->getExtraModalFooterActions());

        $component->mountAction('removeEvent');

        $this->assertCount(1, $component->instance()->mountedActions);
        $this->assertSame('view', $component->instance()->mountedActions[0]['name']);
    }

    public function test_view_only_user_cannot_submit_event_update(): void
    {
        $group = PermissionGroup::query()->create([
            'name' => 'Calendar viewers',
            'slug' => 'calendar-viewers',
            'description' => 'Read-only calendar access.',
            'is_system' => false,
        ]);
        $group->permissions()->attach(Permission::query()->where('key', 'calendar.view')->firstOrFail());
        $this->actingAs(User::factory()->create(['permission_group_id' => $group->id]));

        $component = Livewire::test(CalendarWidget::class)
            ->call('onEventClick', [
                'id' => '00USq00000a2fX2MAI',
                'title' => 'Read-only visit',
                'extendedProps' => [
                    'type' => 'Meeting',
                    'startDate' => '2026-01-19',
                    'endDate' => '2026-01-19',
                    'startTime' => '08:30',
                    'endTime' => '12:00',
                    'allDay' => false,
                ],
            ])
            ->assertActionMounted('view')
            ->assertFormFieldDisabled('subject');

        $this->assertSame('Event details', $component->instance()->getMountedAction()?->getModalHeading());
        $this->assertNull($component->instance()->getMountedAction()?->getModalSubmitAction());

        $component->callMountedAction()->assertForbidden();
    }

    public function test_widget_rejects_direct_event_click_without_calendar_permission(): void
    {
        $group = PermissionGroup::query()->create([
            'name' => 'Restricted',
            'slug' => 'restricted-calendar-event-details',
            'description' => 'No calendar access.',
            'is_system' => false,
        ]);
        $this->actingAs(User::factory()->create(['permission_group_id' => $group->id]));

        try {
            app(CalendarWidget::class)->onEventClick([]);

            $this->fail('Expected the calendar event click to be forbidden.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_widget_rejects_direct_event_fetch_without_calendar_permission(): void
    {
        $group = PermissionGroup::query()->create([
            'name' => 'Restricted',
            'slug' => 'restricted-calendar-widget',
            'description' => 'No calendar access.',
            'is_system' => false,
        ]);
        $this->actingAs(User::factory()->create(['permission_group_id' => $group->id]));

        try {
            app(CalendarWidget::class)->fetchEvents([
                'start' => '2026-01-01T00:00:00+00:00',
                'end' => '2026-02-01T00:00:00+00:00',
                'timezone' => 'Europe/London',
            ]);

            $this->fail('Expected the calendar event fetch to be forbidden.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function calendarEvent(): array
    {
        return [
            'id' => '00USq00000a2fX2MAI',
            'title' => 'EO - Technical training Group B',
            'extendedProps' => [
                'location' => 'Boardroom',
                'owner' => 'Visits',
                'type' => 'Meeting',
                'createdBy' => 'Ian Neill',
                'startDate' => '2026-01-19',
                'endDate' => '2026-01-19',
                'startTime' => '08:30',
                'endTime' => '12:00',
                'allDay' => false,
            ],
        ];
    }
}
