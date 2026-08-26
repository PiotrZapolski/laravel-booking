<?php

namespace Zapol\Booking\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use Zapol\Booking\Calendar\CalDav\IcsParser;
use Zapol\Booking\Contracts\CalendarProvider;

/**
 * CalDAV calendar driver: iCloud, Nextcloud, Fastmail, Radicale and any other
 * RFC 4791 server reachable over HTTPS with basic auth.
 *
 * The driver speaks raw HTTP: a `calendar-query` REPORT for busy time and
 * plain PUT / GET / DELETE on `{collection}/{uid}.ics` for the event itself.
 * There is no native conference support, so `supportedConferences()` is empty
 * and the controller stores whatever meeting URL it produced as the LOCATION.
 *
 * Expected config shape:
 *   ['caldav' => config('booking.caldav'), 'organizer' => config('booking.organizer')]
 */
class CalDavCalendarProvider implements CalendarProvider
{
    private const NS_DAV = 'DAV:';
    private const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(private array $config, private Factory $http)
    {
    }

    /**
     * @return array<int,array{start:CarbonImmutable,end:CarbonImmutable}>
     */
    public function freeBusy(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->utc();
        $end = $to->utc();
        $xml = $this->calendarQueryXml($start, $end);

        $busy = [];

        foreach ($this->busyUrls() as $url) {
            $response = $this->client()
                ->withHeaders(['Depth' => '1'])
                ->withBody($xml, 'application/xml')
                ->send('REPORT', $url);

            $this->assertSuccessful($response, "CalDAV REPORT failed for {$url}");

            foreach ($this->calendarData($response->body()) as $ics) {
                foreach (IcsParser::parseEvents($ics) as $event) {
                    if ($event['transparent'] || $event['cancelled']) {
                        continue;
                    }
                    if (!$event['start'] instanceof CarbonImmutable || !$event['end'] instanceof CarbonImmutable) {
                        continue;
                    }

                    $eventStart = $event['start']->utc();
                    $eventEnd = $event['end']->utc();

                    if ($eventEnd->lessThanOrEqualTo($eventStart)) {
                        continue;
                    }
                    if ($eventEnd->lessThanOrEqualTo($start) || $eventStart->greaterThanOrEqualTo($end)) {
                        continue;
                    }

                    $busy[] = [
                        'start' => $eventStart->lessThan($start) ? $start : $eventStart,
                        'end'   => $eventEnd->greaterThan($end) ? $end : $eventEnd,
                    ];
                }
            }
        }

        usort($busy, static fn (array $a, array $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        return $busy;
    }

    /**
     * @param array<string,mixed> $draft
     * @return array{id:string,html_link:?string,meet_link:?string}
     */
    public function createEvent(array $draft): array
    {
        $uid = (string) Str::uuid();
        $ics = $this->buildIcs($uid, $draft);

        $response = $this->client()
            ->withHeaders(['If-None-Match' => '*'])
            ->withBody($ics, 'text/calendar; charset=utf-8')
            ->put($this->eventUrl($uid));

        $this->assertSuccessful($response, 'CalDAV PUT failed while creating the event');

        // A CalDAV server never mints a conference link, so the controller
        // derives meet_link from the location it asked us to store.
        return [
            'id'        => $uid,
            'html_link' => null,
            'meet_link' => null,
        ];
    }

    public function updateEventTime(string $eventId, CarbonImmutable $start, CarbonImmutable $end, string $timezone): void
    {
        $url = $this->eventUrl($eventId);

        $current = $this->client()->get($url);
        $this->assertSuccessful($current, "CalDAV GET failed for event {$eventId}");

        $ics = $this->replaceTimes($current->body(), $start, $end);

        $response = $this->client()
            ->withBody($ics, 'text/calendar; charset=utf-8')
            ->put($url);

        $this->assertSuccessful($response, "CalDAV PUT failed while rescheduling event {$eventId}");
    }

    public function deleteEvent(string $eventId): void
    {
        $response = $this->client()->delete($this->eventUrl($eventId));

        $this->assertSuccessful($response, "CalDAV DELETE failed for event {$eventId}");
    }

    /**
     * @return null|array{id:string,summary:?string,start:?string,end:?string,meet_link:?string,attendees:array<int,array{email:string,name:?string}>}
     */
    public function getEvent(string $eventId): ?array
    {
        $response = $this->client()->get($this->eventUrl($eventId));

        if ($response->status() === 404) {
            return null;
        }

        $this->assertSuccessful($response, "CalDAV GET failed for event {$eventId}");

        $events = IcsParser::parseEvents($response->body());
        if ($events === []) {
            return null;
        }

        $event = $events[0];
        $location = $event['location'];

        return [
            'id'        => $event['uid'] !== '' ? $event['uid'] : $eventId,
            'summary'   => $event['summary'],
            'start'     => $event['start'] instanceof CarbonImmutable ? $event['start']->toIso8601String() : null,
            'end'       => $event['end'] instanceof CarbonImmutable ? $event['end']->toIso8601String() : null,
            'meet_link' => $event['url'] ?: ($this->isUrl($location) ? $location : null),
            'attendees' => $event['attendees'],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function supportedConferences(): array
    {
        return [];
    }

    /**
     * The primary collection URL, always with a trailing slash so that
     * appending "{uid}.ics" produces a valid resource path.
     */
    public function collectionUrl(): string
    {
        $url = trim((string) ($this->caldav()['url'] ?? ''));

        if ($url === '') {
            throw new RuntimeException('CalDAV calendar URL is not configured (BOOKING_CALDAV_URL).');
        }

        return rtrim($url, '/') . '/';
    }

    public function eventUrl(string $uid): string
    {
        return $this->collectionUrl() . rawurlencode($uid) . '.ics';
    }

    /**
     * Primary collection plus every extra collection whose events count as busy.
     *
     * @return array<int,string>
     */
    private function busyUrls(): array
    {
        $extra = $this->caldav()['busy_urls'] ?? [];
        if (!is_array($extra)) {
            $extra = array_filter(array_map('trim', explode(',', (string) $extra)));
        }

        $urls = [$this->collectionUrl()];
        foreach ($extra as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $urls[] = rtrim($url, '/') . '/';
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array<string,mixed>
     */
    private function caldav(): array
    {
        $caldav = $this->config['caldav'] ?? [];

        return is_array($caldav) ? $caldav : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function organizer(): array
    {
        $organizer = $this->config['organizer'] ?? [];

        return is_array($organizer) ? $organizer : [];
    }

    /**
     * A pending request pre-loaded with timeout and credentials.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    private function client()
    {
        $caldav = $this->caldav();
        $username = (string) ($caldav['username'] ?? '');
        $password = (string) ($caldav['password'] ?? '');

        $request = $this->http->timeout(15);

        if ($username !== '') {
            $request = $request->withBasicAuth($username, $password);
        }

        return $request->withHeaders([
            'User-Agent' => 'zapol-laravel-booking',
            'Accept'     => '*/*',
        ]);
    }

    private function assertSuccessful(Response $response, string $message): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException($message . ' (HTTP ' . $response->status() . '): ' . Str::limit($response->body(), 500));
    }

    /**
     * calendar-query REPORT body limited to VEVENTs overlapping the window,
     * with recurrences expanded server side.
     */
    private function calendarQueryXml(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $start = $from->utc()->format('Ymd\THis\Z');
        $end = $to->utc()->format('Ymd\THis\Z');

        return implode("\n", [
            '<?xml version="1.0" encoding="utf-8" ?>',
            '<C:calendar-query xmlns:D="' . self::NS_DAV . '" xmlns:C="' . self::NS_CALDAV . '">',
            '  <D:prop>',
            '    <D:getetag/>',
            '    <C:calendar-data>',
            '      <C:expand start="' . $start . '" end="' . $end . '"/>',
            '    </C:calendar-data>',
            '  </D:prop>',
            '  <C:filter>',
            '    <C:comp-filter name="VCALENDAR">',
            '      <C:comp-filter name="VEVENT">',
            '        <C:time-range start="' . $start . '" end="' . $end . '"/>',
            '      </C:comp-filter>',
            '    </C:comp-filter>',
            '  </C:filter>',
            '</C:calendar-query>',
        ]);
    }

    /**
     * Pull every <C:calendar-data> payload out of a WebDAV multistatus body.
     *
     * @return array<int,string>
     */
    private function calendarData(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml);
        } catch (Throwable $e) {
            $document = false;
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$document instanceof SimpleXMLElement) {
            return [];
        }

        $document->registerXPathNamespace('d', self::NS_DAV);
        $document->registerXPathNamespace('c', self::NS_CALDAV);

        $nodes = $document->xpath('//c:calendar-data');
        if ($nodes === false || $nodes === null) {
            return [];
        }

        $payloads = [];
        foreach ($nodes as $node) {
            $text = trim((string) $node);
            if ($text !== '') {
                $payloads[] = $text;
            }
        }

        return $payloads;
    }

    /**
     * Build the VCALENDAR body for a new booking.
     *
     * Times go out as UTC "Z" values: every CalDAV server understands them and
     * we avoid shipping a VTIMEZONE component the server may not like.
     *
     * @param array<string,mixed> $draft
     */
    private function buildIcs(string $uid, array $draft): string
    {
        $start = self::toCarbon($draft['start']);
        $end = self::toCarbon($draft['end']);

        $organizer = $this->organizer();
        $organizerEmail = (string) ($organizer['email'] ?? '');
        $organizerName = (string) ($organizer['name'] ?? 'Organizer');

        $location = isset($draft['location']) ? (string) $draft['location'] : '';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//zapol/laravel-booking//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . CarbonImmutable::now('UTC')->format('Ymd\THis\Z'),
            'SEQUENCE:0',
            'DTSTART:' . $start->utc()->format('Ymd\THis\Z'),
            'DTEND:' . $end->utc()->format('Ymd\THis\Z'),
            'SUMMARY:' . IcsParser::escape((string) ($draft['summary'] ?? 'Booking')),
            'DESCRIPTION:' . IcsParser::escape((string) ($draft['description'] ?? '')),
        ];

        if ($location !== '') {
            $lines[] = 'LOCATION:' . IcsParser::escape($location);
            if ($this->isUrl($location)) {
                $lines[] = 'URL:' . $location;
            }
        }

        if ($organizerEmail !== '') {
            $lines[] = 'ORGANIZER;CN=' . IcsParser::escape($organizerName) . ':mailto:' . $organizerEmail;
        }

        foreach ($this->attendees($draft) as $attendee) {
            $cn = IcsParser::escape($attendee['name'] !== '' ? $attendee['name'] : $attendee['email']);
            $lines[] = 'ATTENDEE;CN=' . $cn . ';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE'
                . ':mailto:' . $attendee['email'];
        }

        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        $folded = array_map(static fn (string $line): string => self::fold($line), $lines);
        $folded[] = '';

        return implode("\r\n", $folded);
    }

    /**
     * @param mixed $value
     */
    private static function toCarbon($value): CarbonImmutable
    {
        return $value instanceof CarbonImmutable ? $value : CarbonImmutable::parse((string) $value);
    }

    /**
     * @param array<string,mixed> $draft
     * @return array<int,array{email:string,name:string}>
     */
    private function attendees(array $draft): array
    {
        $attendees = [];
        $seen = [];

        $primary = $draft['attendee'] ?? null;
        if (is_array($primary) && !empty($primary['email'])) {
            $email = (string) $primary['email'];
            $seen[strtolower($email)] = true;
            $attendees[] = ['email' => $email, 'name' => (string) ($primary['name'] ?? '')];
        }

        foreach ((array) ($draft['additional_attendees'] ?? []) as $extra) {
            $email = trim((string) $extra);
            if ($email === '' || isset($seen[strtolower($email)])) {
                continue;
            }
            $seen[strtolower($email)] = true;
            $attendees[] = ['email' => $email, 'name' => ''];
        }

        return $attendees;
    }

    /**
     * Rewrite DTSTART / DTEND of the top level VEVENT, refresh DTSTAMP and
     * bump SEQUENCE so the server and every subscribed client accept the
     * change as an update rather than a duplicate.
     */
    private function replaceTimes(string $ics, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $dtStart = 'DTSTART:' . $start->utc()->format('Ymd\THis\Z');
        $dtEnd = 'DTEND:' . $end->utc()->format('Ymd\THis\Z');
        $dtStamp = 'DTSTAMP:' . CarbonImmutable::now('UTC')->format('Ymd\THis\Z');

        $lines = explode("\n", IcsParser::unfold($ics));

        $out = [];
        $stack = [];
        $sequenceSeen = false;

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }

            $upper = strtoupper($line);

            if (strpos($upper, 'BEGIN:') === 0) {
                $component = trim(substr($upper, 6));
                $stack[] = $component;
                if ($component === 'VEVENT') {
                    $sequenceSeen = false;
                }
                $out[] = $line;
                continue;
            }

            if (strpos($upper, 'END:') === 0) {
                $component = trim(substr($upper, 4));
                if ($component === 'VEVENT' && !$sequenceSeen) {
                    $out[] = 'SEQUENCE:1';
                    $sequenceSeen = true;
                }
                array_pop($stack);
                $out[] = $line;
                continue;
            }

            // Leave VTIMEZONE (and any other nested component) untouched.
            if (end($stack) !== 'VEVENT') {
                $out[] = $line;
                continue;
            }

            if (preg_match('/^DTSTART[;:]/', $upper)) {
                $out[] = $dtStart;
                $out[] = $dtEnd;
                continue;
            }

            if (preg_match('/^(DTEND|DURATION)[;:]/', $upper)) {
                // Already emitted right after DTSTART.
                continue;
            }

            if (preg_match('/^DTSTAMP[;:]/', $upper)) {
                $out[] = $dtStamp;
                continue;
            }

            if (preg_match('/^LAST-MODIFIED[;:]/', $upper)) {
                continue;
            }

            if (preg_match('/^SEQUENCE[;:]/', $upper)) {
                $value = (int) trim(substr($line, strpos($line, ':') + 1));
                $out[] = 'SEQUENCE:' . ($value + 1);
                $sequenceSeen = true;
                continue;
            }

            $out[] = $line;
        }

        $folded = array_map(static fn (string $line): string => self::fold($line), $out);
        $folded[] = '';

        return implode("\r\n", $folded);
    }

    /**
     * RFC 5545 line folding at 73 octets, never splitting a UTF-8 sequence.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return $line;
        }

        $chunks = [];
        $buffer = '';

        foreach ($characters as $character) {
            if (strlen($buffer) + strlen($character) > 73) {
                $chunks[] = $buffer;
                $buffer = '';
            }
            $buffer .= $character;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return implode("\r\n ", $chunks);
    }

    private function isUrl(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return (bool) preg_match('#^https?://#i', trim($value));
    }
}
