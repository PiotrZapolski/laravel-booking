<?php

namespace Zapol\Booking\Tests\Feature;

use Carbon\CarbonImmutable;
use Zapol\Booking\Contracts\CalendarProvider;

/** In-memory calendar: just enough for the booking HTTP flow. */
class FakeCalendar implements CalendarProvider
{
    /** @var array<string,array<string,mixed>> */
    public array $events = [];

    public function freeBusy(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }

    public function createEvent(array $draft): array
    {
        $id = 'evt-' . (count($this->events) + 1);
        $this->events[$id] = [
            'id'        => $id,
            'summary'   => $draft['summary'],
            'start'     => $draft['start']->toIso8601String(),
            'end'       => $draft['end']->toIso8601String(),
            'meet_link' => null,
            'attendees' => [['email' => $draft['attendee']['email'], 'name' => $draft['attendee']['name']]],
        ];

        return ['id' => $id, 'html_link' => null, 'meet_link' => null];
    }

    public function updateEventTime(string $eventId, CarbonImmutable $start, CarbonImmutable $end, string $timezone): void
    {
        $this->events[$eventId]['start'] = $start->toIso8601String();
        $this->events[$eventId]['end'] = $end->toIso8601String();
    }

    public function deleteEvent(string $eventId): void
    {
        unset($this->events[$eventId]);
    }

    public function getEvent(string $eventId): ?array
    {
        return $this->events[$eventId] ?? null;
    }

    public function supportedConferences(): array
    {
        return [];
    }
}
