<?php

namespace Zapol\Booking\Mail;

use Carbon\CarbonImmutable;

/**
 * Builds an RFC-5545 iCalendar attachment for a booking lifecycle event.
 *
 * Three METHODs cover the trio of mailables:
 *   - REQUEST  initial confirmation; client adds the event to the calendar
 *   - REQUEST  reschedule; same UID + same METHOD, client treats it as an update
 *   - CANCEL   cancellation; same UID + STATUS:CANCELLED, client removes event
 *
 * UID is stable across the trio (derived from the calendar event id)
 * so attendee calendar apps reliably correlate the messages.
 */
class IcsBuilder
{
    public const METHOD_REQUEST = 'REQUEST';
    public const METHOD_CANCEL  = 'CANCEL';

    /**
     * @param array<string,mixed> $payload Must contain start, end; may contain
     *     event_id, meet_link, location_label, location_text, reschedule_url,
     *     fields[email|name].
     * @param array<string,mixed> $eventType
     * @param string $organizerEmail
     * @param string $method One of the METHOD_* constants.
     */
    public static function build(
        array $payload,
        array $eventType,
        string $organizerEmail,
        string $method = self::METHOD_REQUEST,
    ): string {
        $start = CarbonImmutable::parse($payload['start'])->utc()->format('Ymd\\THis\\Z');
        $end = CarbonImmutable::parse($payload['end'])->utc()->format('Ymd\\THis\\Z');
        $now = CarbonImmutable::now()->utc()->format('Ymd\\THis\\Z');

        $uid = ($payload['event_id'] ?? bin2hex(random_bytes(8))) . '@booking';
        $title = self::escape($eventType['title'] ?? 'Booking');
        if ($method === self::METHOD_CANCEL) {
            $title = self::escape(($eventType['title'] ?? 'Booking') . ' - cancelled');
        }
        $descriptionLines = [];
        if ($method === self::METHOD_CANCEL) {
            $descriptionLines[] = 'This meeting has been cancelled.';
        } else {
            $descriptionLines[] = 'Booking confirmation';
            $descriptionLines[] = '';
            if (!empty($payload['meet_link'])) {
                $descriptionLines[] = ($payload['location_label'] ?? 'Meeting link') . ': ' . $payload['meet_link'];
            }
            if (!empty($payload['reschedule_url'])) {
                $descriptionLines[] = 'Reschedule: ' . $payload['reschedule_url'];
            }
        }
        $description = self::escape(implode("\n", $descriptionLines));
        $location = self::escape((string) ($payload['meet_link'] ?? $payload['location_text'] ?? ''));

        $attendeeEmail = $payload['fields']['email'] ?? ($payload['attendee'] ?? null);
        $attendeeName = $payload['fields']['name'] ?? null;

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//zapol/laravel-booking//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
            'BEGIN:VEVENT',
            "UID:{$uid}",
            "DTSTAMP:{$now}",
            'SEQUENCE:' . self::sequenceFor($method),
            "DTSTART:{$start}",
            "DTEND:{$end}",
            "SUMMARY:{$title}",
            "DESCRIPTION:{$description}",
            "LOCATION:{$location}",
            "ORGANIZER;CN={$organizerEmail}:mailto:{$organizerEmail}",
        ];

        if ($attendeeEmail) {
            $cn = $attendeeName ? self::escape($attendeeName) : self::escape($attendeeEmail);
            $partstat = $method === self::METHOD_CANCEL ? 'DECLINED' : 'NEEDS-ACTION';
            $lines[] = "ATTENDEE;CN={$cn};ROLE=REQ-PARTICIPANT;PARTSTAT={$partstat};RSVP=TRUE:mailto:{$attendeeEmail}";
        }

        $lines[] = $method === self::METHOD_CANCEL ? 'STATUS:CANCELLED' : 'STATUS:CONFIRMED';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        $lines[] = '';

        return implode("\r\n", $lines);
    }

    /**
     * RFC 5545 says SEQUENCE must increment on each update. We can't store
     * state in a stateless package, so we use 0 for initial REQUEST and 1+
     * for subsequent updates / cancels. Calendar clients accept this; the
     * UID match is what makes them correlate.
     */
    private static function sequenceFor(string $method): int
    {
        return $method === self::METHOD_REQUEST ? 0 : 1;
    }

    private static function escape(string $value): string
    {
        return preg_replace(
            ['/\\\\/', '/\n/', '/,/', '/;/'],
            ['\\\\\\\\', '\\n', '\\,', '\\;'],
            $value,
        );
    }
}
