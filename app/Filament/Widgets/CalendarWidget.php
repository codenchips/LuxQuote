<?php

namespace App\Filament\Widgets;

use App\Services\SalesforceService;
use Carbon\CarbonImmutable;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

class CalendarWidget extends FullCalendarWidget
{
    protected static bool $isDiscovered = false;

    /**
     * @param  array{start: string, end: string, timezone: string}  $info
     * @return array<int, array<string, mixed>>
     */
    public function fetchEvents(array $info): array
    {
        abort_unless(auth()->user()?->can('calendar.view'), 403);

        $calendarId = (string) config('services.salesforce.visits_calendar_id');
        $from = CarbonImmutable::parse($info['start'])->utc()->format('Y-m-d\TH:i:s\Z');
        $to = CarbonImmutable::parse($info['end'])->utc()->format('Y-m-d\TH:i:s\Z');
        $response = app(SalesforceService::class)->fetchCalendarBookings($calendarId, $from, $to, 200);

        if (! ($response['success'] ?? false)) {
            return [];
        }

        return collect($response['records'] ?? [])
            ->flatMap(fn (array $booking): array => $this->calendarEventsForBooking($booking))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return [
            'initialView' => 'dayGridMonth',
            'firstDay' => 1,
            'nowIndicator' => true,
            'dayMaxEvents' => true,
            'eventDisplay' => 'block',
            'displayEventEnd' => true,
            'expandRows' => true,
            'height' => 'auto',
            'scrollTime' => '08:00:00',
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

                if (view.type === 'dayGridMonth') {
                    content.className = 'lux-calendar-event-month'

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

    /**
     * @param  array<string, mixed>  $booking
     * @return array<int, array<string, mixed>>
     */
    private function calendarEventsForBooking(array $booking): array
    {
        $id = (string) ($booking['Id'] ?? '');
        $subject = trim((string) ($booking['Subject'] ?? '')) ?: 'Room booking';
        $location = filled($booking['Location'] ?? null) ? (string) $booking['Location'] : null;

        if (! ($booking['IsAllDayEvent'] ?? false)) {
            return [[
                ...$this->eventBase($id, $subject, $location),
                'start' => CarbonImmutable::parse((string) $booking['StartDateTime'])->toIso8601String(),
                'end' => CarbonImmutable::parse((string) $booking['EndDateTime'])->toIso8601String(),
            ]];
        }

        $timezone = (string) config('app.timezone');
        $firstDay = CarbonImmutable::parse(substr((string) $booking['StartDateTime'], 0, 10), $timezone)->startOfDay();
        $lastDay = CarbonImmutable::parse(substr((string) $booking['EndDateTime'], 0, 10), $timezone)->startOfDay();
        $events = [];

        for ($day = $firstDay; $day->lessThanOrEqualTo($lastDay); $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            $events[] = [
                ...$this->eventBase("{$id}-{$date}", $subject, $location),
                'groupId' => $id,
                'start' => $day->setTime(8, 0)->toIso8601String(),
                'end' => $day->setTime(17, 0)->toIso8601String(),
            ];
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventBase(string $id, string $subject, ?string $location): array
    {
        return [
            'id' => $id,
            'title' => $subject,
            'allDay' => false,
            'display' => 'block',
            'classNames' => ['lux-calendar-booking'],
            'extendedProps' => [
                'location' => $location,
            ],
        ];
    }
}
