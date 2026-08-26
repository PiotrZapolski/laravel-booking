<?php

namespace Zapol\Booking\Calendar;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;
use Zapol\Booking\Contracts\CalendarProvider;

/**
 * Microsoft 365 / Outlook calendar driver backed by Microsoft Graph.
 *
 * Runs app-only (client credentials), so there is no user to send through an
 * OAuth consent screen: an Entra ID app registration with the application
 * permission Calendars.ReadWrite plus admin consent is all the operator needs.
 * Every call targets one mailbox, `booking.microsoft.user`, which keeps the
 * blast radius small and lets an admin lock the app down to that single
 * mailbox with an application access policy.
 *
 * Graph itself owns the invitation e-mails: creating an event with attendees
 * makes Exchange send the invite, exactly like the Google driver.
 */
class MicrosoftCalendarProvider implements CalendarProvider
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** getSchedule statuses that block a slot. Everything else (free) is ignored. */
    private const BUSY_STATUSES = ['busy', 'oof', 'tentative', 'workingElsewhere'];

    /** Cached for the lifetime of this instance, i.e. one request. */
    private ?string $token = null;

    public function __construct(private array $config, private Factory $http) {}

    /**
     * @return array<int,array{start:CarbonImmutable,end:CarbonImmutable}>
     */
    public function freeBusy(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $schedules = $this->schedules();

        if (empty($schedules)) {
            return [];
        }

        $response = $this->request()->post($this->userBase() . '/calendar/getSchedule', [
            'schedules' => $schedules,
            'startTime' => [
                'dateTime' => $from->utc()->format('Y-m-d\TH:i:s'),
                'timeZone' => 'UTC',
            ],
            'endTime' => [
                'dateTime' => $to->utc()->format('Y-m-d\TH:i:s'),
                'timeZone' => 'UTC',
            ],
            'availabilityViewInterval' => 15,
        ]);

        $this->assertOk($response, 'reading the free/busy schedule');

        $busy = [];

        foreach ((array) $response->json('value', []) as $schedule) {
            foreach ((array) ($schedule['scheduleItems'] ?? []) as $item) {
                $status = (string) ($item['status'] ?? '');

                if (! in_array($status, self::BUSY_STATUSES, true)) {
                    continue;
                }

                $start = $this->parseDateTime($item['start'] ?? null);
                $end   = $this->parseDateTime($item['end'] ?? null);

                if ($start === null || $end === null) {
                    continue;
                }

                $busy[] = ['start' => $start, 'end' => $end];
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
        $timezone = (string) ($draft['timezone'] ?? 'UTC');

        $body = [
            'subject' => (string) ($draft['summary'] ?? ''),
            'body' => [
                'contentType' => 'text',
                'content'     => (string) ($draft['description'] ?? ''),
            ],
            'start'     => $this->graphDateTime($draft['start'], $timezone),
            'end'       => $this->graphDateTime($draft['end'], $timezone),
            'attendees' => $this->attendees($draft),
        ];

        $location = $draft['location'] ?? null;

        if (is_string($location) && $location !== '') {
            $body['location'] = ['displayName' => $location];
        }

        // Only Teams is a native conference here; anything else (Zoom, a custom
        // URL) arrives as a plain location and must not ask Graph for a meeting.
        if (($draft['conference'] ?? null) === 'teams') {
            $body['isOnlineMeeting']       = true;
            $body['onlineMeetingProvider'] = 'teamsForBusiness';
        }

        $response = $this->request()->post($this->calendarBase() . '/events', $body);

        $this->assertOk($response, 'creating the calendar event');

        return [
            'id'         => (string) $response->json('id'),
            'html_link'  => $response->json('webLink'),
            'meet_link'  => $response->json('onlineMeeting.joinUrl'),
        ];
    }

    public function updateEventTime(string $eventId, CarbonImmutable $start, CarbonImmutable $end, string $timezone): void
    {
        $response = $this->request()->patch($this->eventUrl($eventId), [
            'start' => $this->graphDateTime($start, $timezone),
            'end'   => $this->graphDateTime($end, $timezone),
        ]);

        $this->assertOk($response, 'moving the calendar event');
    }

    public function deleteEvent(string $eventId): void
    {
        $response = $this->request()->delete($this->eventUrl($eventId));

        // An event that is already gone is the state we wanted anyway.
        if ($response->status() === 404) {
            return;
        }

        $this->assertOk($response, 'deleting the calendar event');
    }

    /**
     * @return null|array{id:string,summary:?string,start:?string,end:?string,meet_link:?string,attendees:array<int,array{email:string,name:?string}>}
     */
    public function getEvent(string $eventId): ?array
    {
        $response = $this->request()->get($this->eventUrl($eventId), [
            '$select' => 'id,subject,start,end,attendees,onlineMeeting,webLink',
        ]);

        if ($response->status() === 404) {
            return null;
        }

        $this->assertOk($response, 'reading the calendar event');

        $start = $this->parseDateTime($response->json('start'));
        $end   = $this->parseDateTime($response->json('end'));

        $attendees = [];

        foreach ((array) $response->json('attendees', []) as $attendee) {
            $email = $attendee['emailAddress']['address'] ?? null;

            if (! is_string($email) || $email === '') {
                continue;
            }

            $name = $attendee['emailAddress']['name'] ?? null;

            $attendees[] = [
                'email' => $email,
                'name'  => is_string($name) && $name !== '' ? $name : null,
            ];
        }

        return [
            'id'        => (string) $response->json('id'),
            'summary'   => $response->json('subject'),
            'start'     => $start ? $start->toIso8601String() : null,
            'end'       => $end ? $end->toIso8601String() : null,
            'meet_link' => $response->json('onlineMeeting.joinUrl'),
            'attendees' => $attendees,
        ];
    }

    /** @return array<int,string> */
    public function supportedConferences(): array
    {
        return ['teams'];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Access token for the app registration, cached on the instance.
     */
    private function accessToken(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $tenant = $this->requiredConfig('tenant_id', 'BOOKING_MS_TENANT_ID');

        $response = $this->http
            ->timeout(15)
            ->acceptJson()
            ->asForm()
            ->post('https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token', [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->requiredConfig('client_id', 'BOOKING_MS_CLIENT_ID'),
                'client_secret' => $this->requiredConfig('client_secret', 'BOOKING_MS_CLIENT_SECRET'),
                'scope'         => 'https://graph.microsoft.com/.default',
            ]);

        $this->assertOk($response, 'requesting a Microsoft Graph access token');

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Booking package: Microsoft Graph returned no access token.');
        }

        return $this->token = $token;
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http
            ->timeout(15)
            ->withToken($this->accessToken())
            ->acceptJson();
    }

    private function userBase(): string
    {
        return self::GRAPH . '/users/' . rawurlencode($this->requiredConfig('user', 'BOOKING_MS_USER'));
    }

    /**
     * Events live under the default calendar unless the operator pinned one.
     */
    private function calendarBase(): string
    {
        $calendarId = $this->config['calendar_id'] ?? null;

        if (is_string($calendarId) && $calendarId !== '') {
            return $this->userBase() . '/calendars/' . rawurlencode($calendarId);
        }

        return $this->userBase() . '/calendar';
    }

    /**
     * Event ids are unique per mailbox, so reads and writes go through the
     * mailbox-level collection and do not need the calendar segment.
     */
    private function eventUrl(string $eventId): string
    {
        return $this->userBase() . '/events/' . rawurlencode($eventId);
    }

    /** @return array<int,string> */
    private function schedules(): array
    {
        $extra = $this->config['busy_schedules'] ?? [];

        $schedules = array_merge(
            [$this->requiredConfig('user', 'BOOKING_MS_USER')],
            is_array($extra) ? $extra : []
        );

        $schedules = array_filter(array_map(
            fn ($address) => trim((string) $address),
            $schedules
        ), fn (string $address) => $address !== '');

        return array_values(array_unique($schedules));
    }

    /**
     * @param array<int,mixed>|array<string,mixed> $draft
     * @return array<int,array<string,mixed>>
     */
    private function attendees(array $draft): array
    {
        $attendees = [];

        $primary = $draft['attendee'] ?? null;

        if (is_array($primary) && ! empty($primary['email'])) {
            $attendees[] = [
                'emailAddress' => [
                    'address' => (string) $primary['email'],
                    'name'    => (string) ($primary['name'] ?? $primary['email']),
                ],
                'type' => 'required',
            ];
        }

        foreach ((array) ($draft['additional_attendees'] ?? []) as $email) {
            $email = trim((string) $email);

            if ($email === '') {
                continue;
            }

            $attendees[] = [
                'emailAddress' => ['address' => $email, 'name' => $email],
                'type'         => 'required',
            ];
        }

        return $attendees;
    }

    /**
     * @return array{dateTime:string,timeZone:string}
     */
    private function graphDateTime(CarbonImmutable $moment, string $timezone): array
    {
        $timezone = $this->safeTimezone($timezone);

        return [
            'dateTime' => $moment->setTimezone($timezone)->format('Y-m-d\TH:i:s'),
            'timeZone' => $timezone,
        ];
    }

    /**
     * Graph hands back `{dateTime: '2026-01-01T10:00:00.0000000', timeZone: 'UTC'}`
     * and the zone is authoritative: the string carries no offset.
     *
     * @param mixed $value
     */
    private function parseDateTime($value): ?CarbonImmutable
    {
        if (! is_array($value) || empty($value['dateTime'])) {
            return null;
        }

        return CarbonImmutable::parse(
            (string) $value['dateTime'],
            $this->safeTimezone((string) ($value['timeZone'] ?? 'UTC'))
        );
    }

    /**
     * Mailboxes configured in Outlook report Windows zone names ("Central
     * European Standard Time") that PHP cannot load. Falling back to UTC keeps
     * a booking working instead of blowing up on an unknown zone.
     */
    private function safeTimezone(string $timezone): string
    {
        if ($timezone === '') {
            return 'UTC';
        }

        try {
            new DateTimeZone($timezone);
        } catch (Throwable $e) {
            return 'UTC';
        }

        return $timezone;
    }

    private function requiredConfig(string $key, string $env): string
    {
        $value = $this->config[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(
                "Booking package: the Microsoft calendar driver needs booking.microsoft.{$key} ({$env}) to be set."
            );
        }

        return trim($value);
    }

    private function assertOk(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Booking package: Microsoft Graph failed while %s (HTTP %d): %s',
            $context,
            $response->status(),
            $this->errorMessage($response)
        ));
    }

    private function errorMessage(Response $response): string
    {
        // Graph: {"error":{"code":"...","message":"..."}}
        $message = $response->json('error.message');

        if (is_string($message) && $message !== '') {
            return $message;
        }

        // Entra token endpoint: {"error":"invalid_client","error_description":"..."}
        $description = $response->json('error_description');

        if (is_string($description) && $description !== '') {
            return $description;
        }

        $error = $response->json('error');

        if (is_string($error) && $error !== '') {
            return $error;
        }

        $body = trim((string) $response->body());

        return $body === '' ? 'no response body' : mb_substr($body, 0, 500);
    }
}
