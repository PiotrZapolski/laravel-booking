<?php

namespace Zapol\Booking\Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Zapol\Booking\Calendar\CalDav\IcsParser;

class IcsParserTest extends TestCase
{
    public function test_unfold_joins_continuation_lines(): void
    {
        // CRLF + one space or tab is a fold: the whitespace marker is removed.
        $ics = "SUMMARY:Hello\r\n  world\r\nUID:abc\r\n\tdef";

        $this->assertSame("SUMMARY:Hello world\nUID:abcdef", IcsParser::unfold($ics));
    }

    public function test_unfold_normalises_bare_carriage_returns(): void
    {
        $this->assertSame("A\nB", IcsParser::unfold("A\rB"));
    }

    public function test_folded_property_values_are_rejoined_before_parsing(): void
    {
        $ics = $this->wrap([
            'UID:folded-1',
            'DTSTART:20260115T100000Z',
            'DTEND:20260115T110000Z',
            'SUMMARY:Discovery call with a very long ',
            ' title that had to be folded',
        ]);

        $events = IcsParser::parseEvents($ics);

        $this->assertCount(1, $events);
        $this->assertSame(
            'Discovery call with a very long title that had to be folded',
            $events[0]['summary'],
        );
    }

    public function test_parses_utc_date_times(): void
    {
        $ics = $this->wrap([
            'UID:utc-1',
            'DTSTART:20260115T100000Z',
            'DTEND:20260115T113000Z',
        ]);

        $events = IcsParser::parseEvents($ics);

        $this->assertInstanceOf(CarbonImmutable::class, $events[0]['start']);
        $this->assertSame('2026-01-15T10:00:00+00:00', $events[0]['start']->utc()->toIso8601String());
        $this->assertSame('2026-01-15T11:30:00+00:00', $events[0]['end']->utc()->toIso8601String());
        $this->assertFalse($events[0]['all_day']);
    }

    public function test_parses_tzid_date_times(): void
    {
        $ics = $this->wrap([
            'UID:tzid-1',
            'DTSTART;TZID=Europe/Warsaw:20260115T100000',
            'DTEND;TZID=Europe/Warsaw:20260115T110000',
        ]);

        $events = IcsParser::parseEvents($ics);

        // Europe/Warsaw is UTC+1 in January.
        $this->assertSame('2026-01-15T09:00:00+00:00', $events[0]['start']->utc()->toIso8601String());
        $this->assertSame('2026-01-15T10:00:00+00:00', $events[0]['end']->utc()->toIso8601String());
        $this->assertSame('Europe/Warsaw', $events[0]['start']->getTimezone()->getName());
    }

    public function test_parses_prefixed_tzid_used_by_apple_and_mozilla(): void
    {
        $ics = $this->wrap([
            'UID:tzid-2',
            'DTSTART;TZID=/mozilla.org/20050126_1/Europe/Warsaw:20260115T100000',
            'DTEND;TZID=/mozilla.org/20050126_1/Europe/Warsaw:20260115T110000',
        ]);

        $events = IcsParser::parseEvents($ics);

        $this->assertSame('2026-01-15T09:00:00+00:00', $events[0]['start']->utc()->toIso8601String());
    }

    public function test_unknown_tzid_falls_back_to_utc(): void
    {
        $ics = $this->wrap([
            'UID:tzid-3',
            'DTSTART;TZID=Central European Standard Time:20260115T100000',
            'DTEND;TZID=Central European Standard Time:20260115T110000',
        ]);

        $events = IcsParser::parseEvents($ics);

        $this->assertSame('2026-01-15T10:00:00+00:00', $events[0]['start']->utc()->toIso8601String());
    }

    public function test_parses_all_day_date_values(): void
    {
        $ics = $this->wrap([
            'UID:allday-1',
            'DTSTART;VALUE=DATE:20260115',
            'DTEND;VALUE=DATE:20260116',
        ]);

        $events = IcsParser::parseEvents($ics);

        $this->assertTrue($events[0]['all_day']);
        $this->assertSame('2026-01-15T00:00:00+00:00', $events[0]['start']->utc()->toIso8601String());
        $this->assertSame('2026-01-16T00:00:00+00:00', $events[0]['end']->utc()->toIso8601String());
    }

    public function test_all_day_without_dtend_lasts_one_day(): void
    {
        $ics = $this->wrap([
            'UID:allday-2',
            'DTSTART;VALUE=DATE:20260115',
        ]);

        $events = IcsParser::parseEvents($ics);

        $this->assertSame('2026-01-16T00:00:00+00:00', $events[0]['end']->utc()->toIso8601String());
    }

    public function test_duration_is_used_when_dtend_is_missing(): void
    {
        $ics = $this->wrap([
            'UID:duration-1',
            'DTSTART:20260115T100000Z',
            'DURATION:PT1H30M',
        ]);

        $events = IcsParser::parseEvents($ics);

        $this->assertSame('2026-01-15T11:30:00+00:00', $events[0]['end']->utc()->toIso8601String());
    }

    public function test_parse_duration_handles_weeks_days_and_negatives(): void
    {
        $this->assertSame(5400, IcsParser::parseDuration('PT1H30M'));
        $this->assertSame(86400, IcsParser::parseDuration('P1D'));
        $this->assertSame(604800, IcsParser::parseDuration('P1W'));
        $this->assertSame(-900, IcsParser::parseDuration('-PT15M'));
        $this->assertSame(0, IcsParser::parseDuration('nonsense'));
    }

    public function test_transparent_and_cancelled_events_are_flagged(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . $this->vevent(['UID:busy-1', 'DTSTART:20260115T100000Z', 'DTEND:20260115T110000Z'])
            . $this->vevent([
                'UID:free-1',
                'DTSTART:20260115T120000Z',
                'DTEND:20260115T130000Z',
                'TRANSP:TRANSPARENT',
            ])
            . $this->vevent([
                'UID:cancelled-1',
                'DTSTART:20260115T140000Z',
                'DTEND:20260115T150000Z',
                'STATUS:CANCELLED',
            ])
            . "END:VCALENDAR\r\n";

        $events = IcsParser::parseEvents($ics);

        $this->assertCount(3, $events);

        $this->assertFalse($events[0]['transparent']);
        $this->assertFalse($events[0]['cancelled']);

        $this->assertTrue($events[1]['transparent']);
        $this->assertFalse($events[1]['cancelled']);

        $this->assertFalse($events[2]['transparent']);
        $this->assertTrue($events[2]['cancelled']);

        $blocking = array_values(array_filter(
            $events,
            static fn (array $e) => !$e['transparent'] && !$e['cancelled'],
        ));

        $this->assertCount(1, $blocking);
        $this->assertSame('busy-1', $blocking[0]['uid']);
    }

    public function test_parses_attendees_with_cn_and_mailto(): void
    {
        $ics = $this->wrap([
            'UID:attendees-1',
            'DTSTART:20260115T100000Z',
            'DTEND:20260115T110000Z',
            'ORGANIZER;CN=Piotr Zapolski:mailto:piotr@example.com',
            'ATTENDEE;CN=Jan Kowalski;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:jan@example.com',
            'ATTENDEE;CN="Smith; Jane";PARTSTAT=ACCEPTED:mailto:jane@example.com',
            'ATTENDEE:mailto:noname@example.com',
        ]);

        $events = IcsParser::parseEvents($ics);
        $attendees = $events[0]['attendees'];

        $this->assertCount(3, $attendees);
        $this->assertSame(['email' => 'jan@example.com', 'name' => 'Jan Kowalski'], $attendees[0]);
        $this->assertSame(['email' => 'jane@example.com', 'name' => 'Smith; Jane'], $attendees[1]);
        $this->assertSame(['email' => 'noname@example.com', 'name' => null], $attendees[2]);
        $this->assertSame('piotr@example.com', $events[0]['organizer']['email']);
        $this->assertSame('Piotr Zapolski', $events[0]['organizer']['name']);
    }

    public function test_summary_description_and_location_are_unescaped(): void
    {
        $ics = $this->wrap([
            'UID:text-1',
            'DTSTART:20260115T100000Z',
            'DTEND:20260115T110000Z',
            'SUMMARY:Call with Kowalski\, Jan',
            'DESCRIPTION:First line\nSecond line\; done',
            'LOCATION:Warsaw\, Poland',
            'URL:https://zoom.us/j/123456',
        ]);

        $events = IcsParser::parseEvents($ics);

        $this->assertSame('Call with Kowalski, Jan', $events[0]['summary']);
        $this->assertSame("First line\nSecond line; done", $events[0]['description']);
        $this->assertSame('Warsaw, Poland', $events[0]['location']);
        $this->assertSame('https://zoom.us/j/123456', $events[0]['url']);
    }

    public function test_nested_components_do_not_leak_into_the_event(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VTIMEZONE\r\nTZID:Europe/Warsaw\r\n"
            . "BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE\r\n"
            . "BEGIN:VEVENT\r\nUID:alarm-1\r\nDTSTART:20260115T100000Z\r\nDTEND:20260115T110000Z\r\n"
            . "BEGIN:VALARM\r\nTRIGGER:-PT15M\r\nDESCRIPTION:Reminder\r\nACTION:DISPLAY\r\n"
            . "END:VALARM\r\nSUMMARY:Real summary\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $events = IcsParser::parseEvents($ics);

        $this->assertCount(1, $events);
        $this->assertSame('Real summary', $events[0]['summary']);
        $this->assertNull($events[0]['description']);
        $this->assertSame('2026-01-15T10:00:00+00:00', $events[0]['start']->utc()->toIso8601String());
    }

    public function test_escape_and_unescape_round_trip(): void
    {
        $value = "Path C:\\temp, then; a break\nand more";

        $escaped = IcsParser::escape($value);

        $this->assertStringNotContainsString("\n", $escaped);
        $this->assertStringContainsString('\\\\', $escaped);
        $this->assertStringContainsString('\,', $escaped);
        $this->assertStringContainsString('\;', $escaped);
        $this->assertStringContainsString('\n', $escaped);

        $this->assertSame($value, IcsParser::unescape($escaped));
    }

    public function test_parse_events_returns_empty_array_for_junk(): void
    {
        $this->assertSame([], IcsParser::parseEvents(''));
        $this->assertSame([], IcsParser::parseEvents('not an icalendar document'));
    }

    /**
     * @param array<int,string> $lines
     */
    private function wrap(array $lines): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
            . $this->vevent($lines)
            . "END:VCALENDAR\r\n";
    }

    /**
     * @param array<int,string> $lines
     */
    private function vevent(array $lines): string
    {
        return "BEGIN:VEVENT\r\n" . implode("\r\n", $lines) . "\r\nEND:VEVENT\r\n";
    }
}
