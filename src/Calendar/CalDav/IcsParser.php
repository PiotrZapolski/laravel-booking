<?php

namespace Zapol\Booking\Calendar\CalDav;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * Minimal, dependency-free iCalendar (RFC 5545) reader.
 *
 * Only the subset a booking calendar needs: unfolding, VEVENT extraction,
 * DTSTART / DTEND / DURATION with TZID, UTC and all-day DATE values, the
 * TRANSP and STATUS flags used to decide whether an event blocks time, and
 * ATTENDEE lines. Every method is static and side-effect free so the parser
 * can be unit tested without a network, a container or a calendar server.
 */
class IcsParser
{
    /**
     * Undo RFC 5545 line folding and normalise line endings to "\n".
     *
     * A folded line is a CRLF (or bare CR / LF, which some servers emit)
     * followed by a single space or horizontal tab.
     */
    public static function unfold(string $ics): string
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $ics);

        return (string) preg_replace("/\n[ \t]/", '', $normalised);
    }

    /**
     * Parse every VEVENT in an iCalendar document.
     *
     * @return array<int,array{
     *   uid:string,
     *   summary:?string,
     *   description:?string,
     *   start:?CarbonImmutable,
     *   end:?CarbonImmutable,
     *   all_day:bool,
     *   transparent:bool,
     *   cancelled:bool,
     *   location:?string,
     *   url:?string,
     *   attendees:array<int,array{email:string,name:?string}>
     * }>
     */
    public static function parseEvents(string $ics): array
    {
        $lines = explode("\n", self::unfold($ics));

        $events = [];
        $stack = [];
        $current = null;

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }

            [$name, $params, $value] = self::splitLine($line);

            if ($name === 'BEGIN') {
                $component = strtoupper(trim($value));
                $stack[] = $component;
                if ($component === 'VEVENT' && $current === null) {
                    $current = self::blankEvent();
                }
                continue;
            }

            if ($name === 'END') {
                $component = strtoupper(trim($value));
                array_pop($stack);
                if ($component === 'VEVENT' && $current !== null) {
                    $events[] = self::finalise($current);
                    $current = null;
                }
                continue;
            }

            // Ignore properties of nested components such as VALARM.
            if ($current === null || end($stack) !== 'VEVENT') {
                continue;
            }

            switch ($name) {
                case 'UID':
                    $current['uid'] = trim($value);
                    break;
                case 'SUMMARY':
                    $current['summary'] = self::unescape($value);
                    break;
                case 'DESCRIPTION':
                    $current['description'] = self::unescape($value);
                    break;
                case 'LOCATION':
                    $current['location'] = self::unescape($value);
                    break;
                case 'URL':
                    $current['url'] = self::unescape(trim($value));
                    break;
                case 'TRANSP':
                    $current['transparent'] = strtoupper(trim($value)) === 'TRANSPARENT';
                    break;
                case 'STATUS':
                    $current['cancelled'] = strtoupper(trim($value)) === 'CANCELLED';
                    break;
                case 'DTSTART':
                    $current['dtstart'] = [$value, $params];
                    break;
                case 'DTEND':
                    $current['dtend'] = [$value, $params];
                    break;
                case 'DURATION':
                    $current['duration'] = self::parseDuration($value);
                    break;
                case 'ATTENDEE':
                    $attendee = self::parseCalAddress($value, $params);
                    if ($attendee !== null) {
                        $current['attendees'][] = $attendee;
                    }
                    break;
                case 'ORGANIZER':
                    $current['organizer'] = self::parseCalAddress($value, $params);
                    break;
            }
        }

        return $events;
    }

    /**
     * Escape a text value for an iCalendar property (RFC 5545 section 3.3.11).
     */
    public static function escape(string $value): string
    {
        return (string) preg_replace(
            ['/\\\\/', '/\r\n|\r|\n/', '/,/', '/;/'],
            ['\\\\\\\\', '\\n', '\\,', '\;'],
            $value,
        );
    }

    /**
     * Reverse escape(): turn \n, \N, \, \; and \\ back into literal characters.
     */
    public static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\(.)/s', static function (array $match): string {
            switch ($match[1]) {
                case 'n':
                case 'N':
                    return "\n";
                case '\\':
                    return '\\';
                case ',':
                    return ',';
                case ';':
                    return ';';
                default:
                    return $match[1];
            }
        }, $value);
    }

    /**
     * Parse a DATE / DATE-TIME property value into a CarbonImmutable.
     *
     * Handles `20260101` (all-day), `20260101T100000Z` (UTC),
     * `20260101T100000` with a `TZID` parameter, and floating local times
     * (treated as UTC, which is the safest reading for busy calculations).
     *
     * @param array<string,string> $params
     */
    public static function parseDateTime(string $value, array $params = []): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $zone = self::zone($params['TZID'] ?? null);

        if (preg_match('/^\d{8}$/', $value)) {
            $parsed = CarbonImmutable::createFromFormat('!Ymd', $value, $zone);

            return $parsed === false ? null : $parsed;
        }

        if (preg_match('/^(\d{8})T(\d{6})(Z?)$/', $value, $match)) {
            $parsed = CarbonImmutable::createFromFormat(
                '!Ymd\THis',
                $match[1] . 'T' . $match[2],
                $match[3] === 'Z' ? 'UTC' : $zone,
            );

            return $parsed === false ? null : $parsed;
        }

        try {
            return CarbonImmutable::parse($value, $zone);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Parse an ISO 8601 duration such as PT1H30M, P1D or -PT15M into seconds.
     */
    public static function parseDuration(string $value): int
    {
        $pattern = '/^(?P<sign>[+-])?P(?:(?P<w>\d+)W)?(?:(?P<d>\d+)D)?'
            . '(?:T(?:(?P<h>\d+)H)?(?:(?P<m>\d+)M)?(?:(?P<s>\d+)S)?)?$/i';

        if (!preg_match($pattern, trim($value), $match)) {
            return 0;
        }

        $seconds = ((int) ($match['w'] ?? 0)) * 604800
            + ((int) ($match['d'] ?? 0)) * 86400
            + ((int) ($match['h'] ?? 0)) * 3600
            + ((int) ($match['m'] ?? 0)) * 60
            + ((int) ($match['s'] ?? 0));

        return ($match['sign'] ?? '') === '-' ? -$seconds : $seconds;
    }

    /**
     * Is this DTSTART / DTEND value an all-day DATE rather than a DATE-TIME?
     *
     * @param array<string,string> $params
     */
    public static function isDateOnly(string $value, array $params = []): bool
    {
        if (strtoupper($params['VALUE'] ?? '') === 'DATE') {
            return true;
        }

        return (bool) preg_match('/^\d{8}$/', trim($value));
    }

    /**
     * @return array{uid:string,summary:?string,description:?string,location:?string,url:?string,transparent:bool,cancelled:bool,dtstart:?array,dtend:?array,duration:?int,attendees:array,organizer:?array}
     */
    private static function blankEvent(): array
    {
        return [
            'uid'         => '',
            'summary'     => null,
            'description' => null,
            'location'    => null,
            'url'         => null,
            'transparent' => false,
            'cancelled'   => false,
            'dtstart'     => null,
            'dtend'       => null,
            'duration'    => null,
            'attendees'   => [],
            'organizer'   => null,
        ];
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private static function finalise(array $event): array
    {
        $allDay = false;
        $start = null;

        if (is_array($event['dtstart'])) {
            [$value, $params] = $event['dtstart'];
            $allDay = self::isDateOnly($value, $params);
            $start = self::parseDateTime($value, $params);
        }

        $end = null;
        if (is_array($event['dtend'])) {
            [$value, $params] = $event['dtend'];
            $end = self::parseDateTime($value, $params);
        } elseif ($event['duration'] !== null && $start !== null) {
            $end = $start->addSeconds((int) $event['duration']);
        } elseif ($start !== null) {
            // RFC 5545: a DATE start with no end lasts one day, a DATE-TIME
            // start with no end has zero duration.
            $end = $allDay ? $start->addDay() : $start;
        }

        return [
            'uid'         => $event['uid'],
            'summary'     => $event['summary'],
            'description' => $event['description'],
            'start'       => $start,
            'end'         => $end,
            'all_day'     => $allDay,
            'transparent' => $event['transparent'],
            'cancelled'   => $event['cancelled'],
            'location'    => $event['location'],
            'url'         => $event['url'],
            'attendees'   => $event['attendees'],
            'organizer'   => $event['organizer'],
        ];
    }

    /**
     * Split a content line into [NAME, params, value].
     *
     * @return array{0:string,1:array<string,string>,2:string}
     */
    private static function splitLine(string $line): array
    {
        $colon = self::findColon($line);
        if ($colon === null) {
            return [strtoupper(trim($line)), [], ''];
        }

        $head = substr($line, 0, $colon);
        $value = substr($line, $colon + 1);

        $segments = self::splitParams($head);
        $name = strtoupper(trim((string) array_shift($segments)));

        $params = [];
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            $equals = strpos($segment, '=');
            if ($equals === false) {
                $params[strtoupper(trim($segment))] = '';
                continue;
            }
            $key = strtoupper(trim(substr($segment, 0, $equals)));
            $params[$key] = trim(trim(substr($segment, $equals + 1)), '"');
        }

        return [$name, $params, $value];
    }

    /**
     * Index of the first ":" that is not inside a quoted parameter value.
     */
    private static function findColon(string $line): ?int
    {
        $length = strlen($line);
        $inQuotes = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '"') {
                $inQuotes = !$inQuotes;
                continue;
            }
            if ($char === ':' && !$inQuotes) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Split "NAME;KEY=VALUE;KEY2="a;b"" on semicolons outside quotes.
     *
     * @return array<int,string>
     */
    private static function splitParams(string $head): array
    {
        $segments = [];
        $buffer = '';
        $inQuotes = false;
        $length = strlen($head);

        for ($i = 0; $i < $length; $i++) {
            $char = $head[$i];
            if ($char === '"') {
                $inQuotes = !$inQuotes;
                $buffer .= $char;
                continue;
            }
            if ($char === ';' && !$inQuotes) {
                $segments[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        $segments[] = $buffer;

        return $segments;
    }

    /**
     * Turn an ATTENDEE / ORGANIZER line into ['email' => ..., 'name' => ...].
     *
     * @param array<string,string> $params
     * @return array{email:string,name:?string}|null
     */
    private static function parseCalAddress(string $value, array $params): ?array
    {
        $address = trim($value);
        if (stripos($address, 'mailto:') === 0) {
            $address = substr($address, 7);
        }
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $name = isset($params['CN']) && $params['CN'] !== '' ? self::unescape($params['CN']) : null;

        return ['email' => $address, 'name' => $name];
    }

    /**
     * Normalise a TZID parameter to a PHP timezone identifier, falling back
     * to UTC for the Windows-style and prefixed names some servers emit.
     */
    private static function zone(?string $tzid): string
    {
        $tzid = trim((string) $tzid, " \t\"");
        if ($tzid === '') {
            return 'UTC';
        }

        if (strpos($tzid, '/') === 0) {
            // Apple and Mozilla prefix TZIDs, e.g. /mozilla.org/2005/Europe/Warsaw
            $parts = array_values(array_filter(explode('/', $tzid), static fn ($p) => $p !== ''));
            $tzid = count($parts) > 2 ? implode('/', array_slice($parts, -2)) : implode('/', $parts);
        }

        try {
            new DateTimeZone($tzid);

            return $tzid;
        } catch (Throwable $e) {
            return 'UTC';
        }
    }
}
