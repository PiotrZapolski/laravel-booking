<?php

namespace Zapol\Booking\Services\Google;

use Carbon\CarbonImmutable;
use Google\Client as GoogleClient;
use Google\Service\Calendar as CalendarService;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;
use Illuminate\Support\Str;
use RuntimeException;
use Zapol\Booking\Contracts\CalendarProvider;

/**
 * Google Calendar driver.
 *
 * The calendar it writes to (`booking.google.calendar_id`) and the calendars it
 * treats as busy (`booking.google.busy_calendars`) come from its own config, so
 * callers only ever deal with the CalendarProvider interface.
 */
class GoogleCalendarService implements CalendarProvider
{
    private ?CalendarService $service = null;

    public function __construct(private array $config) {}

    /**
     * Busy intervals across every configured calendar within the range.
     *
     * @return array<int,array{start:CarbonImmutable,end:CarbonImmutable}>
     */
    public function freeBusy(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calendarIds = $this->busyCalendarIds();
        if (empty($calendarIds)) {
            return [];
        }

        $request = new FreeBusyRequest();
        $request->setTimeMin($from->toRfc3339String());
        $request->setTimeMax($to->toRfc3339String());
        $request->setItems(array_map(function (string $id) {
            $item = new FreeBusyRequestItem();
            $item->setId($id);
            return $item;
        }, $calendarIds));

        $response = $this->service()->freebusy->query($request);

        $busy = [];
        foreach ($response->getCalendars() as $cal) {
            foreach ($cal->getBusy() ?? [] as $period) {
                $busy[] = [
                    'start' => CarbonImmutable::parse($period->getStart()),
                    'end'   => CarbonImmutable::parse($period->getEnd()),
                ];
            }
        }

        return $busy;
    }

    /**
     * @param array<string,mixed> $draft
     * @return array{id:string,html_link:?string,meet_link:?string}
     */
    public function createEvent(array $draft): array
    {
        $tz = (string) ($draft['timezone'] ?? 'UTC');
        $start = $draft['start'];
        $end = $draft['end'];
        $attendeeEmail = (string) ($draft['attendee']['email'] ?? '');
        $attendeeName = (string) ($draft['attendee']['name'] ?? '');

        $event = new Event();
        $event->setSummary((string) ($draft['summary'] ?? ''));
        $event->setDescription((string) ($draft['description'] ?? ''));

        $startDt = new EventDateTime();
        $startDt->setDateTime($start->setTimezone($tz)->toRfc3339String());
        $startDt->setTimeZone($tz);
        $event->setStart($startDt);

        $endDt = new EventDateTime();
        $endDt->setDateTime($end->setTimezone($tz)->toRfc3339String());
        $endDt->setTimeZone($tz);
        $event->setEnd($endDt);

        if (!empty($draft['location'])) {
            $event->setLocation((string) $draft['location']);
        }

        $attendees = [];
        if ($attendeeEmail !== '') {
            $primary = new EventAttendee();
            $primary->setEmail($attendeeEmail);
            if ($attendeeName !== '') {
                $primary->setDisplayName($attendeeName);
            }
            $attendees[] = $primary;
        }
        foreach ((array) ($draft['additional_attendees'] ?? []) as $email) {
            $email = (string) $email;
            if ($email === '' || strcasecmp($email, $attendeeEmail) === 0) {
                continue;
            }
            $extra = new EventAttendee();
            $extra->setEmail($email);
            $attendees[] = $extra;
        }
        $event->setAttendees($attendees);

        $params = ['sendUpdates' => 'all'];
        if (($draft['conference'] ?? null) === 'google_meet') {
            $conf = new ConferenceData();
            $request = new CreateConferenceRequest();
            $request->setRequestId((string) Str::uuid());
            $solution = new \Google\Service\Calendar\ConferenceSolutionKey();
            $solution->setType('hangoutsMeet');
            $request->setConferenceSolutionKey($solution);
            $conf->setCreateRequest($request);
            $event->setConferenceData($conf);
            $params['conferenceDataVersion'] = 1;
        }

        $created = $this->service()->events->insert($this->calendarId(), $event, $params);

        return [
            'id'        => $created->getId(),
            'html_link' => $created->getHtmlLink(),
            'meet_link' => $created->getHangoutLink(),
        ];
    }

    public function updateEventTime(string $eventId, CarbonImmutable $start, CarbonImmutable $end, string $timezone): void
    {
        $patch = new Event();

        $startDt = new EventDateTime();
        $startDt->setDateTime($start->setTimezone($timezone)->toRfc3339String());
        $startDt->setTimeZone($timezone);
        $patch->setStart($startDt);

        $endDt = new EventDateTime();
        $endDt->setDateTime($end->setTimezone($timezone)->toRfc3339String());
        $endDt->setTimeZone($timezone);
        $patch->setEnd($endDt);

        $this->service()->events->patch($this->calendarId(), $eventId, $patch, ['sendUpdates' => 'all']);
    }

    public function deleteEvent(string $eventId): void
    {
        $this->service()->events->delete($this->calendarId(), $eventId, ['sendUpdates' => 'all']);
    }

    /**
     * `meet_link` falls back to the event location when it is a URL, so that
     * bookings whose conference lives outside Google (Zoom, a custom link)
     * still expose a join link on reschedule and cancel.
     *
     * @return null|array{id:string,summary:?string,start:?string,end:?string,meet_link:?string,attendees:array<int,array{email:string,name:?string}>}
     */
    public function getEvent(string $eventId): ?array
    {
        try {
            $event = $this->service()->events->get($this->calendarId(), $eventId);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$event) {
            return null;
        }

        $start = $event->getStart();
        $end = $event->getEnd();

        $attendees = [];
        foreach ((array) $event->getAttendees() as $attendee) {
            $email = (string) $attendee->getEmail();
            if ($email === '') {
                continue;
            }
            $attendees[] = [
                'email' => $email,
                'name'  => $attendee->getDisplayName() ?: null,
            ];
        }

        $location = $event->getLocation();
        $meetLink = $event->getHangoutLink();
        if (!$meetLink && is_string($location) && preg_match('#^https?://#i', $location)) {
            $meetLink = $location;
        }

        return [
            'id'        => (string) $event->getId(),
            'summary'   => $event->getSummary(),
            'start'     => $start ? ($start->getDateTime() ?: $start->getDate()) : null,
            'end'       => $end ? ($end->getDateTime() ?: $end->getDate()) : null,
            'meet_link' => $meetLink ?: null,
            'attendees' => $attendees,
        ];
    }

    /**
     * @return array<int,string>
     */
    public function supportedConferences(): array
    {
        return ['google_meet'];
    }

    public function client(): GoogleClient
    {
        $creds = $this->resolveClientCredentials();

        $client = new GoogleClient();
        $client->setClientId($creds['client_id'] ?? '');
        $client->setClientSecret($creds['client_secret'] ?? '');
        $client->setRedirectUri($this->config['redirect_uri'] ?? 'urn:ietf:wg:oauth:2.0:oob');
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setScopes([
            CalendarService::CALENDAR_EVENTS,
            CalendarService::CALENDAR_READONLY,
        ]);
        return $client;
    }

    private function calendarId(): string
    {
        $id = $this->config['calendar_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : 'primary';
    }

    /**
     * @return array<int,string>
     */
    private function busyCalendarIds(): array
    {
        $ids = $this->config['busy_calendars'] ?? null;
        if (!is_array($ids)) {
            $ids = [];
        }

        $ids = array_values(array_filter(array_map('strval', $ids), fn ($id) => $id !== ''));

        return $ids ?: [$this->calendarId()];
    }

    private function service(): CalendarService
    {
        if ($this->service) {
            return $this->service;
        }

        $creds = $this->resolveClientCredentials();
        $refresh = $this->config['refresh_token'] ?? null;
        if (empty($creds['client_id']) || empty($creds['client_secret']) || !$refresh) {
            throw new RuntimeException('Booking package: Google credentials are not configured. Run `php artisan booking:google-auth` and complete the OAuth flow.');
        }

        $client = $this->client();
        $client->refreshToken($refresh);

        return $this->service = new CalendarService($client);
    }

    /**
     * Resolve the OAuth client_id + client_secret. The credentials_file path
     * (a Google Cloud Console "Web application" JSON download) wins if it
     * exists and is readable. Otherwise we fall back to the explicit env
     * vars. This lets operators drop the JSON file into the project without
     * copying the values into .env.
     *
     * @return array{client_id:?string,client_secret:?string}
     */
    private function resolveClientCredentials(): array
    {
        $path = $this->config['credentials_file'] ?? null;
        if ($path && is_file($path) && is_readable($path)) {
            $raw = file_get_contents($path);
            $decoded = $raw ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $section = $decoded['web'] ?? $decoded['installed'] ?? $decoded;
                $clientId = $section['client_id'] ?? null;
                $clientSecret = $section['client_secret'] ?? null;
                if ($clientId && $clientSecret) {
                    return ['client_id' => $clientId, 'client_secret' => $clientSecret];
                }
            }
        }

        return [
            'client_id'     => $this->config['client_id'] ?? null,
            'client_secret' => $this->config['client_secret'] ?? null,
        ];
    }
}
