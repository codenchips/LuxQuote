<?php

namespace App\Filament\Widgets;

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
        return [];
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
        ];
    }

    protected function headerActions(): array
    {
        return [];
    }
}
