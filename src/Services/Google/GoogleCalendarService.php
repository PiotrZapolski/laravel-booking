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

class GoogleCalendarService
{
    private ?CalendarService $service = null;

    public function __construct(private array $config) {}

    /**
     * Returns busy intervals across all configured calendars within the range.
     *
     * @param array<int,string> $calendarIds
     * @return array<int,array{start:CarbonImmutable,end:CarbonImmutable}>
     */
    public function freeBusy(array $calendarIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
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
     * @param array<int,string> $attendeeEmails Extra invitees beyond the booker.
     * @return array{id:string,html_link:string,meet_link:?string}
     */
    public function createEvent(
        string $calendarId,
        string $summary,
        string $description,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $organizerTz,
        string $attendeeEmail,
        string $attendeeName,
        array $attendeeEmails = [],
        bool $withMeet = true,
    ): array {
        $event = new Event();
        $event->setSummary($summary);
        $event->setDescription($description);

        $startDt = new EventDateTime();
        $startDt->setDateTime($start->setTimezone($organizerTz)->toRfc3339String());
        $startDt->setTimeZone($organizerTz);
        $event->setStart($startDt);

        $endDt = new EventDateTime();
        $endDt->setDateTime($end->setTimezone($organizerTz)->toRfc3339String());
        $endDt->setTimeZone($organizerTz);
        $event->setEnd($endDt);

        $attendees = [];
        $primary = new EventAttendee();
        $primary->setEmail($attendeeEmail);
        if ($attendeeName !== '') {
            $primary->setDisplayName($attendeeName);
        }
        $attendees[] = $primary;
        foreach ($attendeeEmails as $email) {
            if ($email === '' || strcasecmp($email, $attendeeEmail) === 0) {
                continue;
            }
            $extra = new EventAttendee();
            $extra->setEmail($email);
            $attendees[] = $extra;
        }
        $event->setAttendees($attendees);

        $params = ['sendUpdates' => 'all'];
        if ($withMeet) {
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

        $created = $this->service()->events->insert($calendarId, $event, $params);

        return [
            'id'        => $created->getId(),
            'html_link' => $created->getHtmlLink(),
            'meet_link' => $created->getHangoutLink(),
        ];
    }

    public function patchEvent(
        string $calendarId,
        string $eventId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $organizerTz,
    ): void {
        $patch = new Event();

        $startDt = new EventDateTime();
        $startDt->setDateTime($start->setTimezone($organizerTz)->toRfc3339String());
        $startDt->setTimeZone($organizerTz);
        $patch->setStart($startDt);

        $endDt = new EventDateTime();
        $endDt->setDateTime($end->setTimezone($organizerTz)->toRfc3339String());
        $endDt->setTimeZone($organizerTz);
        $patch->setEnd($endDt);

        $this->service()->events->patch($calendarId, $eventId, $patch, ['sendUpdates' => 'all']);
    }

    public function deleteEvent(string $calendarId, string $eventId): void
    {
        $this->service()->events->delete($calendarId, $eventId, ['sendUpdates' => 'all']);
    }

    public function getEvent(string $calendarId, string $eventId): ?Event
    {
        try {
            return $this->service()->events->get($calendarId, $eventId);
        } catch (\Throwable $e) {
            return null;
        }
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
