<?php

namespace Tests\Feature;

use App\Filament\Widgets\CalendarWidget;
use App\Models\PermissionGroup;
use App\Models\User;
use App\Services\SalesforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                        ],
                        [
                            'Id' => '00USq00000ALLDAYAA',
                            'Subject' => 'Two day visit',
                            'StartDateTime' => '2026-01-20T00:00:00.000+0000',
                            'EndDateTime' => '2026-01-21T00:00:00.000+0000',
                            'IsAllDayEvent' => true,
                            'Location' => 'Showroom',
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
        $this->assertSame('2026-01-19T08:30:00+00:00', $events[0]['start']);
        $this->assertSame('2026-01-19T12:00:00+00:00', $events[0]['end']);
        $this->assertFalse($events[0]['allDay']);
        $this->assertTrue($events[1]['allDay']);
        $this->assertSame('2026-01-20', $events[1]['start']);
        $this->assertSame('2026-01-22', $events[1]['end']);
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
}
