<?php

namespace Zapol\Booking\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zapol\Booking\Calendar\CalDav\IcsParser;
use Zapol\Booking\Calendar\CalDavCalendarProvider;

class CalDavCalendarProviderTest extends TestCase
{
    private const COLLECTION = 'https://caldav.example.com/dav/calendars/user/booking/';

    public function test_free_busy_sends_a_report_to_the_collection_with_depth_one(): void
    {
        $http = new Factory();
        $http->fake([
            'https://caldav.example.com/*' => $http::response($this->multistatus([$this->busyIcs()]), 207),
        ]);

        $provider = $this->provider($http);

        $provider->freeBusy($this->at('2026-01-15T00:00:00Z'), $this->at('2026-01-16T00:00:00Z'));

        $http->assertSent(function (Request $request) {
            $auth = $request->header('Authorization');

            return $request->method() === 'REPORT'
                && $request->url() === self::COLLECTION
                && $request->hasHeader('Depth', '1')
                && isset($auth[0])
                && $auth[0] === 'Basic ' . base64_encode('user@example.com:app-specific-password')
                && str_contains($request->body(), '<C:calendar-query')
                && str_contains($request->body(), 'urn:ietf:params:xml:ns:caldav')
                && str_contains($request->body(), '<C:time-range start="20260115T000000Z" end="20260116T000000Z"/>')
                && str_contains($request->body(), '<C:expand start="20260115T000000Z" end="20260116T000000Z"/>');
        });
    }

    public function test_free_busy_parses_multistatus_and_skips_free_and_cancelled_events(): void
    {
        $http = new Factory();
        $http->fake([
            '*' => $http::response($this->multistatus([
                $this->overnightIcs(),
                $this->busyIcs(),
                $this->transparentIcs(),
                $this->cancelledIcs(),
            ]), 207),
        ]);

        $busy = $this->provider($http)->freeBusy(
            $this->at('2026-01-15T00:00:00Z'),
            $this->at('2026-01-16T00:00:00Z'),
        );

        $this->assertCount(2, $busy);

        // Clamped to the requested window and sorted by start.
        $this->assertSame('2026-01-15T00:00:00+00:00', $busy[0]['start']->utc()->toIso8601String());
        $this->assertSame('2026-01-15T01:00:00+00:00', $busy[0]['end']->utc()->toIso8601String());
        $this->assertSame('2026-01-15T10:00:00+00:00', $busy[1]['start']->utc()->toIso8601String());
        $this->assertSame('2026-01-15T11:00:00+00:00', $busy[1]['end']->utc()->toIso8601String());
    }

    public function test_free_busy_also_queries_extra_busy_collections(): void
    {
        $http = new Factory();
        $http->fake(['*' => $http::response($this->multistatus([]), 207)]);

        $provider = new CalDavCalendarProvider($this->config([
            'busy_urls' => ['https://caldav.example.com/dav/calendars/user/personal'],
        ]), $http);

        $provider->freeBusy($this->at('2026-01-15T00:00:00Z'), $this->at('2026-01-16T00:00:00Z'));

        $http->assertSentCount(2);
        $http->assertSent(fn (Request $r) => $r->url() === 'https://caldav.example.com/dav/calendars/user/personal/');
    }

    public function test_free_busy_throws_when_the_server_rejects_the_report(): void
    {
        $http = new Factory();
        $http->fake(['*' => $http::response('nope', 500)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CalDAV REPORT failed');

        $this->provider($http)->freeBusy($this->at('2026-01-15T00:00:00Z'), $this->at('2026-01-16T00:00:00Z'));
    }

    public function test_create_event_puts_an_ics_resource_named_after_the_uid(): void
    {
        $http = new Factory();
        $http->fake(['*' => $http::response('', 201, ['ETag' => '"1"'])]);

        $result = $this->provider($http)->createEvent($this->draft());

        $this->assertNotSame('', $result['id']);
        $this->assertNull($result['html_link']);
        $this->assertNull($result['meet_link']);

        $uid = $result['id'];

        $http->assertSent(function (Request $request) use ($uid) {
            // Long property lines are folded on the wire, so compare unfolded.
            $body = IcsParser::unfold($request->body());

            return $request->method() === 'PUT'
                && $request->url() === self::COLLECTION . $uid . '.ics'
                && $request->hasHeader('If-None-Match', '*')
                && $request->hasHeader('Content-Type', 'text/calendar; charset=utf-8')
                && str_contains($request->body(), "END:VCALENDAR\r\n")
                && str_contains($body, 'BEGIN:VCALENDAR')
                && str_contains($body, 'PRODID:-//zapol/laravel-booking//EN')
                && str_contains($body, 'UID:' . $uid)
                && str_contains($body, 'DTSTAMP:')
                && str_contains($body, 'SEQUENCE:0')
                && str_contains($body, 'DTSTART:20260115T090000Z')
                && str_contains($body, 'DTEND:20260115T093000Z')
                && str_contains($body, 'SUMMARY:Consultation')
                && str_contains($body, 'DESCRIPTION:Booked through the widget')
                && str_contains($body, 'ORGANIZER;CN=Piotr Zapolski:mailto:piotr@example.com')
                && str_contains($body, 'ATTENDEE;CN=Jan Kowalski;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:jan@example.com')
                && str_contains($body, 'ATTENDEE;CN=extra@example.com;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:extra@example.com');
        });
    }

    public function test_create_event_folds_long_lines_to_75_octets(): void
    {
        $http = new Factory();
        $http->fake(['*' => $http::response('', 201)]);

        $draft = $this->draft();
        $draft['description'] = str_repeat('Bardzo dluga notatka o spotkaniu. ', 12);

        $this->provider($http)->createEvent($draft);

        $http->assertSent(function (Request $request) use ($draft) {
            foreach (explode("\r\n", $request->body()) as $line) {
                if (strlen($line) > 75) {
                    return false;
                }
            }

            return str_contains(
                IcsParser::unfold($request->body()),
                'DESCRIPTION:' . rtrim($draft['description']),
            );
        });
    }

    public function test_create_event_stores_a_url_location_as_both_location_and_url(): void
    {
        $http = new Factory();
        $http->fake(['*' => $http::response('', 201)]);

        $draft = $this->draft();
        $draft['location'] = 'https://zoom.us/j/98765';

        $this->provider($http)->createEvent($draft);

        $http->assertSent(fn (Request $r) => str_contains(IcsParser::unfold($r->body()), 'LOCATION:https://zoom.us/j/98765')
            && str_contains(IcsParser::unfold($r->body()), "\nURL:https://zoom.us/j/98765"));
    }

    public function test_create_event_escapes_plain_text_locations_and_omits_url(): void
    {
        $http = new Factory();
        $http->fake(['*' => $http::response('', 201)]);

        $draft = $this->draft();
        $draft['location'] = 'Warsaw, Poland; 2nd floor';

        $this->provider($http)->createEvent($draft);

        $http->assertSent(fn (Request $r) => str_contains(IcsParser::unfold($r->body()), 'LOCATION:Warsaw\, Poland\; 2nd floor')
            && !str_contains(IcsParser::unfold($r->body()), "\nURL:"));
    }

    public function test_create_event_throws_when_the_put_is_rejected(): void
    {
        $http = new Factory();
        $http->fake(['*' => $http::response('precondition failed', 412)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CalDAV PUT failed');

        $this->provider($http)->createEvent($this->draft());
    }

    public function test_update_event_time_rewrites_times_and_bumps_sequence(): void
    {
        $existing = $this->storedIcs(['SEQUENCE:2']);

        $http = new Factory();
        $http->fake(fn (Request $request) => $request->method() === 'GET'
            ? Factory::response($existing, 200, ['Content-Type' => 'text/calendar'])
            : Factory::response('', 204));

        $this->provider($http)->updateEventTime(
            'evt-1',
            $this->at('2026-01-20T13:00:00Z'),
            $this->at('2026-01-20T14:00:00Z'),
            'Europe/Warsaw',
        );

        $http->assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === self::COLLECTION . 'evt-1.ics');

        $http->assertSent(function (Request $request) {
            if ($request->method() !== 'PUT') {
                return false;
            }
            $body = IcsParser::unfold($request->body());

            return $request->url() === self::COLLECTION . 'evt-1.ics'
                && $request->hasHeader('Content-Type', 'text/calendar; charset=utf-8')
                && str_contains($body, 'DTSTART:20260120T130000Z')
                && str_contains($body, 'DTEND:20260120T140000Z')
                && str_contains($body, 'SEQUENCE:3')
                && str_contains($body, 'UID:evt-1')
                && str_contains($body, 'SUMMARY:Consultation')
                && !str_contains($body, '20260115T090000Z')
                && !str_contains($body, 'SEQUENCE:2');
        });
    }

    public function test_update_event_time_adds_a_sequence_when_the_event_has_none(): void
    {
        $http = new Factory();
        $http->fake(fn (Request $request) => $request->method() === 'GET'
            ? Factory::response($this->storedIcs(), 200)
            : Factory::response('', 204));

        $this->provider($http)->updateEventTime(
            'evt-1',
            $this->at('2026-01-20T13:00:00Z'),
            $this->at('2026-01-20T14:00:00Z'),
            'UTC',
        );

        $http->assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_contains(IcsParser::unfold($r->body()), 'SEQUENCE:1'));
    }

    public function test_update_event_time_replaces_a_duration_with_an_explicit_dtend(): void
    {
        $stored = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:evt-1\r\n"
            . "DTSTAMP:20260101T090000Z\r\nDTSTART:20260115T090000Z\r\nDURATION:PT30M\r\n"
            . "SUMMARY:Consultation\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $http = new Factory();
        $http->fake(fn (Request $request) => $request->method() === 'GET'
            ? Factory::response($stored, 200)
            : Factory::response('', 204));

        $this->provider($http)->updateEventTime(
            'evt-1',
            $this->at('2026-01-20T13:00:00Z'),
            $this->at('2026-01-20T14:00:00Z'),
            'UTC',
        );

        $http->assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_contains(IcsParser::unfold($r->body()), 'DTEND:20260120T140000Z')
            && !str_contains(IcsParser::unfold($r->body()), 'DURATION:'));
    }

    public function test_update_event_time_leaves_vtimezone_start_lines_alone(): void
    {
        $stored = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VTIMEZONE\r\nTZID:Europe/Warsaw\r\nBEGIN:STANDARD\r\n"
            . "DTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE\r\n"
            . "BEGIN:VEVENT\r\nUID:evt-1\r\nDTSTART:20260115T090000Z\r\nDTEND:20260115T093000Z\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $http = new Factory();
        $http->fake(fn (Request $request) => $request->method() === 'GET'
            ? Factory::response($stored, 200)
            : Factory::response('', 204));

        $this->provider($http)->updateEventTime(
            'evt-1',
            $this->at('2026-01-20T13:00:00Z'),
            $this->at('2026-01-20T14:00:00Z'),
            'UTC',
        );

        $http->assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_contains(IcsParser::unfold($r->body()), 'DTSTART:19701025T030000')
            && str_contains(IcsParser::unfold($r->body()), 'DTSTART:20260120T130000Z'));
    }

    public function test_delete_event_sends_a_delete_to_the_event_url(): void
    {
        $http = new Factory();
        $http->fake(['*' => $http::response('', 204)]);

        $this->provider($http)->deleteEvent('evt-1');

        $http->assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === self::COLLECTION . 'evt-1.ics');
    }

    public function test_delete_event_throws_on_server_error(): void
    {
        $http = new Factory();
        $http->fake(['*' => $http::response('boom', 503)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CalDAV DELETE failed');

        $this->provider($http)->deleteEvent('evt-1');
    }

    public function test_get_event_normalises_the_stored_ics(): void
    {
        $http = new Factory();
        $http->fake(['*' => $http::response($this->storedIcs([
            'LOCATION:https://zoom.us/j/98765',
            'URL:https://zoom.us/j/98765',
            'ATTENDEE;CN=Jan Kowalski;PARTSTAT=NEEDS-ACTION:mailto:jan@example.com',
        ]), 200, ['Content-Type' => 'text/calendar'])]);

        $event = $this->provider($http)->getEvent('evt-1');

        $this->assertSame('evt-1', $event['id']);
        $this->assertSame('Consultation', $event['summary']);
        $this->assertSame('2026-01-15T09:00:00+00:00', $event['start']);
        $this->assertSame('2026-01-15T09:30:00+00:00', $event['end']);
        $this->assertSame('https://zoom.us/j/98765', $event['meet_link']);
        $this->assertSame([['email' => 'jan@example.com', 'name' => 'Jan Kowalski']], $event['attendees']);

        $http->assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === self::COLLECTION . 'evt-1.ics');
    }

    public function test_get_event_returns_null_on_404(): void
    {
        $http = new Factory();
        $http->fake(['*' => $http::response('not found', 404)]);

        $this->assertNull($this->provider($http)->getEvent('missing'));
    }

    public function test_get_event_throws_on_other_errors(): void
    {
        $http = new Factory();
        $http->fake(['*' => $http::response('boom', 500)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CalDAV GET failed');

        $this->provider($http)->getEvent('evt-1');
    }

    public function test_collection_url_always_ends_with_a_single_slash(): void
    {
        $http = new Factory();

        $withSlash = new CalDavCalendarProvider($this->config(['url' => self::COLLECTION]), $http);
        $withoutSlash = new CalDavCalendarProvider($this->config(), $http);

        $this->assertSame(self::COLLECTION, $withSlash->collectionUrl());
        $this->assertSame(self::COLLECTION, $withoutSlash->collectionUrl());
        $this->assertSame(self::COLLECTION . 'abc.ics', $withoutSlash->eventUrl('abc'));
    }

    public function test_missing_url_throws(): void
    {
        $provider = new CalDavCalendarProvider($this->config(['url' => null]), new Factory());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BOOKING_CALDAV_URL');

        $provider->collectionUrl();
    }

    public function test_the_driver_creates_no_native_conferences(): void
    {
        $this->assertSame([], $this->provider(new Factory())->supportedConferences());
    }

    private function provider(Factory $http): CalDavCalendarProvider
    {
        return new CalDavCalendarProvider($this->config(), $http);
    }

    /**
     * @param array<string,mixed> $caldav
     * @return array<string,mixed>
     */
    private function config(array $caldav = []): array
    {
        return [
            'caldav' => array_merge([
                // Deliberately without a trailing slash: the driver must add one.
                'url'       => 'https://caldav.example.com/dav/calendars/user/booking',
                'username'  => 'user@example.com',
                'password'  => 'app-specific-password',
                'busy_urls' => [],
            ], $caldav),
            'organizer' => [
                'name'     => 'Piotr Zapolski',
                'email'    => 'piotr@example.com',
                'timezone' => 'Europe/Warsaw',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function draft(): array
    {
        return [
            'summary'              => 'Consultation',
            'description'          => 'Booked through the widget',
            'start'                => $this->at('2026-01-15T09:00:00Z'),
            'end'                  => $this->at('2026-01-15T09:30:00Z'),
            'timezone'             => 'Europe/Warsaw',
            'attendee'             => ['email' => 'jan@example.com', 'name' => 'Jan Kowalski'],
            'additional_attendees' => ['extra@example.com'],
            'conference'           => null,
            'location'             => null,
        ];
    }

    private function at(string $iso): CarbonImmutable
    {
        return CarbonImmutable::parse($iso, 'UTC');
    }

    /**
     * @param array<int,string> $extraLines
     */
    private function storedIcs(array $extraLines = []): string
    {
        $lines = array_merge([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//zapol/laravel-booking//EN',
            'BEGIN:VEVENT',
            'UID:evt-1',
            'DTSTAMP:20260101T090000Z',
            'DTSTART:20260115T090000Z',
            'DTEND:20260115T093000Z',
            'SUMMARY:Consultation',
        ], $extraLines, [
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        return implode("\r\n", $lines);
    }

    private function busyIcs(): string
    {
        return $this->calendar(['UID:busy-1', 'DTSTART:20260115T100000Z', 'DTEND:20260115T110000Z', 'SUMMARY:Standup']);
    }

    private function overnightIcs(): string
    {
        return $this->calendar([
            'UID:overnight-1',
            'DTSTART:20260114T230000Z',
            'DTEND:20260115T010000Z',
            'SUMMARY:Long call',
        ]);
    }

    private function transparentIcs(): string
    {
        return $this->calendar([
            'UID:free-1',
            'DTSTART:20260115T120000Z',
            'DTEND:20260115T130000Z',
            'TRANSP:TRANSPARENT',
            'SUMMARY:Focus time',
        ]);
    }

    private function cancelledIcs(): string
    {
        return $this->calendar([
            'UID:cancelled-1',
            'DTSTART:20260115T140000Z',
            'DTEND:20260115T150000Z',
            'STATUS:CANCELLED',
            'SUMMARY:Dropped',
        ]);
    }

    /**
     * @param array<int,string> $veventLines
     */
    private function calendar(array $veventLines): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n"
            . implode("\r\n", $veventLines)
            . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * A WebDAV multistatus body shaped like the one a CalDAV server returns
     * for a calendar-query REPORT. The caldav namespace deliberately uses a
     * different prefix than the request so the parser cannot rely on prefixes.
     *
     * @param array<int,string> $calendars
     */
    private function multistatus(array $calendars): string
    {
        $responses = '';

        foreach ($calendars as $index => $ics) {
            $responses .= '<d:response>'
                . '<d:href>/dav/calendars/user/booking/' . $index . '.ics</d:href>'
                . '<d:propstat><d:prop>'
                . '<d:getetag>"etag-' . $index . '"</d:getetag>'
                . '<cal:calendar-data>' . $ics . '</cal:calendar-data>'
                . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
                . '</d:response>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            . $responses
            . '</d:multistatus>';
    }
}
