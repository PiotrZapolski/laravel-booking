<?php

namespace Zapol\Booking\Contracts;

use Carbon\CarbonImmutable;

interface CalendarProvider
{
    /** Busy intervals across every calendar this driver is configured to check.
     *  @return array<int,array{start:CarbonImmutable,end:CarbonImmutable}> */
    public function freeBusy(CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * @param array{
     *   summary:string, description:string,
     *   start:CarbonImmutable, end:CarbonImmutable, timezone:string,
     *   attendee:array{email:string,name:string},
     *   additional_attendees:array<int,string>,
     *   conference:?string,   // 'google_meet' | 'teams' | null: ask the calendar to create a native conference
     *   location:?string      // free text or URL to store as the event location (e.g. Zoom join URL, office address)
     * } $draft
     * @return array{id:string,html_link:?string,meet_link:?string}
     */
    public function createEvent(array $draft): array;

    public function updateEventTime(string $eventId, CarbonImmutable $start, CarbonImmutable $end, string $timezone): void;

    public function deleteEvent(string $eventId): void;

    /** @return null|array{id:string,summary:?string,start:?string,end:?string,meet_link:?string,attendees:array<int,array{email:string,name:?string}>} */
    public function getEvent(string $eventId): ?array;

    /** Which native conference kinds this driver can create: e.g. ['google_meet'] or ['teams']. */
    public function supportedConferences(): array;
}
