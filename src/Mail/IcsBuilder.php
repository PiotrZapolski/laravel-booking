<?php

namespace Zapol\Booking\Mail;

use Carbon\CarbonImmutable;

class IcsBuilder
{
    public static function build(array $payload, array $eventType, string $organizerEmail): string
    {
        $start = CarbonImmutable::parse($payload['start'])->utc()->format('Ymd\\THis\\Z');
        $end = CarbonImmutable::parse($payload['end'])->utc()->format('Ymd\\THis\\Z');
        $now = CarbonImmutable::now()->utc()->format('Ymd\\THis\\Z');

        $uid = ($payload['event_id'] ?? bin2hex(random_bytes(8))) . '@booking';
        $title = self::escape($eventType['title'] ?? 'Booking');
        $description = self::escape(sprintf(
            "Booking confirmation\n\nMeet link: %s\nReschedule: %s",
            $payload['meet_link'] ?? '',
            $payload['reschedule_url'] ?? '',
        ));
        $location = self::escape($payload['meet_link'] ?? '');

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Booking//EN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            "DTSTAMP:{$now}",
            "DTSTART:{$start}",
            "DTEND:{$end}",
            "SUMMARY:{$title}",
            "DESCRIPTION:{$description}",
            "LOCATION:{$location}",
            "ORGANIZER:mailto:{$organizerEmail}",
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
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
